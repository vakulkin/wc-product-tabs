<?php
/**
 * Product tabs data building and verification.
 *
 * @package WC_Product_Tabs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_PT_Data {

	/**
	 * Settings service.
	 *
	 * @var WC_PT_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param WC_PT_Settings $settings Settings service.
	 */
	public function __construct( WC_PT_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Build tabs payload for a product based on ACF fields.
	 *
	 * @param int $product_id Product ID.
	 * @return array<string, mixed>|null
	 */
	public function get_product_tabs_data( $product_id ) {
		if ( ! function_exists( 'get_field' ) ) {
			return null;
		}

		// Use raw stock meta here to avoid recursion with Woo filters.
		$stock_status = sanitize_key( (string) get_post_meta( (int) $product_id, '_stock_status', true ) );
		$product_available = in_array( $stock_status, [ 'instock', 'onbackorder' ], true );

		$raw_categories = (array) get_field( 'categories', $product_id );
		if ( empty( $raw_categories ) ) {
			return null;
		}

		$categories = array_map(
			function ( $category ) {
				return is_object( $category ) ? (int) $category->term_id : (int) $category;
			},
			$raw_categories
		);

		$tabs = [];

		if ( in_array( $this->settings->get_category_id( 'flakony' ), $categories, true ) ) {
			$variants = $this->get_variants_from_acf( 'flakony', $product_id, $product_available );
			if ( ! empty( $variants ) && $this->has_available_variants( $variants ) ) {
				$tabs['flakony'] = [
					'label'    => 'Флакони',
					'variants' => $variants,
				];
			}
		}

		if ( in_array( $this->settings->get_category_id( 'zalyszky' ), $categories, true ) ) {
			$variants = $this->get_variants_from_acf( 'zalyszky', $product_id, $product_available );
			if ( ! empty( $variants ) && $this->has_available_variants( $variants ) ) {
				$tabs['zalyszky'] = [
					'label'    => 'Залишки',
					'variants' => $variants,
				];
			}
		}

		if ( in_array( $this->settings->get_category_id( 'rozpyv' ), $categories, true ) ) {
			$rozpyv_status = $this->normalize_variant_status( get_field( 'rozpyv_status', $product_id ) );
			$rozpyv_price_per_ml = $this->to_float( get_field( 'rozpyv_price', $product_id ) );
			$base = [
				'key'       => sanitize_text_field( (string) get_field( 'rozpyv_key', $product_id ) ),
				'pos_id'    => sanitize_text_field( (string) get_field( 'rozpyv_pos_id', $product_id ) ),
				'price'     => sanitize_text_field( (string) get_field( 'rozpyv_price', $product_id ) ),
				'price_per_ml' => $rozpyv_price_per_ml,
				'old_price' => sanitize_text_field( (string) get_field( 'rozpyv_old_price', $product_id ) ),
				'status'    => $rozpyv_status,
				'available' => $product_available && 'instock' === $rozpyv_status && $rozpyv_price_per_ml > 0,
				'desc'      => sanitize_text_field( (string) get_field( 'rozpyv_desc', $product_id ) ),
			];

			$rozpyv_sizes = $this->settings->get_rozpyv_sizes();
			$rozpyv_atoms = $this->build_rozpyv_atomizers_options( $base, $rozpyv_sizes, $this->settings->get_atomizers() );

			$tabs['rozpyv'] = [
				'label'     => 'Розпив',
				'base'      => $base,
				'sizes'     => $rozpyv_sizes,
				'size_options' => $rozpyv_atoms['size_options'],
				'atomizers' => $rozpyv_atoms['atomizers'],
			];

			if ( ! $this->has_available_rozpyv_options( $tabs['rozpyv'] ) ) {
				unset( $tabs['rozpyv'] );
			}
		}

		if ( empty( $tabs ) ) {
			return null;
		}

		$tabs = $this->order_tabs_by_priority( $tabs );

		return [
			'product_id' => (int) $product_id,
			'product_available' => $product_available,
			'tabs'       => $tabs,
		];
	}

	/**
	 * Check whether a product belongs to any managed tabs category.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	public function product_has_managed_category( $product_id ) {
		$managed_category_ids = $this->get_managed_category_ids();
		if ( empty( $managed_category_ids ) ) {
			return false;
		}

		$product_category_ids = wp_get_post_terms( (int) $product_id, 'product_cat', [ 'fields' => 'ids' ] );
		if ( is_wp_error( $product_category_ids ) || empty( $product_category_ids ) ) {
			return false;
		}

		$product_category_ids = array_map( 'intval', (array) $product_category_ids );
		return ! empty( array_intersect( $managed_category_ids, $product_category_ids ) );
	}

	/**
	 * Get fallback POS ID for regular simple flow when tabs are unavailable.
	 *
	 * @param int $product_id Product ID.
	 * @return string
	 */
	public function get_regular_product_pos_id( $product_id ) {
		if ( ! function_exists( 'get_field' ) ) {
			return '';
		}

		return sanitize_text_field( (string) get_field( 'regular_pos_id', (int) $product_id ) );
	}

	/**
	 * Verify submitted tab selection and return trusted cart data.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $submitted Submitted data.
	 * @return array<string, mixed>|null
	 */
	public function verify_and_build_tab_data( $product_id, $submitted ) {
		$tabs_data = $this->get_product_tabs_data( $product_id );
		if ( empty( $tabs_data['tabs'] ) || ! is_array( $submitted ) ) {
			return null;
		}

		$tab = sanitize_key( $submitted['tab'] ?? '' );
		if ( ! isset( $tabs_data['tabs'][ $tab ] ) ) {
			return null;
		}

		$tab_config = $tabs_data['tabs'][ $tab ];

		if ( in_array( $tab, [ 'flakony', 'zalyszky' ], true ) ) {
			$variant_index = (int) ( $submitted['variant_index'] ?? 0 );
			$submitted_key = sanitize_text_field( (string) ( $submitted['key'] ?? '' ) );
			$submitted_pos_id = sanitize_text_field( (string) ( $submitted['pos_id'] ?? '' ) );
			foreach ( $tab_config['variants'] as $variant ) {
				if ( $variant_index > 0 ) {
					if ( (int) $variant['index'] !== $variant_index ) {
						continue;
					}
				} elseif ( '' !== $submitted_key || '' !== $submitted_pos_id ) {
					if ( $submitted_key !== (string) ( $variant['key'] ?? '' ) ) {
						continue;
					}

					if ( '' !== $submitted_pos_id && $submitted_pos_id !== (string) ( $variant['pos_id'] ?? '' ) ) {
						continue;
					}
				} else {
					continue;
				}
				if ( empty( $variant['available'] ) ) {
					return null;
				}

				$variant_price = (float) ( $variant['price_value'] ?? $this->to_float( $variant['price'] ?? 0 ) );
				if ( $variant_price <= 0 ) {
					return null;
				}

				return [
					'tab'    => $tab,
					'variant_index' => (int) $variant['index'],
					'key'    => $variant['key'],
					'pos_id' => $variant['pos_id'],
					'price'  => $variant_price,
					'desc'   => $variant['desc'],
				];
			}

			return null;
		}

		if ( 'rozpyv' !== $tab ) {
			return null;
		}

		$size_ml    = (int) ( $submitted['size_ml'] ?? 0 );
		$atomizer_id = sanitize_key( $submitted['atomizer_id'] ?? '' );
		$size_key   = (string) $size_ml;

		if ( empty( $tab_config['size_options'][ $size_key ]['available'] ) ) {
			return null;
		}

		$atomizer = $this->find_atomizer( $tab_config['atomizers'], $atomizer_id );
		if ( empty( $atomizer ) ) {
			return null;
		}

		$option = $atomizer['options'][ $size_key ] ?? null;
		if ( empty( $option ) || empty( $option['available'] ) ) {
			return null;
		}

		$atomizer_price = (float) ( $option['atomizer_price'] ?? 0 );
		$total_price    = (float) ( $option['total_price'] ?? 0 );
		if ( $total_price <= 0 || $atomizer_price < 0 ) {
			return null;
		}

		return [
			'tab'            => 'rozpyv',
			'key'            => $tab_config['base']['key'],
			'pos_id'         => $tab_config['base']['pos_id'],
			'price'          => $total_price,
			'size_ml'        => $size_ml,
			'atomizer_id'    => $atomizer['id'],
			'atomizer_title' => sanitize_text_field( $atomizer['title'] ),
			'atomizer_price' => $atomizer_price,
			'desc'           => 'Розпив ' . $size_ml . ' мл — ' . sanitize_text_field( $atomizer['title'] ),
		];
	}

	/**
	 * Get the first available selection for auto-population in cart.
	 *
	 * @param array<string, mixed> $tabs Tabs data.
	 * @return array<string, mixed>|null
	 */
	public function get_first_option( $tabs ) {
		$priority = $this->settings->get_tabs_priority();

		foreach ( $priority as $tab_key ) {
			if ( empty( $tabs[ $tab_key ] ) || ! is_array( $tabs[ $tab_key ] ) ) {
				continue;
			}

			$tab = $tabs[ $tab_key ];

			if ( in_array( $tab_key, [ 'flakony', 'zalyszky' ], true ) ) {
				foreach ( (array) ( $tab['variants'] ?? [] ) as $variant ) {
					if ( empty( $variant['available'] ) ) {
						continue;
					}

					$variant_price = (float) ( $variant['price_value'] ?? $this->to_float( $variant['price'] ?? 0 ) );
					if ( $variant_price <= 0 ) {
						continue;
					}

					return [
						'tab'    => $tab_key,
						'variant_index' => (int) $variant['index'],
						'key'    => $variant['key'],
						'pos_id' => $variant['pos_id'],
						'price'  => $variant_price,
						'desc'   => $variant['desc'],
					];
				}

				continue;
			}

			if ( 'rozpyv' !== $tab_key ) {
				continue;
			}

			foreach ( (array) ( $tab['sizes'] ?? [] ) as $size ) {
				$size_ml = (int) $size;
				$size_key = (string) $size_ml;

				if ( empty( $tab['size_options'][ $size_key ]['available'] ) ) {
					continue;
				}

				foreach ( (array) ( $tab['atomizers'] ?? [] ) as $atomizer ) {
					$option = $atomizer['options'][ $size_key ] ?? null;
					if ( empty( $option ) || empty( $option['available'] ) ) {
						continue;
					}

					$atomizer_price = (float) ( $option['atomizer_price'] ?? 0 );
					$total_price    = (float) ( $option['total_price'] ?? 0 );
					$atomizer_title = sanitize_text_field( (string) ( $atomizer['title'] ?? '' ) );

					return [
						'tab'            => 'rozpyv',
						'key'            => $tab['base']['key'] ?? '',
						'pos_id'         => $tab['base']['pos_id'] ?? '',
						'price'          => $total_price,
						'size_ml'        => $size_ml,
						'atomizer_id'    => $atomizer['id'] ?? '',
						'atomizer_title' => $atomizer_title,
						'atomizer_price' => $atomizer_price,
						'desc'           => 'Розпив ' . $size_ml . ' мл' . ( $atomizer_title ? ' — ' . $atomizer_title : '' ),
					];
				}
			}
		}

		return null;
	}

	/**
	 * Order tabs array according to settings priority.
	 *
	 * @param array<string, mixed> $tabs Tabs keyed by slug.
	 * @return array<string, mixed>
	 */
	private function order_tabs_by_priority( $tabs ) {
		$ordered   = [];
		$priority  = $this->settings->get_tabs_priority();

		foreach ( $priority as $tab_key ) {
			if ( isset( $tabs[ $tab_key ] ) ) {
				$ordered[ $tab_key ] = $tabs[ $tab_key ];
			}
		}

		foreach ( $tabs as $tab_key => $tab_value ) {
			if ( ! isset( $ordered[ $tab_key ] ) ) {
				$ordered[ $tab_key ] = $tab_value;
			}
		}

		return $ordered;
	}

	/**
	 * Build variants list from ACF grouped fields.
	 *
	 * @param string $field_prefix ACF field prefix.
	 * @param int    $product_id Product ID.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_variants_from_acf( $field_prefix, $product_id, $product_available = true ) {
		$variants = [];

		for ( $index = 1; $index <= 5; $index++ ) {
			$group = get_field( "{$field_prefix}_variants_{$index}", $product_id );
			if ( ! is_array( $group ) ) {
				continue;
			}

			$variant_key = sanitize_text_field( (string) ( $group['key'] ?? '' ) );

			$price_raw = sanitize_text_field( $group['price'] ?? '' );
			$old_price_raw = sanitize_text_field( $group['old_price'] ?? '' );
			$desc  = sanitize_text_field( $group['desc'] ?? '' );

			$price_value = $this->to_float( $price_raw );
			$old_price_value = $this->to_float( $old_price_raw );

			// Rows without key or price are not valid options and must be hidden completely.
			if ( '' === $variant_key || '' === $price_raw ) {
				continue;
			}

			$status = $this->normalize_variant_status( $group['status'] ?? '' );
			if ( $price_value <= 0 ) {
				$status = 'outofstock';
			}

			$old_price = '';
			if ( $old_price_value > $price_value && $price_value > 0 ) {
				$old_price = (string) $old_price_value;
			}

			$variants[] = [
				'index'     => $index,
				'key'       => $variant_key,
				'pos_id'    => sanitize_text_field( $group['pos_id'] ?? '' ),
				'price'     => $price_value > 0 ? (string) $price_value : '',
				'price_value' => $price_value,
				'old_price' => $old_price,
				'status'    => $status,
				'available' => $product_available && 'instock' === $status && $price_value > 0,
				'desc'      => $desc,
			];
		}

		return $variants;
	}

	/**
	 * Normalize ACF stock status to canonical values used by this plugin.
	 *
	 * @param string $status Raw ACF status value.
	 * @return string
	 */
	private function normalize_variant_status( $status ) {
		if ( 'instock' === sanitize_key( (string) $status ) ) {
			return 'instock';
		}

		return 'outofstock';
	}

	/**
	 * Check if variant is available for selection.
	 *
	 * @param array<string, mixed> $variant Variant data.
	 * @return bool
	 */
	private function is_variant_instock( $variant ) {
		return 'instock' === $this->normalize_variant_status( $variant['status'] ?? '' );
	}

	/**
	 * Convert a raw numeric value to float, returning 0 for invalid input.
	 *
	 * @param mixed $value Raw value.
	 * @return float
	 */
	private function to_float( $value ) {
		$raw = sanitize_text_field( (string) $value );
		$raw = str_replace( ',', '.', $raw );

		if ( '' === $raw || ! is_numeric( $raw ) ) {
			return 0.0;
		}

		return (float) $raw;
	}

	/**
	 * Find atomizer by ID in atomizers config.
	 *
	 * @param array<int, array<string, mixed>> $atomizers Atomizer list.
	 * @param string                            $atomizer_id Atomizer ID.
	 * @return array<string, mixed>|null
	 */
	private function find_atomizer( $atomizers, $atomizer_id ) {
		foreach ( (array) $atomizers as $atomizer ) {
			if ( ! is_array( $atomizer ) ) {
				continue;
			}
			if ( ( $atomizer['id'] ?? '' ) === $atomizer_id ) {
				return $atomizer;
			}
		}

		return null;
	}

	/**
	 * Build resolved rozpyv options per size and per atomizer.
	 *
	 * @param array<string, mixed>              $base Rozpyv base data.
	 * @param int[]                              $sizes Allowed sizes.
	 * @param array<int, array<string, mixed>>   $atomizers Atomizers config.
	 * @return array{size_options: array<string, array<string, mixed>>, atomizers: array<int, array<string, mixed>>}
	 */
	private function build_rozpyv_atomizers_options( $base, $sizes, $atomizers ) {
		$price_per_ml = (float) ( $base['price_per_ml'] ?? 0 );
		$base_available = ! empty( $base['available'] );

		$size_options = [];
		foreach ( (array) $sizes as $size ) {
			$size_ml = (int) $size;
			if ( $size_ml <= 0 ) {
				continue;
			}

			$size_options[ (string) $size_ml ] = [
				'available' => false,
			];
		}

		$resolved_atomizers = [];

		foreach ( (array) $atomizers as $atomizer ) {
			if ( ! is_array( $atomizer ) ) {
				continue;
			}

			$instock = ! isset( $atomizer['instock'] ) || (bool) $atomizer['instock'];
			$available_sizes = array_map( 'intval', (array) ( $atomizer['available_sizes'] ?? [] ) );
			$prices = (array) ( $atomizer['prices'] ?? [] );

			$options = [];

			foreach ( $size_options as $size_key => $unused ) {
				$size_ml = (int) $size_key;
				$base_price = $price_per_ml * $size_ml;

				$atomizer_price = $this->to_float( $prices[ $size_ml ] ?? $prices[ $size_key ] ?? 0 );
				$is_size_allowed = in_array( $size_ml, $available_sizes, true );
				$total_price = $base_price + $atomizer_price;

				$available = $base_available
					&& $instock
					&& $is_size_allowed
					&& $price_per_ml > 0
					&& $base_price > 0
					&& $atomizer_price >= 0
					&& $total_price > 0;

				if ( $available ) {
					$size_options[ $size_key ]['available'] = true;
				}

				$options[ $size_key ] = [
					'available' => $available,
					'atomizer_price' => max( 0, $atomizer_price ),
					'total_price' => $available ? $total_price : 0,
				];
			}

			$atomizer['options'] = $options;
			$resolved_atomizers[] = $atomizer;
		}

		return [
			'size_options' => $size_options,
			'atomizers'    => $resolved_atomizers,
		];
	}

	/**
	 * Check whether variants list has at least one available option.
	 *
	 * @param array<int, array<string, mixed>> $variants Variants list.
	 * @return bool
	 */
	private function has_available_variants( $variants ) {
		foreach ( (array) $variants as $variant ) {
			if ( ! empty( $variant['available'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether rozpyv tab has at least one available size option.
	 *
	 * @param array<string, mixed> $tab Rozpyv tab data.
	 * @return bool
	 */
	private function has_available_rozpyv_options( $tab ) {
		foreach ( (array) ( $tab['size_options'] ?? [] ) as $option ) {
			if ( ! empty( $option['available'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return configured managed category IDs for tabs products.
	 *
	 * @return int[]
	 */
	private function get_managed_category_ids() {
		$ids = [
			(int) $this->settings->get_category_id( 'flakony' ),
			(int) $this->settings->get_category_id( 'zalyszky' ),
			(int) $this->settings->get_category_id( 'rozpyv' ),
		];

		$ids = array_values( array_unique( array_filter( $ids ) ) );
		return array_map( 'intval', $ids );
	}
}
