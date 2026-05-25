<?php

/**
 * ACF local field registration for WC Product Tabs.
 *
 * @package WC_Product_Tabs
 */

if (! defined('ABSPATH')) {
    exit;
}

class WC_PT_ACF
{

    /**
     * Register ACF integration hooks.
     *
     * @return void
     */
    public static function register_hooks()
    {
        add_action('acf/include_fields', [__CLASS__, 'register_field_group']);
    }

    /**
     * Register Product Prices field group in code.
     *
     * @return void
     */
    public static function register_field_group()
    {
        if (! function_exists('acf_add_local_field_group')) {
            return;
        }

        $settings = new WC_PT_Settings();
        $cat_flakony = (string) $settings->get_category_id('flakony');
        $cat_zalyszky = (string) $settings->get_category_id('zalyszky');
        $cat_rozpyv = (string) $settings->get_category_id('rozpyv');

        $fields = [];

        $fields[] = [
            'key'               => 'field_wcpt_categories',
            'label'             => 'Categories',
            'name'              => 'categories',
            'type'              => 'taxonomy',
            'taxonomy'          => 'product_cat',
            'add_term'          => 1,
            'save_terms'        => 1,
            'load_terms'        => 1,
            'return_format'     => 'id',
            'field_type'        => 'checkbox',
            'multiple'          => 0,
            'allow_null'        => 0,
            'allow_in_bindings' => 0,
            'bidirectional'     => 0,
            'bidirectional_target' => [],
        ];

        $fields[] = self::build_tab('field_wcpt_tab_flakony', 'Flakony', $cat_flakony);
        $fields   = array_merge($fields, self::build_variants_groups('flakony'));

        $fields[] = self::build_tab('field_wcpt_tab_zalyszky', 'Zalyszky', $cat_zalyszky);
        $fields   = array_merge($fields, self::build_variants_groups('zalyszky'));

        $fields[] = self::build_tab('field_wcpt_tab_rozpyv', 'Rozpyv', $cat_rozpyv);
        $fields[] = self::build_text_field('field_wcpt_rozpyv_key', 'rozpyv_key', 'rozpyv_key', '20');
        $fields[] = self::build_text_field('field_wcpt_rozpyv_pos_id', 'rozpyv_pos_id', 'rozpyv_pos_id', '20');
        $fields[] = self::build_text_field('field_wcpt_rozpyv_price', 'rozpyv_price', 'rozpyv_price', '20');
        $fields[] = self::build_text_field('field_wcpt_rozpyv_old_price', 'rozpyv_old_price', 'rozpyv_old_price', '20');
        $fields[] = self::build_status_field('field_wcpt_rozpyv_status', 'rozpyv_status', 'rozpyv_status', '20');
        $fields[] = self::build_text_field('field_wcpt_rozpyv_desc', 'rozpyv_desc', 'rozpyv_desc', '20');

        $fields[] = [
            'key'       => 'field_wcpt_tab_regular',
            'label'     => 'Regular product',
            'name'      => '',
            'type'      => 'tab',
            'placement' => 'top',
            'endpoint'  => 0,
            'selected'  => 0,
        ];
        $fields[] = self::build_text_field('field_wcpt_regular_pos_id', 'regular_pos_id', 'regular_pos_id', '20');

        $fields[] = [
            'key'       => 'field_wcpt_tab_weblium',
            'label'     => 'Weblium',
            'name'      => '',
            'type'      => 'tab',
            'placement' => 'top',
            'endpoint'  => 0,
            'selected'  => 0,
        ];
        $fields[] = self::build_text_field('field_wcpt_weblium_flakony_id', 'weblium_flakony_id', 'weblium_flakony_id', '');
        $fields[] = self::build_text_field('field_wcpt_weblium_rozpyv_id', 'weblium_rozpyv_id', 'weblium_rozpyv_id', '');

        acf_add_local_field_group(
            [
                'key'                   => 'group_wcpt_product_prices',
                'title'                 => 'Product Prices',
                'fields'                => $fields,
                'location'              => [
                    [
                        [
                            'param'    => 'post_type',
                            'operator' => '==',
                            'value'    => 'product',
                        ],
                    ],
                ],
                'menu_order'            => 0,
                'position'              => 'normal',
                'style'                 => 'default',
                'label_placement'       => 'top',
                'instruction_placement' => 'label',
                'hide_on_screen'        => '',
                'active'                => true,
                'description'           => '',
                'show_in_rest'          => 0,
            ]
        );
    }

    /**
     * Build one tab field with category-based conditional logic.
     *
     * @param string $key ACF field key.
     * @param string $label UI label.
     * @param string $category_id Category ID used in condition.
     * @return array<string, mixed>
     */
    private static function build_tab($key, $label, $category_id)
    {
        return [
            'key'               => $key,
            'label'             => $label,
            'name'              => '',
            'type'              => 'tab',
            'conditional_logic' => [
                [
                    [
                        'field'    => 'field_wcpt_categories',
                        'operator' => '==contains',
                        'value'    => $category_id,
                    ],
                ],
            ],
            'placement'         => 'top',
            'endpoint'          => 0,
            'selected'          => 0,
        ];
    }

    /**
     * Build 5 grouped variants for flakony/zalyszky.
     *
     * @param string $prefix Field prefix.
     * @return array<int, array<string, mixed>>
     */
    private static function build_variants_groups($prefix)
    {
        $groups = [];

        for ($i = 1; $i <= 5; $i++) {
            $groups[] = [
                'key'        => 'field_wcpt_' . $prefix . '_variants_' . $i,
                'label'      => $prefix . '_variants_' . $i,
                'name'       => $prefix . '_variants_' . $i,
                'type'       => 'group',
                'layout'     => 'block',
                'wrapper'    => [
                    'width' => '20',
                ],
                'sub_fields' => self::build_variant_sub_fields($prefix, $i),
            ];
        }

        return $groups;
    }

    /**
     * Build sub-fields for a single variant group.
     *
     * @param string $prefix Field prefix.
     * @param int    $index Variant index.
     * @return array<int, array<string, mixed>>
     */
    private static function build_variant_sub_fields($prefix, $index)
    {
        $names = ['key', 'pos_id', 'price', 'old_price', 'desc'];
        $result = [];

        foreach ($names as $name) {
            $result[] = self::build_text_field(
                'field_wcpt_' . $prefix . '_v' . $index . '_' . $name,
                $name,
                $name,
                ''
            );
        }

        $result[] = self::build_status_field(
            'field_wcpt_' . $prefix . '_v' . $index . '_status',
            'status',
            'status',
            ''
        );

        return $result;
    }

    /**
     * Build stock status select with fixed allowed values.
     *
     * @param string $key Field key.
     * @param string $label Field label.
     * @param string $name Field name.
     * @param string $wrapper_width Wrapper width.
     * @return array<string, mixed>
     */
    private static function build_status_field($key, $label, $name, $wrapper_width = '')
    {
        return [
            'key'           => $key,
            'label'         => $label,
            'name'          => $name,
            'type'          => 'select',
            'wrapper'       => [
                'width' => $wrapper_width,
            ],
            'choices'       => [
                'instock'    => 'instock',
                'outofstock' => 'outofstock',
            ],
            'default_value' => 'outofstock',
            'allow_null'    => 0,
            'multiple'      => 0,
            'ui'            => 0,
            'ajax'          => 0,
            'placeholder'   => '',
            'return_format' => 'value',
        ];
    }

    /**
     * Build plain text ACF field.
     *
     * @param string                     $key Field key.
     * @param string                     $label Field label.
     * @param string                     $name Field name.
     * @param string                     $wrapper_width Wrapper width.
     * @param array<int, array<int, array<string, string>>>|int $conditional_logic Optional conditional logic.
     * @return array<string, mixed>
     */
    private static function build_text_field($key, $label, $name, $wrapper_width = '', $conditional_logic = 0)
    {
        return [
            'key'               => $key,
            'label'             => $label,
            'name'              => $name,
            'type'              => 'text',
            'conditional_logic' => $conditional_logic,
            'wrapper'           => [
                'width' => $wrapper_width,
            ],
            'default_value'     => '',
            'maxlength'         => '',
            'allow_in_bindings' => 0,
            'placeholder'       => '',
            'prepend'           => '',
            'append'            => '',
        ];
    }
}
