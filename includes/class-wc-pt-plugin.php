<?php

/**
 * Runtime plugin orchestration and WooCommerce hooks.
 *
 * @package WC_Product_Tabs
 */

if (! defined('ABSPATH')) {
	exit;
}

class WC_PT_Plugin
{

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
	public static function bootstrap()
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct()
	{
		$this->settings = new WC_PT_Settings();
		$this->settings->maybe_migrate_legacy_atomizers();
		$this->data     = new WC_PT_Data($this->settings);

		$this->settings->register_hooks();
		$this->register_runtime_hooks();
	}

	/**
	 * Register frontend and WooCommerce hooks.
	 *
	 * @return void
	 */
	private function register_runtime_hooks()
	{
		add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
		add_filter('woocommerce_get_price_html', [$this, 'maybe_hide_price_html'], 10, 2);
		add_filter('woocommerce_is_purchasable', [$this, 'maybe_block_simple_fallback_purchase'], 10, 2);
		add_filter('woocommerce_product_is_in_stock', [$this, 'maybe_mark_simple_fallback_out_of_stock'], 10, 2);
		add_action('woocommerce_before_add_to_cart_form', [$this, 'maybe_start_hide_cart_form']);
		add_action('woocommerce_after_add_to_cart_form', [$this, 'maybe_end_hide_cart_form']);
		add_action('woocommerce_single_product_summary', [$this, 'render_tabs_container'], 25);
		add_filter('woocommerce_add_cart_item_data', [$this, 'add_cart_item_data'], 10, 3);
		add_action('woocommerce_before_calculate_totals', [$this, 'adjust_cart_item_price']);
		add_filter('woocommerce_get_item_data', [$this, 'display_cart_item_data'], 10, 2);
		add_action('woocommerce_checkout_create_order_line_item', [$this, 'add_order_item_meta'], 10, 4);
		add_filter('woocommerce_order_item_get_formatted_meta_data', [$this, 'format_order_item_meta'], 10, 2);
		add_action('save_post_product', [$this, 'queue_sync_on_save'], 25, 1);
		add_action('acf/save_post', [$this, 'queue_sync_on_acf_save'], 25, 1);
		add_action('woocommerce_product_set_stock', [$this, 'queue_sync_on_stock_change'], 10, 1);
		add_action('shutdown', [$this, 'process_pending_price_bounds_sync']);
		add_filter('woocommerce_product_add_to_cart_text', [$this, 'change_catalog_add_to_cart_text'], 10, 2);
		add_filter('woocommerce_product_add_to_cart_url', [$this, 'change_catalog_add_to_cart_url'], 10, 2);
		add_filter('woocommerce_loop_add_to_cart_args', [$this, 'filter_catalog_add_to_cart_args'], 10, 2);
	}

	/**
	 * Enqueue plugin assets and localize front-end data.
	 *
	 * @return void
	 */
	public function enqueue_scripts()
	{
		$is_single_product = is_product() || is_singular('product');

		if (! $is_single_product && ! is_shop() && ! is_product_category()) {
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
			['jquery'],
			WC_PT_VERSION,
			true
		);

		$payload = [
			'currency'          => get_woocommerce_currency_symbol(),
			'atomizers_url'     => WC_PT_PLUGIN_URL . 'images/',
			'add_to_cart_nonce' => wp_create_nonce('wc_product_tabs_add_to_cart'),
			'notify_url'        => $this->settings->get_notify_url(),
			'tabs_priority'     => $this->settings->get_tabs_priority(),
			'i18n'             => [
				'add_to_cart'        => esc_html__('Додати в кошик', 'wc-product-tabs'),
				'added'              => esc_html__('Додано!', 'wc-product-tabs'),
				'select_option'      => esc_html__('Оберіть варіант', 'wc-product-tabs'),
				'select_atomizer'    => esc_html__('Оберіть атомайзер', 'wc-product-tabs'),
				'out_of_stock'       => esc_html__('Немає в наявності', 'wc-product-tabs'),
				'notify_title'       => esc_html__('Повідомити про надходження', 'wc-product-tabs'),
				'notify_desc'        => esc_html__('Залиште контакт — ми сповістимо вас, коли аромат знову буде доступний у розмірі', 'wc-product-tabs'),
				'notify_desc_global' => esc_html__('Залиште контакт — ми сповістимо вас, коли аромат знову буде доступний.', 'wc-product-tabs'),
				'notify_placeholder' => esc_html__('+380 XX XXX XX XX', 'wc-product-tabs'),
				'notify_submit'      => esc_html__('Сповістити', 'wc-product-tabs'),
				'notify_success'     => esc_html__('Дякуємо! Повідомимо вас.', 'wc-product-tabs'),
				'notify_error'       => esc_html__('Помилка. Спробуйте ще раз.', 'wc-product-tabs'),
				'notify_error_phone' => esc_html__('Введіть номер телефону.', 'wc-product-tabs'),
				'notify_rozpyv_label' => esc_html__('Розпив', 'wc-product-tabs'),
			],
		];

		if ($is_single_product) {
			$product_id = (int) get_queried_object_id();

			if ($product_id <= 0) {
				global $post;
				if ($post instanceof WP_Post && 'product' === $post->post_type) {
					$product_id = (int) $post->ID;
				}
			}

			if ($product_id > 0) {
				$product = wc_get_product($product_id);
				if ($product instanceof WC_Product) {
					$tabs_data = $this->data->get_product_tabs_data($product_id);
					if (! empty($tabs_data)) {
						$payload['product_tabs'] = $tabs_data;
					}

					$payload['product_info'] = [
						'id'               => $product_id,
						'type'             => $product->get_type(),
						'is_in_stock'      => $product->is_in_stock(),
						'title'            => $product->get_name(),
						'has_tabs'         => ! empty($tabs_data),
						'blocked_fallback' => ('simple' === $product->get_type() && $this->should_block_simple_fallback($product)),
					];
				}
			}
		}

		wp_localize_script('wc-product-tabs', 'wcProductTabs', $payload);
	}

	/**
	 * Retrieve managed tab product context or null if unmanaged/invalid.
	 *
	 * @param mixed $product WC_Product object or post ID.
	 * @return array{product_id: int, tabs_data: array<string, mixed>}|null
	 */
	private function get_managed_tab_context($product)
	{
		if (! $product instanceof WC_Product || 'simple' !== $product->get_type()) {
			return null;
		}

		$product_id = (int) $product->get_id();
		if (! $this->data->product_has_managed_category($product_id)) {
			return null;
		}

		$tabs_data = $this->data->get_product_tabs_data($product_id);
		if (empty($tabs_data) || empty($tabs_data['tabs'])) {
			return null;
		}

		return [
			'product_id' => $product_id,
			'tabs_data'  => $tabs_data,
		];
	}

	/**
	 * Display formatted prices on product listing view, while suppressing top default price on single product view.
	 *
	 * @param string     $price Price HTML.
	 * @param WC_Product $product WooCommerce product.
	 * @return string
	 */
	public function maybe_hide_price_html($price, $product)
	{
		$context = $this->get_managed_tab_context($product);
		if (null === $context) {
			if ($product instanceof WC_Product && $this->should_block_simple_fallback($product)) {
				return '<span style="color:red;font-size:10px;">[DEBUG: blocked simple fallback]</span>';
			}
			return $price . '<span style="color:red;font-size:10px;">[DEBUG: context is null (not managed)]</span>';
		}

		$product_id = $context['product_id'];

		global $post, $woocommerce_loop;

		// Check if this is the main product display on a single product page
		$is_main_product = is_product() 
			&& is_singular('product') 
			&& isset($post) 
			&& $product_id === $post->ID;

		$is_in_named_loop = isset($woocommerce_loop['name']) && !empty($woocommerce_loop['name']);
		$is_shortcode     = isset($woocommerce_loop['is_shortcode']) && $woocommerce_loop['is_shortcode'];
		
		// We are in a loop if it has a name, is a shortcode, or we are NOT rendering the main product
		$is_in_loop = $is_in_named_loop || $is_shortcode || !$is_main_product;

		if (! $is_in_loop) {
			// On main single product view: hide top default price HTML because JS handles interactive summary price
			return '<span style="color:red;font-size:10px;">[DEBUG: not in loop (main single product)]</span>';
		}

		// On shop archive / catalog / shortcodes / product listing views: display price range via data service
		$range_html = $this->data->format_product_price_range_html($product_id, $price);
		return $range_html . '<span style="color:red;font-size:10px;">[DEBUG: in loop, range returned]</span>';
	}

	/**
	 * Change catalog add-to-cart button text for tab products based on available options count.
	 *
	 * @param string     $text Button text.
	 * @param WC_Product $product Product object.
	 * @return string
	 */
	public function change_catalog_add_to_cart_text($text, $product)
	{
		$context = $this->get_managed_tab_context($product);
		if (null === $context) {
			if ($product instanceof WC_Product && $this->data->product_has_managed_category((int) $product->get_id())) {
				return __('Повідомити про надходження', 'wc-product-tabs');
			}
			return $text;
		}

		$available_count = $this->data->count_available_options($context['tabs_data']);

		if (0 === $available_count) {
			return __('Повідомити про надходження', 'wc-product-tabs');
		}

		if (1 === $available_count) {
			return __('Додати в кошик', 'wc-product-tabs');
		}

		return __('Вибрати варіант', 'wc-product-tabs');
	}

	/**
	 * Change catalog add-to-cart button URL for tab products with multiple options.
	 *
	 * @param string     $url Button URL.
	 * @param WC_Product $product Product object.
	 * @return string
	 */
	public function change_catalog_add_to_cart_url($url, $product)
	{
		$context = $this->get_managed_tab_context($product);
		if (null === $context) {
			return $url;
		}

		$available_count = $this->data->count_available_options($context['tabs_data']);

		// If more than 1 option or out of stock, direct user to single product page to select option
		if (1 !== $available_count) {
			return $product->get_permalink();
		}

		return $url;
	}

	/**
	 * Filter catalog loop add-to-cart button arguments and classes.
	 * Removes ajax_add_to_cart & add_to_cart_button classes when selection is required on single product page.
	 *
	 * @param array      $args Loop button arguments.
	 * @param WC_Product $product WooCommerce product.
	 * @return array
	 */
	public function filter_catalog_add_to_cart_args($args, $product)
	{
		$context = $this->get_managed_tab_context($product);
		if (null === $context) {
			return $args;
		}

		$available_count = $this->data->count_available_options($context['tabs_data']);

		// If more than 1 option or 0 options, disable AJAX add to cart so button acts purely as link to product page
		if (1 !== $available_count) {
			if (isset($args['class'])) {
				$classes = explode(' ', (string) $args['class']);
				$classes = array_diff($classes, ['add_to_cart_button', 'ajax_add_to_cart', 'product_type_simple']);
				$classes[] = 'product_type_variable';
				$args['class'] = implode(' ', array_unique(array_filter($classes)));
			}

			if (isset($args['attributes']['data-product_id'])) {
				unset($args['attributes']['data-product_id']);
			}
		}

		return $args;
	}

	/**
	 * Start output buffering to suppress default add-to-cart form for custom tabs or out-of-stock products.
	 *
	 * @return void
	 */
	public function maybe_start_hide_cart_form()
	{
		global $product;
		if (! $product instanceof WC_Product) {
			return;
		}

		$product_type = $product->get_type();

		if ('simple' === $product_type) {
			if ($this->should_block_simple_fallback($product) || ! $product->is_in_stock()) {
				ob_start();
				return;
			}

			if ($this->data->get_product_tabs_data($product->get_id())) {
				ob_start();
			}
		} elseif ('variable' === $product_type) {
			if (! $product->is_in_stock()) {
				ob_start();
			}
		}
	}

	/**
	 * Clean output buffer to suppress default add-to-cart form.
	 *
	 * @return void
	 */
	public function maybe_end_hide_cart_form()
	{
		global $product;
		if (! $product instanceof WC_Product) {
			return;
		}

		$product_type = $product->get_type();

		if ('simple' === $product_type) {
			if ($this->should_block_simple_fallback($product) || ! $product->is_in_stock()) {
				if (ob_get_level() > 0) {
					ob_end_clean();
				}
				return;
			}

			if ($this->data->get_product_tabs_data($product->get_id())) {
				if (ob_get_level() > 0) {
					ob_end_clean();
				}
			}
		} elseif ('variable' === $product_type) {
			if (! $product->is_in_stock()) {
				if (ob_get_level() > 0) {
					ob_end_clean();
				}
			}
		}
	}

	/**
	 * Render the custom tabs container or standalone out-of-stock notify form wrapper.
	 *
	 * @return void
	 */
	public function render_tabs_container()
	{
		global $product;
		if (! $product instanceof WC_Product) {
			$product = wc_get_product(get_queried_object_id());
		}

		if (! $product instanceof WC_Product) {
			$product = wc_get_product(get_the_ID());
		}

		if (! $product instanceof WC_Product) {
			return;
		}

		$product_id   = (int) $product->get_id();
		$product_type = $product->get_type();

		// 1. Simple product with tabs
		if ('simple' === $product_type) {
			$tabs_data = $this->data->get_product_tabs_data($product_id);
			if (! empty($tabs_data) && ! empty($tabs_data['tabs'])) {
				echo '<div id="wc-product-tabs" data-product-id="' . esc_attr($product_id) . '"></div>';
				return;
			}

			// 2. Simple product with blocked fallback (managed cat without valid tab options)
			if ($this->should_block_simple_fallback($product)) {
				echo '<div id="wct-standalone-notify" class="wct-standalone-notify" data-product-id="' . esc_attr($product_id) . '" data-tab="simple" data-key=""></div>';
				return;
			}

			// 3. Regular simple product that is out of stock
			if (! $product->is_in_stock()) {
				echo '<div id="wct-standalone-notify" class="wct-standalone-notify" data-product-id="' . esc_attr($product_id) . '" data-tab="simple" data-key=""></div>';
				return;
			}
		}

		// 4. Variable product that is globally out of stock
		if ('variable' === $product_type) {
			if (! $product->is_in_stock()) {
				echo '<div id="wct-standalone-notify" class="wct-standalone-notify" data-product-id="' . esc_attr($product_id) . '" data-tab="variable" data-key=""></div>';
				return;
			}
		}
	}

	/**
	 * Store verified custom selection data in cart item data.
	 *
	 * @param array<string, mixed> $cart_item_data Cart item data.
	 * @param int                  $product_id Product ID.
	 * @param int                  $variation_id Variation ID.
	 * @return array<string, mixed>
	 */
	public function add_cart_item_data($cart_item_data, $product_id, $variation_id)
	{
		unset($variation_id);

		$product = wc_get_product($product_id);
		if (! $product || 'simple' !== $product->get_type()) {
			return $cart_item_data;
		}

		if (empty($_POST['wc_product_tab_data'])) {
			$tabs_data = $this->data->get_product_tabs_data($product_id);
			if (empty($tabs_data) || empty($tabs_data['tabs'])) {
				$regular_pos_id = $this->data->get_regular_product_pos_id($product_id);
				if ('' !== $regular_pos_id) {
					$cart_item_data['wc_product_tab_data'] = [
						'tab'    => 'regular',
						'pos_id' => $regular_pos_id,
					];
				}
			}

			return $cart_item_data;
		}

		$nonce = isset($_POST['wc_product_tabs_nonce']) ? sanitize_text_field(wp_unslash($_POST['wc_product_tabs_nonce'])) : '';
		if (! $nonce || ! wp_verify_nonce($nonce, 'wc_product_tabs_add_to_cart')) {
			return $cart_item_data;
		}

		if (empty($_POST['wc_product_tab_data'])) {
			return $cart_item_data;
		}

		$raw_data  = sanitize_text_field(wp_unslash($_POST['wc_product_tab_data']));
		$submitted = json_decode($raw_data, true);
		if (! is_array($submitted)) {
			return $cart_item_data;
		}

		$verified = $this->data->verify_and_build_tab_data($product_id, $submitted);
		if ($verified) {
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
	public function get_cart_item_from_session($cart_item, $values)
	{
		if (isset($values['wc_product_tab_data'])) {
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
	public function adjust_cart_item_price($cart)
	{
		if (is_admin() && ! defined('DOING_AJAX')) {
			return;
		}

		$items_to_remove = [];
		$price_updated   = false;

		foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
			if (isset($cart_item['data']) && 'simple' !== $cart_item['data']->get_type()) {
				continue;
			}

			$product = wc_get_product($cart_item['product_id']);
			if (! $product instanceof WC_Product || ! $product->is_purchasable() || ! $product->is_in_stock()) {
				$items_to_remove[] = $cart_item_key;
				continue;
			}

			$tab_data = $cart_item['wc_product_tab_data'] ?? null;

			if (! $tab_data) {
				$tabs_data = $this->data->get_product_tabs_data($cart_item['product_id']);
				if ($tabs_data && ! empty($tabs_data['tabs'])) {
					$tab_data = $this->data->get_first_option($tabs_data['tabs']);
					if ($tab_data) {
						WC()->cart->cart_contents[$cart_item_key]['wc_product_tab_data'] = $tab_data;
						$price_updated = true;
					} else {
						$items_to_remove[] = $cart_item_key;
						continue;
					}
				}
			} else {
				$verified_tab_data = $this->data->verify_and_build_tab_data($cart_item['product_id'], $tab_data);

				// Fallback: if specific option is no longer available, attempt to load first available option for this product
				if (! $verified_tab_data) {
					$tabs_data = $this->data->get_product_tabs_data($cart_item['product_id']);
					if ($tabs_data && ! empty($tabs_data['tabs'])) {
						$verified_tab_data = $this->data->get_first_option($tabs_data['tabs']);
					}
				}

				if (! $verified_tab_data) {
					$items_to_remove[] = $cart_item_key;
					continue;
				}

				$old_cart_price = isset($tab_data['price']) ? (float) $tab_data['price'] : 0.0;
				$new_cart_price = isset($verified_tab_data['price']) ? (float) $verified_tab_data['price'] : 0.0;

				if ($old_cart_price > 0 && abs($old_cart_price - $new_cart_price) > 0.01) {
					$price_updated = true;
				}

				$tab_data = $verified_tab_data;
				WC()->cart->cart_contents[$cart_item_key]['wc_product_tab_data'] = $tab_data;
			}

			if ($tab_data && isset($tab_data['price'])) {
				$price = (float) $tab_data['price'];

				if ($price <= 0) {
					$items_to_remove[] = $cart_item_key;
					continue;
				}

				$cart_item['data']->set_price($price);
			}
		}

		if (! empty($items_to_remove)) {
			foreach ($items_to_remove as $cart_item_key) {
				$cart->remove_cart_item($cart_item_key);
			}

			if (! wc_has_notice(__('Деякі товари були видалені з кошика, оскільки їх немає в наявності.', 'wc-product-tabs'), 'error')) {
				wc_add_notice(__('Деякі товари були видалені з кошика, оскільки їх немає в наявності.', 'wc-product-tabs'), 'error');
			}
		}

		if ($price_updated && empty($items_to_remove)) {
			if (! wc_has_notice(__('Ціну товарів у кошику було оновлено відповідно до актуальних цін.', 'wc-product-tabs'), 'notice')) {
				wc_add_notice(__('Ціну товарів у кошику було оновлено відповідно до актуальних цін.', 'wc-product-tabs'), 'notice');
			}
		}
	}

	/**
	 * Get metadata labels for display.
	 *
	 * @return array<string, string>
	 */
	private function get_meta_labels()
	{
		return [
			'tab'            => 'Тип',
			'key'            => 'Варіант',
			'price'          => 'Ціна',
			'size_ml'        => "Об'єм",
			'atomizer_title' => 'Атомайзер',
			'atomizer_price' => 'Ціна атомайзера',
		];
	}

	/**
	 * Format metadata value for display.
	 *
	 * @param string $key Meta key.
	 * @param string $val Meta value.
	 * @param array  $data Optional full data array for context.
	 * @return string Formatted value.
	 */
	private function format_meta_value($key, $val, $data = [])
	{
		$tab_labels = [
			'flakony'  => 'Флакон',
			'zalyszky' => 'Залишок',
			'rozpyv'   => 'Розпив',
		];

		if ($key === 'tab') {
			return $tab_labels[$val] ?? $val;
		} elseif ($key === 'size_ml') {
			return $val . ' ml';
		} elseif ($key === 'price') {
			$base_price = (float) $val;
			if (!empty($data['atomizer_price'])) {
				$base_price -= (float) $data['atomizer_price'];
			}
			return $base_price . ' ' . get_woocommerce_currency_symbol();
		} elseif ($key === 'atomizer_price') {
			return $val . ' ' . get_woocommerce_currency_symbol();
		}

		return $val;
	}

	/**
	 * Determine whether a metadata key should be rendered for display.
	 *
	 * @param string $key Meta key.
	 * @param string $tab_slug Current item tab slug.
	 * @return bool
	 */
	private function should_render_meta_key($key, $tab_slug)
	{
		if ('price' === $key && 'rozpyv' !== $tab_slug) {
			return false;
		}

		return true;
	}

	/**
	 * Display custom selection data in cart and checkout.
	 *
	 * @param array<int, array<string, string>> $item_data Existing rendered item data.
	 * @param array<string, mixed>              $cart_item Cart item.
	 * @return array<int, array<string, string>>
	 */
	public function display_cart_item_data($item_data, $cart_item)
	{
		if (empty($cart_item['wc_product_tab_data'])) {
			return $item_data;
		}

		$data     = $cart_item['wc_product_tab_data'];
		$labels   = $this->get_meta_labels();
		$tab_slug = (string) ($data['tab'] ?? '');

		foreach ($labels as $key => $label) {
			if (isset($data[$key]) && $data[$key] !== '') {
				if (! $this->should_render_meta_key($key, $tab_slug)) {
					continue;
				}

				$item_data[] = [
					'name'  => $label,
					'value' => esc_html($this->format_meta_value($key, $data[$key], $data)),
				];
			}
		}

		return $item_data;
	}

	/**
	 * Add custom data to order line item.
	 *
	 * @param WC_Order_Item_Product $item Order item.
	 * @param string                $cart_item_key Cart item key.
	 * @param array                 $values Cart item values.
	 * @param WC_Order              $order Order.
	 * @return void
	 */
	public function add_order_item_meta($item, $cart_item_key, $values, $order)
	{
		if (empty($values['wc_product_tab_data'])) {
			return;
		}

		$data   = $values['wc_product_tab_data'];
		$fields = [
			'tab',
			'key',
			'pos_id',
			'price',
			'size_ml',
			'atomizer_title',
			'atomizer_price',
		];

		foreach ($fields as $key) {
			if (isset($data[$key]) && $data[$key] !== '') {
				$item->add_meta_data($key, $data[$key], true);
			}
		}
	}

	/**
	 * Format order item metadata for clean human display.
	 *
	 * @param array<int, object> $formatted_meta Formatted meta elements.
	 * @param WC_Order_Item      $item Order item object.
	 * @return array<int, object>
	 */
	public function format_order_item_meta($formatted_meta, $item)
	{
		$labels           = $this->get_meta_labels();
		$labels['pos_id'] = 'POS ID';
		$is_admin         = is_admin();

		// Convert $formatted_meta to associative array for format_meta_value context
		$meta_data_array = [];
		foreach ($formatted_meta as $meta) {
			$meta_data_array[$meta->key] = $meta->value;
		}

		$tab_slug = (string) ($meta_data_array['tab'] ?? '');

		foreach ($formatted_meta as $key => $meta) {
			if (! $this->should_render_meta_key($meta->key, $tab_slug)) {
				unset($formatted_meta[$key]);
				continue;
			}

			if (isset($labels[$meta->key])) {
				if ('pos_id' === $meta->key && ! $is_admin) {
					unset($formatted_meta[$key]);
					continue;
				}

				// Apply human-friendly label
				$meta->display_key   = $labels[$meta->key];
				$meta->display_value = $this->format_meta_value($meta->key, $meta->value, $meta_data_array);
			}
		}

		return $formatted_meta;
	}

	/**
	 * Block default simple purchase fallback for managed-category products without valid tab options.
	 *
	 * @param bool       $purchasable Current purchasable state.
	 * @param WC_Product $product Product object.
	 * @return bool
	 */
	public function maybe_block_simple_fallback_purchase($purchasable, $product)
	{
		if (! $product instanceof WC_Product || 'simple' !== $product->get_type()) {
			return $purchasable;
		}

		if ($this->should_block_simple_fallback($product)) {
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
	public function maybe_mark_simple_fallback_out_of_stock($is_in_stock, $product)
	{
		if (! $product instanceof WC_Product || 'simple' !== $product->get_type()) {
			return $is_in_stock;
		}

		if ($this->should_block_simple_fallback($product)) {
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
	private function should_block_simple_fallback($product)
	{
		$product_id = (int) $product->get_id();

		if (! $this->data->product_has_managed_category($product_id)) {
			return false;
		}

		if ('' !== $this->data->get_regular_product_pos_id($product_id)) {
			return false;
		}

		$tabs_data = $this->data->get_product_tabs_data($product_id);
		return empty($tabs_data) || empty($tabs_data['tabs']);
	}

	/**
	 * Pending product IDs to sync price bounds for at end of request.
	 *
	 * @var array<int, bool>
	 */
	private static $pending_sync_pids = [];

	/**
	 * Queue price bounds sync on product save.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public function queue_sync_on_save($product_id)
	{
		$product_id = (int) $product_id;
		if ($product_id <= 0 || wp_is_post_revision($product_id) || wp_is_post_autosave($product_id)) {
			return;
		}

		self::$pending_sync_pids[$product_id] = true;
	}

	/**
	 * Queue price bounds sync on ACF post save.
	 *
	 * @param int|string $post_id Post ID.
	 * @return void
	 */
	public function queue_sync_on_acf_save($post_id)
	{
		$product_id = (int) $post_id;
		if ($product_id > 0 && 'product' === get_post_type($product_id)) {
			self::$pending_sync_pids[$product_id] = true;
		}
	}

	/**
	 * Queue price bounds sync on WooCommerce stock status change.
	 *
	 * @param WC_Product $product WooCommerce product object.
	 * @return void
	 */
	public function queue_sync_on_stock_change($product)
	{
		if (is_object($product) && method_exists($product, 'get_id')) {
			$product_id = (int) $product->get_id();
			if ($product_id > 0) {
				self::$pending_sync_pids[$product_id] = true;
			}
		}
	}

	/**
	 * Process queued price bounds syncs ONCE per product at end of request.
	 *
	 * @return void
	 */
	public function process_pending_price_bounds_sync()
	{
		if (empty(self::$pending_sync_pids)) {
			return;
		}

		$pids                     = array_keys(self::$pending_sync_pids);
		self::$pending_sync_pids = [];

		foreach ($pids as $pid) {
			$this->data->sync_product_price_bounds((int) $pid, true);
		}
	}
}
