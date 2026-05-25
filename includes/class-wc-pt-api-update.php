<?php
/**
 * REST API endpoint for batch updating product prices and stock.
 *
 * Route: POST /wp-json/wc-product-tabs/v1/products/batch-update
 * Auth:  Authorization: Bearer <token>
 *
 * @package WC_Product_Tabs
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_PT_API_Update
{
    const NAMESPACE = 'wc-product-tabs/v1';
    const ROUTE     = '/products/batch-update';
    const MAX_UPDATES_PER_REQUEST = 500;

    /**
     * Register REST API hooks.
     *
     * @return void
     */
    public static function register_hooks()
    {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    /**
     * Register REST routes.
     *
     * @return void
     */
    public static function register_routes()
    {
        register_rest_route(
            self::NAMESPACE,
            self::ROUTE,
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ __CLASS__, 'handle_request' ],
                // Keep one token validation source while exposing a route-local callback.
                'permission_callback' => [ __CLASS__, 'check_permission' ],
                'args'                => [
                    'updates' => [
                        'required' => true,
                        'type'     => 'array',
                        'validate_callback' => static function ( $value ) {
                            return is_array( $value );
                        },
                    ],
                ],
            ]
        );
    }

    /**
     * Validate API token (delegated to the shared implementation).
     *
     * @param WP_REST_Request $request Incoming request.
     * @return true|WP_Error
     */
    public static function check_permission( WP_REST_Request $request )
    {
        return WC_PT_API::check_permission( $request );
    }

    /**
     * Handle POST /products/batch-update to update product data.
     *
     * @param WP_REST_Request $request Incoming request.
     * @return WP_REST_Response
     */
    public static function handle_request( WP_REST_Request $request )
    {
        $updates = $request->get_param( 'updates' );
        if ( ! is_array( $updates ) ) {
            return new WP_REST_Response( [ 'error' => 'Invalid updates format' ], 400 );
        }

        if ( count( $updates ) > self::MAX_UPDATES_PER_REQUEST ) {
            return new WP_REST_Response(
                [ 'error' => 'Too many update items in one request' ],
                413
            );
        }

        $results = [];
        foreach ( $updates as $item ) {
            if ( ! is_array( $item ) ) {
                $results[] = [ 'product_id' => null, 'success' => false, 'error' => 'Update item must be an object' ];
                continue;
            }

            $product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
            if ( ! $product_id ) {
                $results[] = [ 'product_id' => null, 'success' => false, 'error' => 'Missing product_id' ];
                continue;
            }
            $result = self::update_product( $product_id, $item );
            $results[] = array_merge( [ 'product_id' => $product_id ], $result );
        }
        return new WP_REST_Response( $results, 200 );
    }

    /**
     * Update a single product's prices and stock.
     *
     * @param int   $product_id Product post ID.
     * @param array $data       Update data.
     * @return array<string, mixed>
     */
    private static function update_product( $product_id, $data )
    {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return [ 'success' => false, 'error' => 'Product not found' ];
        }

        $is_product_dirty   = false;
        $core_update_result = self::apply_core_product_updates( $product, $data, $is_product_dirty );
        if ( is_wp_error( $core_update_result ) ) {
            return [ 'success' => false, 'error' => $core_update_result->get_error_message() ];
        }

        // Backward compatibility for the old nested "regular" shape.
        if ( isset( $data['regular'] ) && is_array( $data['regular'] ) ) {
            $regular_update_result = self::apply_core_product_updates( $product, $data['regular'], $is_product_dirty );
            if ( is_wp_error( $regular_update_result ) ) {
                return [ 'success' => false, 'error' => $regular_update_result->get_error_message() ];
            }
        }

        if ( $is_product_dirty ) {
            $product->save();
        }

        // New flat row shape support: one item can represent one field group.
        if ( isset( $data['field_key'] ) && is_string( $data['field_key'] ) && '' !== $data['field_key'] ) {
            $field_key = sanitize_key( $data['field_key'] );
            if ( ! self::is_allowed_group_key( $field_key ) ) {
                return [ 'success' => false, 'error' => 'Invalid field_key' ];
            }

            self::update_group_fields( $product_id, $field_key, $data );
            return [ 'success' => true ];
        }

        // Update custom fields for rozpyv
        if ( isset( $data['rozpyv'] ) && is_array( $data['rozpyv'] ) ) {
            $roz = $data['rozpyv'];
            self::update_group_fields( $product_id, 'rozpyv', $roz );
        }

        // Update custom fields for zalyszky/flakony variants
        foreach ( [ 'zalyszky', 'flakony' ] as $prefix ) {
            for ( $i = 1; $i <= 5; $i++ ) {
                $key = $prefix . '_variants_' . $i;
                if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
                    self::update_group_fields( $product_id, $key, $data[ $key ] );
                }
            }
        }
        return [ 'success' => true ];
    }

    /**
     * Update custom meta fields for a group key.
     *
     * @param int    $product_id Product post ID.
     * @param string $group_key  Group key (e.g. rozpyv, zalyszky_variants_1).
     * @param array  $payload    Data payload that may contain updatable fields.
     * @return void
     */
    private static function update_group_fields( $product_id, $group_key, $payload )
    {
        $allowed_fields = [ 'key', 'pos_id', 'price', 'old_price', 'status', 'desc' ];

        foreach ( $allowed_fields as $field ) {
            if ( isset( $payload[ $field ] ) ) {
                update_post_meta( $product_id, $group_key . '_' . $field, (string) $payload[ $field ] );
            }
        }
    }

    /**
     * Apply top-level WooCommerce core fields when present.
     *
     * @param WC_Product $product Product object.
     * @param array      $payload          Incoming update payload.
     * @param bool       $is_product_dirty Dirty flag accumulator.
     * @return true|WP_Error
     */
    private static function apply_core_product_updates( WC_Product $product, $payload, &$is_product_dirty )
    {
        if ( isset( $payload['price'] ) ) {
            $product->set_regular_price( wc_format_decimal( (string) $payload['price'] ) );
            $is_product_dirty = true;
        }

        if ( isset( $payload['sale_price'] ) ) {
            $product->set_sale_price( wc_format_decimal( (string) $payload['sale_price'] ) );
            $is_product_dirty = true;
        }

        if ( isset( $payload['stock_status'] ) ) {
            $allowed_statuses = array_keys( wc_get_product_stock_status_options() );
            $stock_status     = (string) $payload['stock_status'];

            if ( ! in_array( $stock_status, $allowed_statuses, true ) ) {
                return new WP_Error( 'invalid_stock_status', __( 'Invalid stock_status.', 'wc-product-tabs' ) );
            }

            $product->set_stock_status( $stock_status );
            $is_product_dirty = true;
        }

        return true;
    }

    /**
     * Allow updates only to known custom group keys.
     *
     * @param string $group_key Group key from API payload.
     * @return bool
     */
    private static function is_allowed_group_key( $group_key )
    {
        if ( 'rozpyv' === $group_key ) {
            return true;
        }

        return (bool) preg_match( '/^(zalyszky|flakony)_variants_[1-5]$/', $group_key );
    }
}
