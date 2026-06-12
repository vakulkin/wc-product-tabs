<?php
/**
 * Runtime plugin orchestration and WooCommerce hooks.
 *
 * @package WC_Product_Tabs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_PT_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var WC_PT_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Settings service.
	 *
	 * @var WC_PT_Settings
	 */
	private $settings;

	/**
	 * Data service.
	 *
	 * @var WC_PT_Data
	 */
	private $data;

	/**
	 * Boot plugin singleton.
	 *
	 * @return WC_PT_Plugin
	 */
	public static function bootstrap() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->settings = new WC_PT_Settings();
		$this->settings->maybe_migrate_legacy_atomizers();
		$this->data     = new WC_PT_Data( $this->settings );

		$this->settings->register_hooks();
		$this->register_runtime_hooks();
	}

	/**
	 * Register frontend and WooCommerce hooks.
	 *
	 * @return void
	 */
	private function register_runtime_hooks() {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		add_filter( 'woocommerce_get_price_html', [ $this, 'maybe_hide_price_html' ], 10, 2 );
		add_filter( 'woocommerce_is_purchasable', [ $this, 'maybe_block_simple_fallback_purchase' ], 10, 2 );
		add_filter( 'woocommerce_product_is_in_stock', [ $this, 'maybe_mark_simple_fallback_out_of_stock' ], 10, 2 );
		add_action( 'woocommerce_before_add_to_cart_form', [ $this, 'maybe_start_hide_cart_form' ] );
		add_action( 'woocommerce_after_add_to_cart_form', [ $this, 'maybe_end_hide_cart_form' ] );
		add_action( 'woocommerce_single_product_summary', [ $this, 'render_tabs_container' ], 25 );
		add_filter( 'woocommerce_add_cart_item_data', [ $this, 'add_cart_item_data' ], 10, 3 );
		add_filter( 'woocommerce_get_cart_item_from_session', [ $this, 'get_cart_item_from_session' ], 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', [ $this, 'adjust_cart_item_price' ] );
		add_filter( 'woocommerce_get_item_data', [ $this, 'display_cart_item_data' ], 10, 2 );
	}

	/**
	 * Enqueue plugin assets and localize front-end data.
	 *
	 * @return void
	 */
	public function enqueue_scripts() {
		$is_single_product = is_product() || is_singular( 'product' );

		if ( ! $is_single_product && ! is_shop() && ! is_product_category() ) {
			return;
		}

		wp_enqueue_style(
			'wc-product-tabs',
			WC_PT_PLUGIN_URL . 'assets/css/product-tabs.css',
			[],
			WC_PT_VERSION
		);

		wp_enqueue_script(
			'wc-product-tabs',
			WC_PT_PLUGIN_URL . 'assets/js/product-tabs.js',
			[ 'jquery' ],
			WC_PT_VERSION,
			true
		);

		$payload = [
			'currency'         => get_woocommerce_currency_symbol(),
			'atomizers_url'    => WC_PT_PLUGIN_URL . 'images/',
			'add_to_cart_nonce' => wp_create_nonce( 'wc_product_tabs_add_to_cart' ),
			'tabs_priority'    => $this->settings->get_tabs_priority(),
			'i18n'             => [
				'add_to_cart'     => esc_html__( 'Додати в кошик', 'wc-product-tabs' ),
				'added'           => esc_html__( 'Додано!', 'wc-product-tabs' ),
				'select_option'   => esc_html__( 'Оберіть варіант', 'wc-product-tabs' ),
				'select_atomizer' => esc_html__( 'Оберіть атомайзер', 'wc-product-tabs' ),
				'out_of_stock'    => esc_html__( 'Немає в наявності', 'wc-product-tabs' ),
			],
		];

		if ( $is_single_product ) {
			$product_id = (int) get_queried_object_id();

			if ( $product_id <= 0 ) {
				global $post;
				if ( $post instanceof WP_Post && 'product' === $post->post_type ) {
					$product_id = (int) $post->ID;
				}
			}

			if ( $product_id > 0 ) {
				$tabs_data = $this->data->get_product_tabs_data( $product_id );
				if ( ! empty( $tabs_data ) ) {
					$payload['product_tabs'] = $tabs_data;
				}
			}
		}

		wp_localize_script( 'wc-product-tabs', 'wcProductTabs', $payload );
	}

	/**
	 * Hide default price html for tabs products.
	 *
	 * @param string     $price Price HTML.
	 * @param WC_Product $product WooCommerce product.
	 * @return string
	 */
	public function maybe_hide_price_html( $price, $product ) {
		if ( 'simple' !== $product->get_type() ) {
			return $price;
		}

		if ( $this->should_block_simple_fallback( $product ) ) {
			return '';
		}

		if ( $this->data->get_product_tabs_data( $product->get_id() ) ) {
			return '';
		}

		return $price;
	}

	/**
	 * Start output buffering to suppress default add-to-cart form.
	 *
	 * @return void
	 */
	public function maybe_start_hide_cart_form() {
		global $product;
		if ( ! $product instanceof WC_Product || 'simple' !== $product->get_type() ) {
			return;
		}

		if ( $this->should_block_simple_fallback( $product ) ) {
			ob_start();
			return;
		}

		if ( $this->data->get_product_tabs_data( $product->get_id() ) ) {
			ob_start();
		}
	}

	/**
	 * Clean output buffer to suppress default add-to-cart form.
	 *
	 * @return void
	 */
	public function maybe_end_hide_cart_form() {
		global $product;
		if ( ! $product instanceof WC_Product || 'simple' !== $product->get_type() ) {
			return;
		}

		if ( $this->should_block_simple_fallback( $product ) ) {
			ob_end_clean();
			return;
		}

		if ( $this->data->get_product_tabs_data( $product->get_id() ) ) {
			ob_end_clean();
		}
	}

	/**
	 * Render the custom tabs container.
	 *
	 * @return void
	 */
	public function render_tabs_container() {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			$product = wc_get_product( get_queried_object_id() );
		}

		if ( ! $product instanceof WC_Product ) {
			$product = wc_get_product( get_the_ID() );
		}

		if ( ! $product instanceof WC_Product || 'simple' !== $product->get_type() ) {
			return;
		}

		if ( $this->should_block_simple_fallback( $product ) ) {
			echo '<p class="stock out-of-stock">' . esc_html__( 'Немає в наявності', 'wc-product-tabs' ) . '</p>';
			return;
		}

		if ( ! $this->data->get_product_tabs_data( $product->get_id() ) ) {
			return;
		}

		echo '<div id="wc-product-tabs" data-product-id="' . esc_attr( $product->get_id() ) . '"></div>';
	}

	/**
	 * Store verified custom selection data in cart item data.
	 *
	 * @param array<string, mixed> $cart_item_data Cart item data.
	 * @param int                  $product_id Product ID.
	 * @param int                  $variation_id Variation ID.
	 * @return array<string, mixed>
	 */
	public function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
		unset( $variation_id );

		$product = wc_get_product( $product_id );
		if ( ! $product || 'simple' !== $product->get_type() ) {
			return $cart_item_data;
		}

		if ( empty( $_POST['wc_product_tab_data'] ) ) {
			$tabs_data = $this->data->get_product_tabs_data( $product_id );
			if ( empty( $tabs_data ) || empty( $tabs_data['tabs'] ) ) {
				$regular_pos_id = $this->data->get_regular_product_pos_id( $product_id );
				if ( '' !== $regular_pos_id ) {
					$cart_item_data['wc_product_tab_data'] = [
						'tab'    => 'regular',
						'pos_id' => $regular_pos_id,
					];
				}
			}

			return $cart_item_data;
		}

		$nonce = isset( $_POST['wc_product_tabs_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['wc_product_tabs_nonce'] ) ) : '';
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wc_product_tabs_add_to_cart' ) ) {
			return $cart_item_data;
		}

		if ( empty( $_POST['wc_product_tab_data'] ) ) {
			return $cart_item_data;
		}

		$raw_data  = sanitize_text_field( wp_unslash( $_POST['wc_product_tab_data'] ) );
		$submitted = json_decode( $raw_data, true );
		if ( ! is_array( $submitted ) ) {
			return $cart_item_data;
		}

		$verified = $this->data->verify_and_build_tab_data( $product_id, $submitted );
		if ( $verified ) {
			$cart_item_data['wc_product_tab_data'] = $verified;
		}

		return $cart_item_data;
	}

	/**
	 * Restore custom selection from session.
	 *
	 * @param array<string, mixed> $cart_item Cart item.
	 * @param array<string, mixed> $values Session values.
	 * @return array<string, mixed>
	 */
	public function get_cart_item_from_session( $cart_item, $values ) {
		if ( isset( $values['wc_product_tab_data'] ) ) {
			$cart_item['wc_product_tab_data'] = $values['wc_product_tab_data'];
		}

		return $cart_item;
	}

	/**
	 * Override cart item price based on custom tab selection.
	 *
	 * @param WC_Cart $cart Cart object.
	 * @return void
	 */
	public function adjust_cart_item_price( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		$items_to_remove = [];

		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( isset( $cart_item['data'] ) && 'simple' !== $cart_item['data']->get_type() ) {
				continue;
			}

			$product = wc_get_product( $cart_item['product_id'] );
			if ( ! $product instanceof WC_Product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
				$items_to_remove[] = $cart_item_key;
				continue;
			}

			$tab_data = $cart_item['wc_product_tab_data'] ?? null;

			if ( ! $tab_data ) {
				$tabs_data = $this->data->get_product_tabs_data( $cart_item['product_id'] );
				if ( $tabs_data ) {
					$tab_data = $this->data->get_first_option( $tabs_data['tabs'] );
					if ( $tab_data ) {
						WC()->cart->cart_contents[ $cart_item_key ]['wc_product_tab_data'] = $tab_data;
					} else {
						$items_to_remove[] = $cart_item_key;
						continue;
					}
				}
			} else {
				$verified_tab_data = $this->data->verify_and_build_tab_data( $cart_item['product_id'], $tab_data );
				if ( ! $verified_tab_data ) {
					$items_to_remove[] = $cart_item_key;
					continue;
				}

				$tab_data = $verified_tab_data;
				WC()->cart->cart_contents[ $cart_item_key ]['wc_product_tab_data'] = $tab_data;
			}

			if ( $tab_data && isset( $tab_data['price'] ) ) {
				$price = (float) $tab_data['price'];

				if ( $price <= 0 ) {
					$items_to_remove[] = $cart_item_key;
					continue;
				}

				$cart_item['data']->set_price( $price );
			}
		}

		if ( ! empty( $items_to_remove ) ) {
			foreach ( $items_to_remove as $cart_item_key ) {
				$cart->remove_cart_item( $cart_item_key );
			}

			if ( ! wc_has_notice( __( 'Деякі товари були видалені з кошика через некоректну ціну.', 'wc-product-tabs' ), 'error' ) ) {
				wc_add_notice( __( 'Деякі товари були видалені з кошика через некоректну ціну.', 'wc-product-tabs' ), 'error' );
			}
		}
	}

	/**
	 * Display custom selection data in cart and checkout.
	 *
	 * @param array<int, array<string, string>> $item_data Existing rendered item data.
	 * @param array<string, mixed>              $cart_item Cart item.
	 * @return array<int, array<string, string>>
	 */
	public function display_cart_item_data( $item_data, $cart_item ) {
		if ( empty( $cart_item['wc_product_tab_data'] ) ) {
			return $item_data;
		}

		$data = $cart_item['wc_product_tab_data'];

		$tab_labels = [
			'flakony'  => 'Флакон',
			'zalyszky' => 'Залишок',
			'rozpyv'   => 'Розпив',
			'regular'  => 'Звичайний',
		];

		if ( ! empty( $data['tab'] ) ) {
			$item_data[] = [
				'name'  => 'Тип',
				'value' => $tab_labels[ $data['tab'] ] ?? esc_html( $data['tab'] ),
			];
		}

		if ( ! empty( $data['key'] ) ) {
			$item_data[] = [
				'name'  => 'Ключ',
				'value' => esc_html( $data['key'] ),
			];
		}

		if ( ! empty( $data['pos_id'] ) ) {
			$item_data[] = [
				'name'  => 'POS ID',
				'value' => esc_html( $data['pos_id'] ),
			];
		}

		if ( ! empty( $data['desc'] ) ) {
			$item_data[] = [
				'name'  => 'Опис',
				'value' => esc_html( $data['desc'] ),
			];
		}

		if ( ! empty( $data['size_ml'] ) ) {
			$item_data[] = [
				'name'  => "Об'єм",
				'value' => esc_html( $data['size_ml'] ) . ' мл',
			];
		}

		if ( ! empty( $data['atomizer_title'] ) ) {
			$item_data[] = [
				'name'  => 'Атомайзер',
				'value' => esc_html( $data['atomizer_title'] ),
			];
		}

		return $item_data;
	}

	/**
	 * Block default simple purchase fallback for managed-category products without valid tab options.
	 *
	 * @param bool       $purchasable Current purchasable state.
	 * @param WC_Product $product Product object.
	 * @return bool
	 */
	public function maybe_block_simple_fallback_purchase( $purchasable, $product ) {
		if ( ! $product instanceof WC_Product || 'simple' !== $product->get_type() ) {
			return $purchasable;
		}

		if ( $this->should_block_simple_fallback( $product ) ) {
			return false;
		}

		return $purchasable;
	}

	/**
	 * Mark managed-category products as out of stock when fallback should be blocked.
	 *
	 * @param bool       $is_in_stock Current stock state.
	 * @param WC_Product $product Product object.
	 * @return bool
	 */
	public function maybe_mark_simple_fallback_out_of_stock( $is_in_stock, $product ) {
		if ( ! $product instanceof WC_Product || 'simple' !== $product->get_type() ) {
			return $is_in_stock;
		}

		if ( $this->should_block_simple_fallback( $product ) ) {
			return false;
		}

		return $is_in_stock;
	}

	/**
	 * Determine whether default simple product functionality should be blocked.
	 *
	 * @param WC_Product $product Product object.
	 * @return bool
	 */
	private function should_block_simple_fallback( $product ) {
		$product_id = (int) $product->get_id();

		if ( ! $this->data->product_has_managed_category( $product_id ) ) {
			return false;
		}

		if ( '' !== $this->data->get_regular_product_pos_id( $product_id ) ) {
			return false;
		}

		$tabs_data = $this->data->get_product_tabs_data( $product_id );
		return empty( $tabs_data ) || empty( $tabs_data['tabs'] );
	}
}
