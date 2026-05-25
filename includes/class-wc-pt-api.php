<?php

/**
 * REST API endpoint for WC Product Tabs.
 *
 * Route: GET /wp-json/wc-product-tabs/v1/products
 * Auth:  Authorization: Bearer <token>
 *
 * @package WC_Product_Tabs
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_PT_API
{
    const NAMESPACE = 'wc-product-tabs/v1';
    const ROUTE     = '/products';
    const VARIANT_PREFIXES = [ 'zalyszky', 'flakony' ];
    const VARIANT_SUB_FIELDS = [ 'key', 'pos_id', 'price', 'old_price', 'status' ];
    const VARIANT_COUNT = 5;

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
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ __CLASS__, 'handle_request' ],
                'permission_callback' => [ __CLASS__, 'check_permission' ],
            ]
        );
    }

    /**
     * Validate the Bearer token from the Authorization header.
     *
     * @param WP_REST_Request $request Incoming request.
     * @return true|WP_Error
     */
    public static function check_permission( WP_REST_Request $request )
    {
        $settings    = new WC_PT_Settings();
        $stored_token = trim( (string) $settings->get_api_token() );

        if ( '' === $stored_token ) {
            return new WP_Error(
                'rest_forbidden',
                __( 'API token is not configured.', 'wc-product-tabs' ),
                [ 'status' => 503 ]
            );
        }

        $auth_header = $request->get_header( 'authorization' );

        if ( empty( $auth_header ) ) {
            return new WP_Error(
                'rest_unauthorized',
                __( 'Authorization header missing.', 'wc-product-tabs' ),
                [ 'status' => 401 ]
            );
        }

        if ( ! preg_match( '/^Bearer\s+(.+)$/i', $auth_header, $matches ) ) {
            return new WP_Error(
                'rest_unauthorized',
                __( 'Invalid Authorization header format. Expected: Bearer <token>', 'wc-product-tabs' ),
                [ 'status' => 401 ]
            );
        }

        $provided_token = trim( (string) $matches[1] );

        if ( ! hash_equals( $stored_token, $provided_token ) ) {
            return new WP_Error(
                'rest_forbidden',
                __( 'Invalid token.', 'wc-product-tabs' ),
                [ 'status' => 403 ]
            );
        }

        return true;
    }

    /**
     * Handle GET /products and return product data.
     *
     * @param WP_REST_Request $request Incoming request.
     * @return WP_REST_Response
     */
    public static function handle_request( WP_REST_Request $request )
    {
        list( $product_ids, $meta_by_product ) = self::get_products_meta_by_custom_sql();
        $data = [];

        foreach ( $product_ids as $product_id ) {
            $product_meta = isset( $meta_by_product[ $product_id ] ) ? $meta_by_product[ $product_id ] : [];
            $rows = self::build_product_rows_from_meta( $product_id, $product_meta );

            if ( empty( $rows ) ) {
                continue;
            }

            $data[ (string) $product_id ] = $rows;
        }

        return new WP_REST_Response( $data, 200 );
    }

    /**
     * Return all meta keys required to compose API rows.
     *
     * @return string[]
     */
    private static function get_required_meta_keys()
    {
        static $cached_keys = null;

        if ( null !== $cached_keys ) {
            return $cached_keys;
        }

        $keys = [
            'regular_pos_id',
            '_price',
            '_regular_price',
            '_stock_status',
            'rozpyv_pos_id',
            'rozpyv_price',
            'rozpyv_old_price',
            'rozpyv_status',
        ];

        foreach ( self::VARIANT_PREFIXES as $prefix ) {
            for ( $i = 1; $i <= self::VARIANT_COUNT; $i++ ) {
                $field_name = $prefix . '_variants_' . $i;

                foreach ( self::VARIANT_SUB_FIELDS as $sub_field ) {
                    $keys[] = $field_name . '_' . $sub_field;
                }
            }
        }

        $cached_keys = $keys;

        return $cached_keys;
    }

    /**
     * Return meta keys that represent POS IDs in ACF fields.
     *
     * @return string[]
     */
    private static function get_pos_id_meta_keys()
    {
        static $cached_keys = null;

        if ( null !== $cached_keys ) {
            return $cached_keys;
        }

        $keys = [ 'regular_pos_id', 'rozpyv_pos_id' ];

        foreach ( self::VARIANT_PREFIXES as $prefix ) {
            for ( $i = 1; $i <= self::VARIANT_COUNT; $i++ ) {
                $keys[] = $prefix . '_variants_' . $i . '_pos_id';
            }
        }

        $cached_keys = $keys;

        return $cached_keys;
    }

    /**
    * Find published products with any non-empty POS ID and load all required meta in bulk.
     *
     * @return array{0:int[],1:array<int,array<string,string>>}
     */
    private static function get_products_meta_by_custom_sql()
    {
        global $wpdb;

        $pos_id_meta_keys      = self::get_pos_id_meta_keys();
        $pos_id_key_placeholders = implode( ', ', array_fill( 0, count( $pos_id_meta_keys ), '%s' ) );

        $product_ids_sql = "
            SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            WHERE p.post_type = 'product'
              AND p.post_status = 'publish'
              AND EXISTS (
                  SELECT 1
                  FROM {$wpdb->postmeta} pm_pos
                  WHERE pm_pos.post_id = p.ID
                    AND pm_pos.meta_key IN ($pos_id_key_placeholders)
                    AND pm_pos.meta_value <> ''
              )
        ";

        $prepared_product_ids_sql = $wpdb->prepare( $product_ids_sql, $pos_id_meta_keys );

        $product_ids = array_map( 'intval', $wpdb->get_col( $prepared_product_ids_sql ) );

        if ( empty( $product_ids ) ) {
            return [ [], [] ];
        }

        $required_meta_keys    = self::get_required_meta_keys();
        $product_placeholders  = implode( ', ', array_fill( 0, count( $product_ids ), '%d' ) );
        $meta_key_placeholders = implode( ', ', array_fill( 0, count( $required_meta_keys ), '%s' ) );
        $meta_sql              = "
            SELECT pm.post_id, pm.meta_key, pm.meta_value
            FROM {$wpdb->postmeta} pm
            WHERE pm.post_id IN ($product_placeholders)
              AND pm.meta_key IN ($meta_key_placeholders)
        ";
        $meta_sql_args         = array_merge( $product_ids, $required_meta_keys );
        $prepared_meta_sql     = $wpdb->prepare( $meta_sql, $meta_sql_args );
        $meta_rows             = $wpdb->get_results( $prepared_meta_sql, ARRAY_A );
        $meta_by_product       = [];

        foreach ( $meta_rows as $row ) {
            $pid = (int) $row['post_id'];

            if ( ! isset( $meta_by_product[ $pid ] ) ) {
                $meta_by_product[ $pid ] = [];
            }

            $meta_by_product[ $pid ][ (string) $row['meta_key'] ] = (string) $row['meta_value'];
        }

        return [ $product_ids, $meta_by_product ];
    }

    /**
     * Read a value from preloaded product meta.
     *
     * @param array<string, string> $product_meta Product meta map.
     * @param string                $key          Meta key.
     * @return string
     */
    private static function get_meta_value( $product_meta, $key )
    {
        return isset( $product_meta[ $key ] ) ? (string) $product_meta[ $key ] : '';
    }

    /**
     * Build flattened product rows and keep only rows with non-empty pos_id.
     *
     * @param int                  $product_id   Product post ID.
     * @param array<string, string> $product_meta Product meta map.
     * @return array<int, array<string, string|int>>
     */
    private static function build_product_rows_from_meta( $product_id, $product_meta )
    {
        $rows = [];

        $regular_pos_id = self::get_meta_value( $product_meta, 'regular_pos_id' );
        if ( '' !== $regular_pos_id ) {
            $rows[] = [
                'product_id'   => $product_id,
                'field_key'    => 'regular',
                'key'          => '',
                'pos_id'       => $regular_pos_id,
                'price'        => self::get_meta_value( $product_meta, '_price' ),
                'old_price'    => self::get_meta_value( $product_meta, '_regular_price' ),
                'stock_status' => self::get_meta_value( $product_meta, '_stock_status' ),
            ];
        }

        $rozpyv_pos_id = self::get_meta_value( $product_meta, 'rozpyv_pos_id' );
        if ( '' !== $rozpyv_pos_id ) {
            $rows[] = [
                'product_id'   => $product_id,
                'field_key'    => 'rozpyv',
                'key'          => '',
                'pos_id'       => $rozpyv_pos_id,
                'price'        => self::get_meta_value( $product_meta, 'rozpyv_price' ),
                'old_price'    => self::get_meta_value( $product_meta, 'rozpyv_old_price' ),
                'stock_status' => self::get_meta_value( $product_meta, 'rozpyv_status' ),
            ];
        }

        foreach ( self::VARIANT_PREFIXES as $prefix ) {
            for ( $i = 1; $i <= self::VARIANT_COUNT; $i++ ) {
                $field_name = $prefix . '_variants_' . $i;
                $group      = self::get_variant_group_from_meta( $product_meta, $field_name );

                if ( '' === $group['pos_id'] ) {
                    continue;
                }

                $rows[] = [
                    'product_id'   => $product_id,
                    'field_key'    => $field_name,
                    'key'          => $group['key'],
                    'pos_id'       => $group['pos_id'],
                    'price'        => $group['price'],
                    'old_price'    => $group['old_price'],
                    'stock_status' => $group['status'],
                ];
            }
        }

        return $rows;
    }

    /**
     * Retrieve a variant group's sub-fields for a product.
     *
     * @param array<string, string> $product_meta Product meta map.
     * @param string                $field_name   ACF group field name.
     * @return array<string, string>
     */
    private static function get_variant_group_from_meta( $product_meta, $field_name )
    {
        // ACF stores group sub-fields as meta keys: <group_name>_<sub_field>.
        $group      = [];

        foreach ( self::VARIANT_SUB_FIELDS as $sub ) {
            $meta_key      = $field_name . '_' . $sub;
            $group[ $sub ] = self::get_meta_value( $product_meta, $meta_key );
        }

        return $group;
    }
}
