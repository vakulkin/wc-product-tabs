<?php
/**
 * Admin settings and runtime configuration access.
 *
 * @package WC_Product_Tabs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_PT_Settings {

	const OPTION_KEY = 'wc_product_tabs_settings';
	const DEFAULT_CAT_FLAKONY = 17;
	const DEFAULT_CAT_ZALYSZKY = 1023;
	const DEFAULT_CAT_ROZPYV = 19;
	const DEFAULT_ROZPYV_SIZES = [ 2, 3, 5, 10, 15 ];
	const DEFAULT_TABS_PRIORITY = [ 'flakony', 'rozpyv', 'zalyszky' ];

	/**
	 * Register admin-facing hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', [ $this, 'register_admin_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	/**
	 * One-time migration from legacy atomizers.json file to plugin option.
	 *
	 * @return void
	 */
	public function maybe_migrate_legacy_atomizers() {
		$settings = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $settings ) ) {
			$settings = [];
		}

		$file = WC_PT_PLUGIN_DIR . 'atomizers.json';
		if ( ! file_exists( $file ) ) {
			return;
		}

		$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_string( $contents ) || '' === trim( $contents ) ) {
			return;
		}

		$file_hash   = md5( $contents );
		$stored_hash = sanitize_text_field( (string) ( $settings['atomizers_file_hash'] ?? '' ) );
		$has_saved   = ! empty( $settings['atomizers'] ) && is_array( $settings['atomizers'] );

		if ( $has_saved && '' !== $stored_hash && hash_equals( $stored_hash, $file_hash ) ) {
			return;
		}

		$decoded = json_decode( $contents, true );

		$atomizers = $this->normalize_atomizers( is_array( $decoded ) ? $decoded : [] );
		if ( empty( $atomizers ) ) {
			return;
		}

		$settings['atomizers'] = $atomizers;
		$settings['atomizers_file_hash'] = $file_hash;
		update_option( self::OPTION_KEY, $settings, false );
	}

	/**
	 * Register the settings page under Settings.
	 *
	 * @return void
	 */
	public function register_admin_page() {
		add_options_page(
			esc_html__( 'WC Product Tabs', 'wc-product-tabs' ),
			esc_html__( 'WC Product Tabs', 'wc-product-tabs' ),
			'manage_options',
			'wc-product-tabs',
			[ $this, 'render_settings_page' ]
		);
	}

	/**
	 * Register option with sanitization callback.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'wc_product_tabs_settings_group',
			self::OPTION_KEY,
			[ $this, 'sanitize_settings' ]
		);
	}

	/**
	 * Sanitize plugin options.
	 *
	 * @param mixed $input Raw option value.
	 * @return array<string, mixed>
	 */
	public function sanitize_settings( $input ) {
		$defaults = $this->get_default_settings();
		$input    = is_array( $input ) ? $input : [];
		$current  = get_option( self::OPTION_KEY, [] );

		$atomizers_input = $input['atomizers_json'] ?? ( $input['atomizers'] ?? [] );
		$atomizers_raw   = [];

		if ( is_string( $atomizers_input ) ) {
			$decoded = json_decode( (string) $atomizers_input, true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				$atomizers_raw = $decoded;
			} else {
				add_settings_error(
					self::OPTION_KEY,
					'atomizers_json_invalid',
					esc_html__( 'Atomizers JSON is invalid. Previous valid value has been kept.', 'wc-product-tabs' ),
					'error'
				);
				$atomizers_raw = $this->get_atomizers();
			}
		} elseif ( is_array( $atomizers_input ) ) {
			$atomizers_raw = $atomizers_input;
		}

		$settings = [
			'cat_flakony'  => max( 1, (int) ( $input['cat_flakony'] ?? $defaults['cat_flakony'] ) ),
			'cat_zalyszky' => max( 1, (int) ( $input['cat_zalyszky'] ?? $defaults['cat_zalyszky'] ) ),
			'cat_rozpyv'   => max( 1, (int) ( $input['cat_rozpyv'] ?? $defaults['cat_rozpyv'] ) ),
			'rozpyv_sizes' => $this->parse_sizes_csv( $input['rozpyv_sizes'] ?? '' ),
			'tabs_priority' => $this->sanitize_tabs_priority( $input['tabs_priority'] ?? $defaults['tabs_priority'] ),
			'atomizers'    => $this->normalize_atomizers( $atomizers_raw ),
			'api_token'    => sanitize_text_field( $input['api_token'] ?? $defaults['api_token'] ),
		];

		if ( empty( $settings['rozpyv_sizes'] ) ) {
			$settings['rozpyv_sizes'] = $defaults['rozpyv_sizes'];
		}

		$settings['atomizers_file_hash'] = is_array( $current )
			? sanitize_text_field( (string) ( $current['atomizers_file_hash'] ?? '' ) )
			: '';

		return $settings;
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = $this->get_settings();
		$atomizers_json = wp_json_encode( $this->get_atomizers_for_editor( $settings['atomizers'] ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'WC Product Tabs', 'wc-product-tabs' ); ?></h1>
			<p><?php echo esc_html__( 'Category IDs and available розпив sizes used by the plugin logic.', 'wc-product-tabs' ); ?></p>

			<form method="post" action="options.php">
				<?php settings_fields( 'wc_product_tabs_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wcpt-cat-flakony"><?php echo esc_html__( 'Flakony category ID', 'wc-product-tabs' ); ?></label></th>
						<td>
							<input id="wcpt-cat-flakony" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[cat_flakony]" type="number" min="1" value="<?php echo esc_attr( $settings['cat_flakony'] ); ?>" class="small-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcpt-cat-zalyszky"><?php echo esc_html__( 'Zalyszky category ID', 'wc-product-tabs' ); ?></label></th>
						<td>
							<input id="wcpt-cat-zalyszky" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[cat_zalyszky]" type="number" min="1" value="<?php echo esc_attr( $settings['cat_zalyszky'] ); ?>" class="small-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcpt-cat-rozpyv"><?php echo esc_html__( 'Rozpyv category ID', 'wc-product-tabs' ); ?></label></th>
						<td>
							<input id="wcpt-cat-rozpyv" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[cat_rozpyv]" type="number" min="1" value="<?php echo esc_attr( $settings['cat_rozpyv'] ); ?>" class="small-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcpt-rozpyv-sizes"><?php echo esc_html__( 'Rozpyv sizes (ml)', 'wc-product-tabs' ); ?></label></th>
						<td>
							<input id="wcpt-rozpyv-sizes" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[rozpyv_sizes]" type="text" value="<?php echo esc_attr( implode( ', ', $settings['rozpyv_sizes'] ) ); ?>" class="regular-text" />
							<p class="description"><?php echo esc_html__( 'Comma-separated list, for example: 2, 3, 5, 10, 15', 'wc-product-tabs' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Tabs display and default priority', 'wc-product-tabs' ); ?></th>
						<td>
							<?php
							$priority_options = [
								'flakony'  => esc_html__( 'Flakony', 'wc-product-tabs' ),
								'rozpyv'   => esc_html__( 'Rozpyv', 'wc-product-tabs' ),
								'zalyszky' => esc_html__( 'Zalyszky', 'wc-product-tabs' ),
							];
							$priority = $this->sanitize_tabs_priority( $settings['tabs_priority'] ?? [] );
							for ( $i = 0; $i < 3; $i++ ) :
								$current = $priority[ $i ] ?? self::DEFAULT_TABS_PRIORITY[ $i ];
								?>
								<p>
									<label for="wcpt-tabs-priority-<?php echo esc_attr( (string) $i ); ?>"><?php echo esc_html( sprintf( __( 'Position %d', 'wc-product-tabs' ), $i + 1 ) ); ?></label>
									<select id="wcpt-tabs-priority-<?php echo esc_attr( (string) $i ); ?>" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[tabs_priority][]">
										<?php foreach ( $priority_options as $value => $label ) : ?>
											<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
								</p>
							<?php endfor; ?>
							<p class="description"><?php echo esc_html__( 'Used for tab order in UI and default auto-selection.', 'wc-product-tabs' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcpt-api-token"><?php echo esc_html__( 'API Token', 'wc-product-tabs' ); ?></label></th>
						<td>
							<input id="wcpt-api-token" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_token]" type="text" value="<?php echo esc_attr( $settings['api_token'] ?? '' ); ?>" class="regular-text" />
							<p class="description"><?php echo esc_html__( 'Secret token for the /wp-json/wc-product-tabs/v1/products endpoint. Pass as: Authorization: Bearer &lt;token&gt;', 'wc-product-tabs' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcpt-atomizers-json"><?php echo esc_html__( 'Atomizers JSON', 'wc-product-tabs' ); ?></label></th>
						<td>
							<textarea id="wcpt-atomizers-json" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[atomizers_json]" rows="14" class="large-text code"><?php echo esc_textarea( (string) $atomizers_json ); ?></textarea>
							<p class="description"><?php echo esc_html__( 'Use simplified format: id, title, image, instock, sizes. Example: "instock": true, "sizes": { "2": 10, "3": 15 }', 'wc-product-tabs' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Return normalized plugin settings.
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings() {
		$defaults = $this->get_default_settings();
		$settings = get_option( self::OPTION_KEY, [] );

		if ( ! is_array( $settings ) ) {
			return $defaults;
		}

		$settings['cat_flakony']  = max( 1, (int) ( $settings['cat_flakony'] ?? $defaults['cat_flakony'] ) );
		$settings['cat_zalyszky'] = max( 1, (int) ( $settings['cat_zalyszky'] ?? $defaults['cat_zalyszky'] ) );
		$settings['cat_rozpyv']   = max( 1, (int) ( $settings['cat_rozpyv'] ?? $defaults['cat_rozpyv'] ) );
		$settings['rozpyv_sizes'] = $this->parse_sizes_csv( $settings['rozpyv_sizes'] ?? [] );
		$settings['tabs_priority'] = $this->sanitize_tabs_priority( $settings['tabs_priority'] ?? $defaults['tabs_priority'] );
		$settings['atomizers']    = $this->normalize_atomizers( $settings['atomizers'] ?? [] );
		$settings['api_token']    = sanitize_text_field( $settings['api_token'] ?? $defaults['api_token'] );
		$settings['atomizers_file_hash'] = sanitize_text_field( (string) ( $settings['atomizers_file_hash'] ?? $defaults['atomizers_file_hash'] ) );

		if ( empty( $settings['rozpyv_sizes'] ) ) {
			$settings['rozpyv_sizes'] = $defaults['rozpyv_sizes'];
		}

		return $settings;
	}

	/**
	 * Get category ID by tab slug.
	 *
	 * @param string $type Tab slug.
	 * @return int
	 */
	public function get_category_id( $type ) {
		$settings = $this->get_settings();
		$map      = [
			'flakony'  => 'cat_flakony',
			'zalyszky' => 'cat_zalyszky',
			'rozpyv'   => 'cat_rozpyv',
		];

		if ( ! isset( $map[ $type ] ) ) {
			return 0;
		}

		return (int) $settings[ $map[ $type ] ];
	}

	/**
	 * Get allowed rozpyv sizes.
	 *
	 * @return int[]
	 */
	public function get_rozpyv_sizes() {
		$settings = $this->get_settings();
		return array_map( 'intval', (array) $settings['rozpyv_sizes'] );
	}

	/**
	 * Get API token for external access.
	 *
	 * @return string
	 */
	public function get_api_token() {
		$settings = $this->get_settings();
		return (string) ( $settings['api_token'] ?? '' );
	}

	/**
	 * Get atomizers configuration from settings.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_atomizers() {
		$settings = $this->get_settings();
		return (array) $settings['atomizers'];
	}

	/**
	 * Get tabs priority used for UI and default selection.
	 *
	 * @return string[]
	 */
	public function get_tabs_priority() {
		$settings = $this->get_settings();
		return $this->sanitize_tabs_priority( $settings['tabs_priority'] ?? [] );
	}

	/**
	 * Get default settings values.
	 *
	 * @return array<string, mixed>
	 */
	private function get_default_settings() {
		return [
			'cat_flakony'  => self::DEFAULT_CAT_FLAKONY,
			'cat_zalyszky' => self::DEFAULT_CAT_ZALYSZKY,
			'cat_rozpyv'   => self::DEFAULT_CAT_ROZPYV,
			'rozpyv_sizes' => self::DEFAULT_ROZPYV_SIZES,
			'tabs_priority' => self::DEFAULT_TABS_PRIORITY,
			'atomizers'    => [],
			'api_token'    => '',
			'atomizers_file_hash' => '',
		];
	}

	/**
	 * Sanitize tabs priority value to unique known tab slugs.
	 *
	 * @param mixed $raw Raw priority value.
	 * @return string[]
	 */
	private function sanitize_tabs_priority( $raw ) {
		$allowed = [ 'flakony', 'rozpyv', 'zalyszky' ];
		$items   = is_array( $raw ) ? $raw : [ $raw ];

		$priority = [];
		foreach ( $items as $item ) {
			$key = sanitize_key( (string) $item );
			if ( in_array( $key, $allowed, true ) && ! in_array( $key, $priority, true ) ) {
				$priority[] = $key;
			}
		}

		foreach ( self::DEFAULT_TABS_PRIORITY as $fallback ) {
			if ( ! in_array( $fallback, $priority, true ) ) {
				$priority[] = $fallback;
			}
		}

		return array_slice( $priority, 0, 3 );
	}

	/**
	 * Parse and sanitize sizes input into unique ascending integers.
	 *
	 * @param string|array<int|string> $raw Raw input value.
	 * @return int[]
	 */
	private function parse_sizes_csv( $raw ) {
		if ( is_array( $raw ) ) {
			$raw = implode( ',', $raw );
		}

		$parts = array_map( 'trim', explode( ',', (string) $raw ) );
		$parts = array_filter( $parts, 'strlen' );

		$sizes = [];
		foreach ( $parts as $part ) {
			$size = (int) $part;
			if ( $size > 0 ) {
				$sizes[] = $size;
			}
		}

		$sizes = array_values( array_unique( $sizes ) );
		sort( $sizes, SORT_NUMERIC );

		return $sizes;
	}

	/**
	 * Normalize atomizers settings to a trusted runtime format.
	 *
	 * @param array<int, mixed> $atomizers Raw atomizers.
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize_atomizers( $atomizers ) {
		$normalized = [];

		foreach ( (array) $atomizers as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$id    = sanitize_key( $item['id'] ?? '' );
			$title = sanitize_text_field( $item['title'] ?? '' );

			if ( '' === $id ) {
				$id = sanitize_key( sanitize_title( $title ) );
			}
			if ( '' === $id ) {
				continue;
			}

			$image = $this->sanitize_atomizer_image( $item['image'] ?? '' );
			$instock = true;
			if ( array_key_exists( 'instock', $item ) ) {
				$instock = filter_var( $item['instock'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
				$instock = null === $instock ? true : (bool) $instock;
			}

			$sizes_map = [];
			$size_images = [];

			if ( isset( $item['sizes'] ) && is_array( $item['sizes'] ) ) {
				foreach ( $item['sizes'] as $size_raw => $size_config ) {
					$size = (int) $size_raw;
					if ( $size <= 0 ) {
						continue;
					}

					$price_raw = $size_config;
					if ( is_array( $size_config ) ) {
						$price_raw = $size_config['price'] ?? ( $size_config['atomizer_price'] ?? 0 );
						$size_image = $this->sanitize_atomizer_image( $size_config['image'] ?? '' );
						if ( '' !== $size_image ) {
							$size_images[ (string) $size ] = $size_image;
						}
					}

					$sizes_map[ (string) $size ] = (float) $price_raw;
				}
			}

			if ( empty( $sizes_map ) && isset( $item['prices'] ) && is_array( $item['prices'] ) ) {
				foreach ( $item['prices'] as $size_raw => $price_raw ) {
					$size = (int) $size_raw;
					if ( $size <= 0 ) {
						continue;
					}
					$sizes_map[ (string) $size ] = (float) $price_raw;
				}
			}

			if ( '' === $image && ! empty( $size_images ) ) {
				$image = (string) reset( $size_images );
			}

			$sizes = $this->parse_sizes_csv( $item['available_sizes'] ?? array_keys( $sizes_map ) );
			if ( empty( $sizes ) && ! empty( $sizes_map ) ) {
				$sizes = $this->parse_sizes_csv( array_keys( $sizes_map ) );
			}

			foreach ( $sizes as $size ) {
				if ( ! isset( $sizes_map[ (string) $size ] ) ) {
					$sizes_map[ (string) $size ] = 0.0;
				}
			}

			if ( empty( $sizes_map ) && ! empty( $sizes ) ) {
				foreach ( $sizes as $size ) {
					$sizes_map[ (string) $size ] = 0.0;
				}
			}

			ksort( $sizes_map, SORT_NUMERIC );
			$available_sizes = $this->parse_sizes_csv( array_keys( $sizes_map ) );

			$normalized[] = [
				'id'              => $id,
				'title'           => '' !== $title ? $title : $id,
				'image'           => $image,
				'size_images'     => $size_images,
				'instock'         => $instock,
				'sizes'           => $sizes_map,
				'available_sizes' => $available_sizes,
				'prices'          => $sizes_map,
			];
		}

		return $normalized;
	}

	/**
	 * Convert atomizers to simplified editor JSON shape.
	 *
	 * @param array<int, array<string, mixed>> $atomizers Runtime atomizers.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_atomizers_for_editor( $atomizers ) {
		$result = [];

		foreach ( (array) $atomizers as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$sizes_map = [];
			if ( isset( $item['sizes'] ) && is_array( $item['sizes'] ) ) {
				$sizes_map = $item['sizes'];
			} elseif ( isset( $item['prices'] ) && is_array( $item['prices'] ) ) {
				$sizes_map = $item['prices'];
			}

			$size_images = [];
			if ( isset( $item['size_images'] ) && is_array( $item['size_images'] ) ) {
				foreach ( $item['size_images'] as $size_raw => $image_raw ) {
					$size = (int) $size_raw;
					if ( $size <= 0 ) {
						continue;
					}
					$image = $this->sanitize_atomizer_image( $image_raw );
					if ( '' !== $image ) {
						$size_images[ (string) $size ] = $image;
					}
				}
			}

			$normalized_sizes = [];
			foreach ( $sizes_map as $size_raw => $price_raw ) {
				$size = (int) $size_raw;
				if ( $size <= 0 ) {
					continue;
				}

				$size_key = (string) $size;
				$price = (float) $price_raw;
				if ( isset( $size_images[ $size_key ] ) ) {
					$normalized_sizes[ $size_key ] = [
						'price' => $price,
						'image' => $size_images[ $size_key ],
					];
				} else {
					$normalized_sizes[ $size_key ] = $price;
				}
			}

			ksort( $normalized_sizes, SORT_NUMERIC );

			$result[] = [
				'id'      => sanitize_key( $item['id'] ?? '' ),
				'title'   => sanitize_text_field( $item['title'] ?? '' ),
				'image'   => $this->sanitize_atomizer_image( $item['image'] ?? '' ),
				'instock' => array_key_exists( 'instock', $item ) ? (bool) $item['instock'] : true,
				'sizes'   => $normalized_sizes,
			];
		}

		return $result;
	}

	/**
	 * Sanitize image value while preserving absolute URLs.
	 *
	 * @param mixed $value Raw image value.
	 * @return string
	 */
	private function sanitize_atomizer_image( $value ) {
		$image = trim( (string) $value );
		if ( '' === $image ) {
			return '';
		}

		if ( preg_match( '#^(https?:)?//#i', $image ) ) {
			return esc_url_raw( $image );
		}

		if ( 0 === strpos( $image, 'data:image/' ) ) {
			return $image;
		}

		return sanitize_file_name( $image );
	}
}
