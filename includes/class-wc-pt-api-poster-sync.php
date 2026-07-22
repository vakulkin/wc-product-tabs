<?php
/**
 * Poster POS price sync via two REST routes.
 *
 * GET /wp-json/wc-product-tabs/v1/poster-sync/start
 *   Fetches all Poster prices once, precomputes update batches, stores in WP options.
 *
 * GET /wp-json/wc-product-tabs/v1/poster-sync/status
 *   Processes one batch per call. Called by external cron every minute.
 *
 * Price rules:
 *   - Poster prices are in kopecks; divide by 100 for UAH.
 *   - fiscal_code = "was" price (old price). Zero/negative/absent → old price cleared.
 *   - Tab fields (rozpyv, flakony_variants_N, zalyszky_variants_N):
 *       {field_key}_price     = current price
 *       {field_key}_old_price = fiscal_code price, or '' if none
 *   - WC core price (field_key = 'regular'):
 *       Tab product (product belongs to a tab category in settings):
 *         Has Poster data  → find cheapest tab; if it has old_price: regular=old_price, sale=price.
 *                                                if no old_price:   regular=price, sale=''.
 *         No Poster data   → clear both regular_price and sale_price.
 *         regular_pos_id is always ignored for tab products.
 *       Simple product (not in any tab category):
 *         fiscal_code exists → regular_price = fiscal_code, sale_price = price.
 *         no fiscal_code     → regular_price = price, sale_price = ''.
 *   - Zero and negative prices are skipped.
 *
 * @package WC_Product_Tabs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_PT_API_Poster_Sync {

	const NAMESPACE  = 'wc-product-tabs/v1';
	const BATCH_SIZE = 50;

	const OPT_JOB     = 'wc_pt_poster_sync_job';
	const OPT_BATCHES = 'wc_pt_poster_sync_batches';

	const POSTER_API_URL         = 'https://joinposter.com/api/menu.getProducts';
	const POSTER_STORAGE_API_URL = 'https://joinposter.com/api/storage.getStorageLeftovers';

	const VARIANT_PREFIXES   = [ 'zalyszky', 'flakony' ];
	const VARIANT_SUB_FIELDS = [ 'key', 'pos_id', 'price', 'old_price', 'status' ];
	const VARIANT_COUNT      = 5;

	// -----------------------------------------------------------------------
	// Bootstrap
	// -----------------------------------------------------------------------

	public static function register_hooks() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	public static function register_routes() {
		$args = [
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => '__return_true',
		];

		register_rest_route( self::NAMESPACE, '/poster-sync/start',          array_merge( $args, [ 'callback' => [ __CLASS__, 'handle_start'   ] ] ) );
		register_rest_route( self::NAMESPACE, '/poster-sync/status',         array_merge( $args, [ 'callback' => [ __CLASS__, 'handle_status'  ] ] ) );
		register_rest_route( self::NAMESPACE, '/poster-sync/reindex-prices', array_merge( $args, [ 'callback' => [ __CLASS__, 'handle_reindex' ] ] ) );
	}

	// -----------------------------------------------------------------------
	// Route: start
	// -----------------------------------------------------------------------

	public static function handle_start( WP_REST_Request $request ) {
		$poster_token = trim( ( new WC_PT_Settings() )->get_poster_api_token() );

		if ( '' === $poster_token ) {
			return new WP_Error(
				'poster_token_missing',
				__( 'Poster API token is not configured.', 'wc-product-tabs' ),
				[ 'status' => 503 ]
			);
		}

		$poster_prices = self::fetch_poster_prices( $poster_token );
		if ( is_wp_error( $poster_prices ) ) {
			return $poster_prices;
		}

		$poster_storage = self::fetch_poster_storage( $poster_token );
		if ( is_wp_error( $poster_storage ) ) {
			return $poster_storage;
		}

		list( $product_ids, $meta_by_product, $tab_product_ids ) = self::load_products_meta();

		if ( empty( $product_ids ) ) {
			return new WP_REST_Response(
				[
					'status'           => 'started',
					'batch_total'      => 0,
					'products_matched' => 0,
					'poster_products'  => count( $poster_prices ),
					'message'          => 'No WC products with pos_id found.',
				],
				200
			);
		}

		$update_items_by_product = [];

		foreach ( $product_ids as $product_id ) {
			$rows           = self::build_product_rows( $product_id, $meta_by_product[ $product_id ] ?? [] );
			$is_tab_product = isset( $tab_product_ids[ $product_id ] );
			$items          = self::build_update_items( $rows, $poster_prices, $is_tab_product, $poster_storage );

			if ( ! empty( $items ) ) {
				$update_items_by_product[ $product_id ] = $items;
			}
		}

		$batches = [];
		foreach ( array_chunk( array_keys( $update_items_by_product ), self::BATCH_SIZE ) as $chunk ) {
			$batch = [];
			foreach ( $chunk as $product_id ) {
				foreach ( $update_items_by_product[ $product_id ] as $item ) {
					$batch[] = array_merge( [ 'product_id' => (int) $product_id ], $item );
				}
			}
			$batches[] = $batch;
		}

		update_option( self::OPT_BATCHES, $batches, false );
		update_option(
			self::OPT_JOB,
			[
				'status'        => 'pending',
				'batch_done'    => 0,
				'batch_total'   => count( $batches ),
				'updated'       => 0,
				'errors'        => 0,
				'started_at'    => time(),
				'processing_at' => null,
				'completed_at'  => null,
			],
			false
		);

		return new WP_REST_Response(
			[
				'status'           => 'started',
				'batch_total'      => count( $batches ),
				'products_matched' => count( $update_items_by_product ),
				'poster_products'  => count( $poster_prices ),
			],
			200
		);
	}

	// -----------------------------------------------------------------------
	// Route: status
	// -----------------------------------------------------------------------

	public static function handle_status( WP_REST_Request $request ) {
		$job = get_option( self::OPT_JOB, null );

		if ( ! is_array( $job ) ) {
			return new WP_REST_Response( [ 'status' => 'idle' ], 200 );
		}

		$status      = (string) ( $job['status'] ?? 'pending' );
		$batch_done  = (int) ( $job['batch_done'] ?? 0 );
		$batch_total = (int) ( $job['batch_total'] ?? 0 );

		// Stale lock recovery: reset if stuck in 'processing' for > 5 min.
		if ( 'processing' === $status ) {
			$processing_at = (int) ( $job['processing_at'] ?? $job['started_at'] ?? 0 );
			if ( ( time() - $processing_at ) < 300 ) {
				return new WP_REST_Response( array_merge( $job, [ 'status' => 'busy' ] ), 200 );
			}
			$status = $job['status'] = 'pending';
		}

		if ( 'completed' === $status || $batch_done >= $batch_total ) {
			return new WP_REST_Response( array_merge( $job, [ 'status' => 'completed' ] ), 200 );
		}

		$job['status']        = 'processing';
		$job['processing_at'] = time();
		update_option( self::OPT_JOB, $job, false );

		$batch   = ( get_option( self::OPT_BATCHES, [] ) )[ $batch_done ] ?? [];
		$updated = 0;
		$errors  = 0;
		$modified_pids = [];

		foreach ( $batch as $item ) {
			try {
				if ( self::apply_update_item( $item ) ) {
					$updated++;
					$modified_pids[ (int) $item['product_id'] ] = true;
				} else {
					$errors++;
				}
			} catch ( Exception $e ) {
				$errors++;
			}
		}

		if ( ! empty( $modified_pids ) ) {
			$data_service = new WC_PT_Data( new WC_PT_Settings() );
			foreach ( array_keys( $modified_pids ) as $pid ) {
				$data_service->sync_product_price_bounds( $pid );
			}
		}

		$job['batch_done'] = $batch_done + 1;
		$job['updated']    = (int) ( $job['updated'] ?? 0 ) + $updated;
		$job['errors']     = (int) ( $job['errors'] ?? 0 ) + $errors;
		$job['status']     = $job['batch_done'] >= $batch_total ? 'completed' : 'pending';

		if ( 'completed' === $job['status'] ) {
			$job['completed_at'] = time();
		}

		update_option( self::OPT_JOB, $job, false );

		return new WP_REST_Response( $job, 200 );
	}

	// -----------------------------------------------------------------------
	// Route: reindex-prices
	// -----------------------------------------------------------------------

	public static function handle_reindex( WP_REST_Request $request ) {
		$page     = (int) $request->get_param( 'page' );
		$per_page = (int) $request->get_param( 'per_page' );
		if ( $per_page <= 0 ) {
			$per_page = 50;
		}

		$data_service = new WC_PT_Data( new WC_PT_Settings() );
		$res          = $data_service->sync_all_products_price_bounds( $page, $per_page );

		return new WP_REST_Response(
			array_merge( [ 'status' => $res['has_more'] ? 'in_progress' : 'completed' ], $res ),
			200
		);
	}

	// -----------------------------------------------------------------------
	// WC product meta loading
	// -----------------------------------------------------------------------

	/**
	 * Find all published products with a non-empty pos_id, load their pos_id meta in bulk,
	 * and determine which products belong to a tab category (from settings).
	 *
	 * @return array{0: int[], 1: array<int, array<string, string>>, 2: array<int, int>}
	 */
	private static function load_products_meta() {
		global $wpdb;

		$pos_id_keys = self::pos_id_meta_keys();
		$key_ph      = implode( ', ', array_fill( 0, count( $pos_id_keys ), '%s' ) );

		// 1. Find published products with at least one non-empty pos_id.
		$product_ids = array_map( 'intval', $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				 FROM {$wpdb->posts} p
				 WHERE p.post_type = 'product'
				   AND p.post_status = 'publish'
				   AND EXISTS (
				       SELECT 1 FROM {$wpdb->postmeta} pm
				       WHERE pm.post_id = p.ID
				         AND pm.meta_key IN ($key_ph)
				         AND pm.meta_value <> ''
				         AND CAST(pm.meta_value AS SIGNED) > 0
				   )",
				$pos_id_keys
			)
		) );

		if ( empty( $product_ids ) ) {
			return [ [], [], [] ];
		}

		// 2. Bulk-load pos_id meta (prices come from Poster, not from WP meta).
		$id_ph = implode( ', ', array_fill( 0, count( $product_ids ), '%d' ) );

		$meta_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id, pm.meta_key, pm.meta_value
				 FROM {$wpdb->postmeta} pm
				 WHERE pm.post_id IN ($id_ph)
				   AND pm.meta_key IN ($key_ph)",
				array_merge( $product_ids, $pos_id_keys )
			),
			ARRAY_A
		);

		$meta_by_product = [];
		foreach ( $meta_rows as $row ) {
			$meta_by_product[ (int) $row['post_id'] ][ $row['meta_key'] ] = (string) $row['meta_value'];
		}

		// 3. Determine which of these products are in a tab category.
		$tab_cat_ids    = self::get_tab_category_ids();
		$cat_ph         = implode( ', ', array_fill( 0, count( $tab_cat_ids ), '%d' ) );
		$tab_product_ids = array_flip( array_map( 'intval', $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT tr.object_id
				 FROM {$wpdb->term_relationships} tr
				 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				 WHERE tr.object_id IN ($id_ph)
				   AND tt.term_id IN ($cat_ph)
				   AND tt.taxonomy = 'product_cat'",
				array_merge( $product_ids, $tab_cat_ids )
			)
		) ) );

		return [ $product_ids, $meta_by_product, $tab_product_ids ];
	}

	/**
	 * Build flattened pos_id rows for a single product from its preloaded meta.
	 *
	 * @param int                   $product_id
	 * @param array<string, string> $meta
	 * @return array<int, array<string, mixed>>
	 */
	private static function build_product_rows( $product_id, array $meta ) {
		$rows = [];
		$get  = static function ( $key ) use ( $meta ) {
			return isset( $meta[ $key ] ) ? (string) $meta[ $key ] : '';
		};

		$regular_pos_id = trim( $get( 'regular_pos_id' ) );
		if ( '' !== $regular_pos_id && (int) $regular_pos_id > 0 ) {
			$rows[] = [
				'product_id' => $product_id,
				'field_key'  => 'regular',
				'pos_id'     => $regular_pos_id,
			];
		}

		$rozpyv_pos_id = trim( $get( 'rozpyv_pos_id' ) );
		if ( '' !== $rozpyv_pos_id && (int) $rozpyv_pos_id > 0 ) {
			$rows[] = [
				'product_id' => $product_id,
				'field_key'  => 'rozpyv',
				'pos_id'     => $rozpyv_pos_id,
			];
		}

		foreach ( self::VARIANT_PREFIXES as $prefix ) {
			for ( $i = 1; $i <= self::VARIANT_COUNT; $i++ ) {
				$base   = $prefix . '_variants_' . $i;
				$pos_id = trim( $get( $base . '_pos_id' ) );

				if ( '' === $pos_id || (int) $pos_id <= 0 ) {
					continue;
				}

				$rows[] = [
					'product_id' => $product_id,
					'field_key'  => $base,
					'pos_id'     => $pos_id,
				];
			}
		}

		return $rows;
	}

	// -----------------------------------------------------------------------
	// Price update logic
	// -----------------------------------------------------------------------

	/**
	 * Build update items for one product.
	 *
	 * Tab product ($is_tab_product = true):
	 *   - regular_pos_id is ignored entirely.
	 *   - Poster data found: find cheapest tab item; use its old_price for WC core split.
	 *   - No Poster data:    emit a 'regular' item with null price → clears WC core prices.
	 *
	 * Simple product ($is_tab_product = false):
	 *   - Uses regular_pos_id; fiscal_code drives sale/regular split.
	 *
	 * Storage status:
	 *   - 'in_stock'     if ingredient_left > 0
	 *   - 'out_of_stock' if ingredient_left <= 0
	 *   - null           if pos_id is not present in storage data (no update)
	 *
	 * @param array<int, array<string, mixed>>                              $rows
	 * @param array<string, array{price: float, old_price: float|string}>   $poster_prices
	 * @param bool                                                          $is_tab_product
	 * @param array<string, bool>                                           $poster_storage  ingredient_id => is_in_stock
	 * @return array<int, array{field_key: string, price: float|null, old_price: float|string, status: string|null}>
	 */
	private static function build_update_items( array $rows, array $poster_prices, $is_tab_product, array $poster_storage = [] ) {
		$custom_items = [];
		$regular_data = null;

		foreach ( $rows as $row ) {
			// Always skip regular_pos_id for tab products.
			if ( 'regular' === $row['field_key'] && $is_tab_product ) {
				continue;
			}

			if ( ! isset( $poster_prices[ $row['pos_id'] ] ) ) {
				continue;
			}

			$data   = $poster_prices[ $row['pos_id'] ];
			$status = array_key_exists( $row['pos_id'], $poster_storage )
				? ( $poster_storage[ $row['pos_id'] ] ? 'in_stock' : 'out_of_stock' )
				: null;

			if ( 'regular' === $row['field_key'] ) {
				$regular_data = array_merge( $data, [ 'status' => $status ] );
			} else {
				$custom_items[] = [
					'field_key' => $row['field_key'],
					'price'     => $data['price'],
					'old_price' => $data['old_price'],
					'status'    => $status,
				];
			}
		}

		$items = $custom_items;

		if ( $is_tab_product ) {
			if ( ! empty( $custom_items ) ) {
				// Pick the cheapest tab item and inherit its old_price and status for the WC core update.
				usort( $custom_items, static function ( $a, $b ) { return $a['price'] <=> $b['price']; } );
				$min_item = $custom_items[0];
				$items[]  = [
					'field_key' => 'regular',
					'price'     => $min_item['price'],
					'old_price' => $min_item['old_price'],
					'status'    => $min_item['status'],
				];
			} elseif ( ! empty( $rows ) ) {
				// Tabs active, had pos_ids configured but none matched Poster — clear WC core prices.
				$items[] = [
					'field_key' => 'regular',
					'price'     => null,
					'old_price' => '',
					'status'    => null,
				];
			}
		} elseif ( null !== $regular_data ) {
			// Simple product: fiscal_code drives sale/regular split.
			$items[] = [
				'field_key' => 'regular',
				'price'     => $regular_data['price'],
				'old_price' => $regular_data['old_price'],
				'status'    => $regular_data['status'],
			];
		}

		return $items;
	}

	/**
	 * Write one update item to the database.
	 *
	 * @param array{product_id: int, field_key: string, price: float, old_price: float|string} $item
	 * @return bool
	 */
	private static function apply_update_item( array $item ) {
		$product_id = (int) $item['product_id'];
		$field_key  = (string) $item['field_key'];
		$price_raw  = $item['price'];   // float|null (null = clear)
		$old_price  = $item['old_price'];

		$status = isset( $item['status'] ) ? $item['status'] : null;

		if ( 'regular' === $field_key ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				error_log( "[PosterSync] Product not found: $product_id" );
				return false;
			}

			if ( null === $price_raw ) {
				// Tab product with no Poster data — clear both prices.
				$product->set_regular_price( '' );
				$product->set_sale_price( '' );
			} else {
				$price   = (float) $price_raw;
				$has_old = is_numeric( $old_price ) && (float) $old_price > 0;
				if ( $has_old ) {
					$product->set_regular_price( wc_format_decimal( (float) $old_price ) );
					$product->set_sale_price( wc_format_decimal( $price ) );
				} else {
					$product->set_regular_price( wc_format_decimal( $price ) );
					$product->set_sale_price( '' );
				}
			}

			if ( null !== $status ) {
				$product->set_stock_status( $status );
			}

			$res = (bool) $product->save();
			error_log( sprintf( '[PosterSync] product %d: reg=%s sale=%s stock=%s %s', $product_id, $product->get_regular_price(), $product->get_sale_price(), $status ?? 'unchanged', $res ? 'OK' : 'FAIL' ) );
			return $res;
		}

		$price     = (float) $price_raw;
		$has_old   = is_numeric( $old_price ) && (float) $old_price > 0;

		update_post_meta( $product_id, $field_key . '_price',     (string) $price );
		update_post_meta( $product_id, $field_key . '_old_price', $has_old ? (string) $old_price : '' );

		if ( null !== $status ) {
			update_post_meta( $product_id, $field_key . '_status', $status );
		}

		wc_delete_product_transients( $product_id );

		error_log( sprintf( '[PosterSync] tab %s product %d: price=%s old=%s stock=%s', $field_key, $product_id, $price, $has_old ? $old_price : '', $status ?? 'unchanged' ) );

		return true;
	}

	// -----------------------------------------------------------------------
	// Poster API
	// -----------------------------------------------------------------------

	/**
	 * Fetch all Poster prices indexed by pos_id (product_id or modificator_id).
	 *
	 * @param string $token
	 * @return array<string, array{price: float, old_price: float|string}>|WP_Error
	 */
	private static function fetch_poster_prices( $token ) {
		$response = wp_remote_get(
			add_query_arg( 'token', rawurlencode( $token ), self::POSTER_API_URL ),
			[ 'timeout' => 30 ]
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'poster_api_error', sprintf( __( 'Poster API request failed: %s', 'wc-product-tabs' ), $response->get_error_message() ), [ 'status' => 502 ] );
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$data      = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) $http_code || JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'poster_api_error', sprintf( __( 'Poster API returned unexpected response (HTTP %d).', 'wc-product-tabs' ), $http_code ), [ 'status' => 502 ] );
		}

		$products = isset( $data['response'] ) && is_array( $data['response'] ) ? $data['response'] : [];

		if ( empty( $products ) ) {
			return new WP_Error( 'poster_api_empty', __( 'Poster API returned no products.', 'wc-product-tabs' ), [ 'status' => 502 ] );
		}

		$prices = [];

		foreach ( $products as $product ) {
			$product_id = trim( (string) ( $product['product_id'] ?? '' ) );

			if ( '' !== $product_id ) {
				$price = self::kopecks_to_uah( $product['price']['1'] ?? 0 );
				if ( '' !== $price ) {
					$prices[ $product_id ] = [
						'price'     => $price,
						'old_price' => self::kopecks_to_uah( $product['fiscal_code'] ?? 0 ),
					];
				}
			}

			foreach ( (array) ( $product['modifications'] ?? [] ) as $mod ) {
				$mod_id = trim( (string) ( $mod['modificator_id'] ?? '' ) );
				if ( '' === $mod_id ) {
					continue;
				}
				$mod_price = self::kopecks_to_uah( $mod['price'] ?? 0 );
				if ( '' !== $mod_price ) {
					$prices[ $mod_id ] = [
						'price'     => $mod_price,
						'old_price' => self::kopecks_to_uah( $mod['fiscal_code'] ?? 0 ),
					];
				}
			}
		}

		return $prices;
	}

	/**
	 * Fetch storage leftovers indexed by ingredient_id.
	 *
	 * @param string $token
	 * @return array<string, bool>|WP_Error  ingredient_id => true (in_stock) / false (out_of_stock)
	 */
	private static function fetch_poster_storage( $token ) {
		$response = wp_remote_get(
			add_query_arg( 'token', rawurlencode( $token ), self::POSTER_STORAGE_API_URL ),
			[ 'timeout' => 30 ]
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'poster_storage_error', sprintf( __( 'Poster Storage API request failed: %s', 'wc-product-tabs' ), $response->get_error_message() ), [ 'status' => 502 ] );
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$data      = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) $http_code || JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'poster_storage_error', sprintf( __( 'Poster Storage API returned unexpected response (HTTP %d).', 'wc-product-tabs' ), $http_code ), [ 'status' => 502 ] );
		}

		$items = isset( $data['response'] ) && is_array( $data['response'] ) ? $data['response'] : [];

		$storage = [];

		foreach ( $items as $item ) {
			$ingredient_id = trim( (string) ( $item['ingredient_id'] ?? '' ) );
			if ( '' === $ingredient_id ) {
				continue;
			}
			$storage[ $ingredient_id ] = ( (float) ( $item['ingredient_left'] ?? 0 ) ) > 0;
		}

		return $storage;
	}

	/**
	 * Convert kopecks to UAH. Returns '' for zero/negative/invalid.
	 *
	 * @param mixed $raw
	 * @return float|string
	 */
	private static function kopecks_to_uah( $raw ) {
		$value = (float) $raw / 100;
		return $value > 0 ? $value : '';
	}

	// -----------------------------------------------------------------------
	// Meta key lists
	// -----------------------------------------------------------------------

	/**
	 * Return the 3 tab category IDs from settings.
	 *
	 * @return int[]
	 */
	private static function get_tab_category_ids() {
		$settings = new WC_PT_Settings();
		return [
			(int) $settings->get_category_id( 'flakony' ),
			(int) $settings->get_category_id( 'zalyszky' ),
			(int) $settings->get_category_id( 'rozpyv' ),
		];
	}

	private static function pos_id_meta_keys() {
		static $keys = null;
		if ( null !== $keys ) {
			return $keys;
		}

		$keys = [ 'regular_pos_id', 'rozpyv_pos_id' ];

		foreach ( self::VARIANT_PREFIXES as $prefix ) {
			for ( $i = 1; $i <= self::VARIANT_COUNT; $i++ ) {
				$keys[] = $prefix . '_variants_' . $i . '_pos_id';
			}
		}

		return $keys;
	}
}
