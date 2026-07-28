<?php

/**
 * Product tabs data building and verification.
 *
 * @package WC_Product_Tabs
 */

if (! defined('ABSPATH')) {
	exit;
}

class WC_PT_Data
{

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
	public function __construct(WC_PT_Settings $settings)
	{
		$this->settings = $settings;
	}

	/**
	 * Build tabs payload for a product based on ACF fields.
	 *
	 * @param int $product_id Product ID.
	 * @return array<string, mixed>|null
	 */
	public function get_product_tabs_data($product_id)
	{
		if (! function_exists('get_field')) {
			return null;
		}

		// Use raw stock meta here to avoid recursion with Woo filters.
		$stock_status = sanitize_key((string) get_post_meta((int) $product_id, '_stock_status', true));
		$product_available = in_array($stock_status, ['instock', 'onbackorder'], true);

		// 1. Combine WordPress taxonomy terms and ACF categories.
		$wp_terms = wp_get_post_terms((int) $product_id, 'product_cat', ['fields' => 'ids']);
		if (is_wp_error($wp_terms)) {
			$wp_terms = [];
		}
		$wp_category_ids = array_map('intval', (array) $wp_terms);

		$raw_acf_categories = (array) get_field('categories', $product_id);
		$acf_category_ids   = array_map(
			function ($category) {
				return is_object($category) ? (int) $category->term_id : (int) $category;
			},
			$raw_acf_categories
		);

		$categories = array_unique(array_filter(array_merge($wp_category_ids, $acf_category_ids)));

		$cat_flakony  = (int) $this->settings->get_category_id('flakony');
		$cat_zalyszky = (int) $this->settings->get_category_id('zalyszky');
		$cat_rozpyv   = (int) $this->settings->get_category_id('rozpyv');

		$tabs = [];

		// Check Flakony: include if valid flakony ACF variants exist (category membership is a bonus check but ACF data is authoritative)
		$flakony_variants = $this->get_variants_from_acf('flakony', $product_id, $product_available);
		if (! empty($flakony_variants)) {
			$tabs['flakony'] = [
				'label'    => 'Флакони',
				'variants' => $flakony_variants,
			];
		}

		// Check Zalyszky: include if valid zalyszky ACF variants exist
		$zalyszky_variants = $this->get_variants_from_acf('zalyszky', $product_id, $product_available);
		if (! empty($zalyszky_variants)) {
			$tabs['zalyszky'] = [
				'label'    => 'Залишки',
				'variants' => $zalyszky_variants,
			];
		}

		// Check Rozpyv: include if rozpyv_price ACF field is set and > 0
		$rozpyv_price_per_ml = $this->to_float(get_field('rozpyv_price', $product_id));
		if ($rozpyv_price_per_ml > 0) {
			$rozpyv_status        = $this->normalize_variant_status(get_field('rozpyv_status', $product_id));
			$rozpyv_old_price_raw = sanitize_text_field((string) get_field('rozpyv_old_price', $product_id));
			$rozpyv_old_price_val = $this->to_float($rozpyv_old_price_raw);
			$old_price            = ($rozpyv_old_price_val > $rozpyv_price_per_ml && $rozpyv_price_per_ml > 0) ? (string) $rozpyv_old_price_val : '';

			$base = [
				'key'          => '',
				'pos_id'       => sanitize_text_field((string) get_field('rozpyv_pos_id', $product_id)),
				'price'        => sanitize_text_field((string) get_field('rozpyv_price', $product_id)),
				'price_per_ml' => $rozpyv_price_per_ml,
				'old_price'    => $old_price,
				'status'       => $rozpyv_status,
				'available'    => $product_available && 'in_stock' === $rozpyv_status && $rozpyv_price_per_ml > 0,
				'desc'         => sanitize_text_field((string) get_field('rozpyv_desc', $product_id)),
			];

			$rozpyv_sizes = $this->settings->get_rozpyv_sizes();
			$rozpyv_atoms = $this->build_rozpyv_atomizers_options($base, $rozpyv_sizes, $this->settings->get_atomizers());

			$tabs['rozpyv'] = [
				'label'        => 'Розпив',
				'base'         => $base,
				'sizes'        => $rozpyv_sizes,
				'size_options' => $rozpyv_atoms['size_options'],
				'atomizers'    => $rozpyv_atoms['atomizers'],
			];
		}

		if (empty($tabs)) {
			return null;
		}

		$tabs = $this->order_tabs_by_priority($tabs);

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
	public function product_has_managed_category($product_id)
	{
		$managed_category_ids = $this->get_managed_category_ids();
		if (empty($managed_category_ids)) {
			return false;
		}

		$wp_terms = wp_get_post_terms((int) $product_id, 'product_cat', ['fields' => 'ids']);
		if (is_wp_error($wp_terms)) {
			$wp_terms = [];
		}
		$product_category_ids = array_map('intval', (array) $wp_terms);

		$raw_acf_categories = (array) get_field('categories', $product_id);
		$acf_category_ids   = array_map(
			function ($category) {
				return is_object($category) ? (int) $category->term_id : (int) $category;
			},
			$raw_acf_categories
		);

		$all_category_ids = array_unique(array_filter(array_merge($product_category_ids, $acf_category_ids)));
		if (! empty(array_intersect($managed_category_ids, $all_category_ids))) {
			return true;
		}

		if (function_exists('get_field') && (float) get_field('rozpyv_price', $product_id) > 0) {
			return true;
		}

		return false;
	}

	/**
	 * Synchronize product min_price, max_price, onsale, and stock_status into WooCommerce core postmeta
	 * and the indexed lookup table (wc_product_meta_lookup) across all tab options.
	 *
	 * @param int $product_id WooCommerce product ID.
	 * @return bool True if synced, false otherwise.
	 */
	public function sync_product_price_bounds($product_id, $force = false)
	{
		static $synced = [];

		$product_id = (int) $product_id;
		if ($product_id <= 0 || ! $this->product_has_managed_category($product_id)) {
			return false;
		}

		if (! $force && isset($synced[$product_id])) {
			return true;
		}
		$synced[$product_id] = true;

		$tabs_data = $this->get_product_tabs_data($product_id);
		if (empty($tabs_data) || empty($tabs_data['tabs'])) {
			return false;
		}

		$bounds        = $this->collect_tab_prices_and_stock($tabs_data['tabs']);
		$target_prices = ! empty($bounds['available_prices']) ? $bounds['available_prices'] : $bounds['all_prices'];

		if (empty($target_prices)) {
			return false;
		}

		$min_price    = min($target_prices);
		$max_price    = max($target_prices);
		$stock_status = $bounds['has_in_stock'] ? 'instock' : 'outofstock';
		$has_sale     = $bounds['has_sale'];

		// 1. Update postmeta
		update_post_meta($product_id, '_min_price', (string) $min_price);
		update_post_meta($product_id, '_max_price', (string) $max_price);
		update_post_meta($product_id, '_price',     (string) $min_price);
		update_post_meta($product_id, '_stock_status', $stock_status);

		if ($has_sale && ! empty($bounds['best_sale'])) {
			$sale_price    = $bounds['best_sale']['sale_price'];
			$regular_price = $bounds['best_sale']['regular_price'];

			update_post_meta($product_id, '_sale_price', (string) $sale_price);
			update_post_meta($product_id, '_regular_price', (string) $regular_price);
		} else {
			update_post_meta($product_id, '_sale_price', '');
			update_post_meta($product_id, '_regular_price', (string) $min_price);
			$has_sale = false;
		}

		// 2. Ensure lookup table row exists, then update lookup table
		if (class_exists('WC_Data_Store')) {
			try {
				$data_store = WC_Data_Store::load('product');
				if (method_exists($data_store, 'update_lookup_table')) {
					$data_store->update_lookup_table($product_id, 'wc_product_meta_lookup');
				}
			} catch (Exception $e) {
				// Ignore lookup table load errors
			}
		}

		global $wpdb;
		$lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$lookup_table}
				 SET min_price = %f, max_price = %f, onsale = %d, stock_status = %s
				 WHERE product_id = %d",
				$min_price,
				$max_price,
				$has_sale ? 1 : 0,
				$stock_status,
				$product_id
			)
		);

		wc_delete_product_transients($product_id);
		delete_transient('wc_products_onsale');

		return true;
	}

	/**
	 * Sync price bounds for products belonging to managed tab categories.
	 * Supports pagination/batching to prevent PHP timeout on large catalogs.
	 *
	 * @param int $page Page number (1-based for pagination, 0 for all).
	 * @param int $per_page Items per batch.
	 * @return array{total_products: int, total_pages: int, page: int, per_page: int, updated: int, has_more: bool}
	 */
	public function sync_all_products_price_bounds($page = 0, $per_page = 50)
	{
		$managed_category_ids = $this->get_managed_category_ids();
		if (empty($managed_category_ids)) {
			return [
				'total_products' => 0,
				'total_pages'    => 0,
				'page'           => max(1, (int) $page),
				'per_page'       => max(1, (int) $per_page),
				'updated'        => 0,
				'has_more'       => false,
			];
		}

		$query_args = [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'tax_query'      => [
				[
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => $managed_category_ids,
					'operator' => 'IN',
				],
			],
		];

		$all_product_ids = get_posts($query_args);
		$total_products  = count($all_product_ids);
		$per_page        = max(1, (int) $per_page);
		$total_pages     = $total_products > 0 ? (int) ceil($total_products / $per_page) : 0;

		if ($page > 0) {
			$current_page = min(max(1, (int) $page), max(1, $total_pages));
			$offset       = ($current_page - 1) * $per_page;
			$target_ids   = array_slice($all_product_ids, $offset, $per_page);
			$has_more     = $current_page < $total_pages;
		} else {
			$current_page = 1;
			$target_ids   = $all_product_ids;
			$has_more     = false;
		}

		$updated_count = 0;
		foreach ((array) $target_ids as $pid) {
			if ($this->sync_product_price_bounds((int) $pid)) {
				$updated_count++;
			}
		}

		return [
			'total_products' => $total_products,
			'total_pages'    => $total_pages,
			'page'           => $current_page,
			'per_page'       => $per_page,
			'updated'        => $updated_count,
			'has_more'       => $has_more,
		];
	}

	/**
	 * Get fallback POS ID for regular simple flow when tabs are unavailable.
	 *
	 * @param int $product_id Product ID.
	 * @return string
	 */
	public function get_regular_product_pos_id($product_id)
	{
		if (! function_exists('get_field')) {
			return '';
		}

		return sanitize_text_field((string) get_field('regular_pos_id', (int) $product_id));
	}

	/**
	 * Verify submitted tab selection and return trusted cart data.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $submitted Submitted data.
	 * @return array<string, mixed>|null
	 */
	public function verify_and_build_tab_data($product_id, $submitted)
	{
		$tabs_data = $this->get_product_tabs_data($product_id);
		if (empty($tabs_data['tabs']) || ! is_array($submitted)) {
			return null;
		}

		$tab = sanitize_key($submitted['tab'] ?? '');
		if (! isset($tabs_data['tabs'][$tab])) {
			return null;
		}

		$tab_config = $tabs_data['tabs'][$tab];

		if (in_array($tab, ['flakony', 'zalyszky'], true)) {
			$variant_index    = (int) ($submitted['variant_index'] ?? 0);
			$submitted_key    = sanitize_text_field((string) ($submitted['key'] ?? ''));
			$submitted_pos_id = sanitize_text_field((string) ($submitted['pos_id'] ?? ''));

			$matched_variant = null;

			// 1. First priority: match by exact key or pos_id
			if ('' !== $submitted_key || '' !== $submitted_pos_id) {
				foreach ($tab_config['variants'] as $variant) {
					if ('' !== $submitted_key && $submitted_key === (string) ($variant['key'] ?? '')) {
						$matched_variant = $variant;
						break;
					}
					if ('' !== $submitted_pos_id && $submitted_pos_id === (string) ($variant['pos_id'] ?? '')) {
						$matched_variant = $variant;
						break;
					}
				}
			}

			// 2. Fallback priority: match by variant_index
			if (! $matched_variant && $variant_index > 0) {
				foreach ($tab_config['variants'] as $variant) {
					if ((int) ($variant['index'] ?? 0) === $variant_index) {
						$matched_variant = $variant;
						break;
					}
				}
			}

			if (! $matched_variant || empty($matched_variant['available'])) {
				return null;
			}

			$variant_price = (float) ($matched_variant['price_value'] ?? $this->to_float($matched_variant['price'] ?? 0));
			if ($variant_price <= 0) {
				return null;
			}

			return [
				'tab'           => $tab,
				'variant_index' => (int) $matched_variant['index'],
				'key'           => $matched_variant['key'],
				'pos_id'        => $matched_variant['pos_id'],
				'price'         => $variant_price,
				'desc'          => $matched_variant['desc'],
			];
		}

		if ('rozpyv' !== $tab) {
			return null;
		}

		$size_ml    = (int) ($submitted['size_ml'] ?? 0);
		$atomizer_id = sanitize_key($submitted['atomizer_id'] ?? '');
		$size_key   = (string) $size_ml;

		if (empty($tab_config['size_options'][$size_key]['available'])) {
			return null;
		}

		$atomizer = $this->find_atomizer($tab_config['atomizers'], $atomizer_id);
		if (empty($atomizer)) {
			return null;
		}

		$option = $atomizer['options'][$size_key] ?? null;
		if (empty($option) || empty($option['available'])) {
			return null;
		}

		$atomizer_price = (float) ($option['atomizer_price'] ?? 0);
		$total_price    = (float) ($option['total_price'] ?? 0);
		if ($total_price <= 0 || $atomizer_price < 0) {
			return null;
		}

		return [
			'tab'            => 'rozpyv',
			'key'            => $tab_config['base']['key'],
			'pos_id'         => $tab_config['base']['pos_id'],
			'price'          => $total_price,
			'size_ml'        => $size_ml,
			'atomizer_id'    => $atomizer['id'],
			'atomizer_title' => sanitize_text_field($atomizer['title']),
			'atomizer_price' => $atomizer_price,
			'desc'           => 'Розпив ' . $size_ml . ' мл — ' . sanitize_text_field($atomizer['title']),
		];
	}

	/**
	 * Get the first available selection for auto-population in cart.
	 *
	 * @param array<string, mixed> $tabs Tabs data.
	 * @return array<string, mixed>|null
	 */
	public function get_first_option($tabs)
	{
		$priority = $this->settings->get_tabs_priority();

		foreach ($priority as $tab_key) {
			if (empty($tabs[$tab_key]) || ! is_array($tabs[$tab_key])) {
				continue;
			}

			$tab = $tabs[$tab_key];

			if (in_array($tab_key, ['flakony', 'zalyszky'], true)) {
				foreach ((array) ($tab['variants'] ?? []) as $variant) {
					if (empty($variant['available'])) {
						continue;
					}

					$variant_price = (float) ($variant['price_value'] ?? $this->to_float($variant['price'] ?? 0));
					if ($variant_price <= 0) {
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

			if ('rozpyv' !== $tab_key) {
				continue;
			}

			foreach ((array) ($tab['sizes'] ?? []) as $size) {
				$size_ml = (int) $size;
				$size_key = (string) $size_ml;

				if (empty($tab['size_options'][$size_key]['available'])) {
					continue;
				}

				foreach ((array) ($tab['atomizers'] ?? []) as $atomizer) {
					$option = $atomizer['options'][$size_key] ?? null;
					if (empty($option) || empty($option['available'])) {
						continue;
					}

					$atomizer_price = (float) ($option['atomizer_price'] ?? 0);
					$total_price    = (float) ($option['total_price'] ?? 0);
					$atomizer_title = sanitize_text_field((string) ($atomizer['title'] ?? ''));

					return [
						'tab'            => 'rozpyv',
						'key'            => $tab['base']['key'] ?? '',
						'pos_id'         => $tab['base']['pos_id'] ?? '',
						'price'          => $total_price,
						'size_ml'        => $size_ml,
						'atomizer_id'    => $atomizer['id'] ?? '',
						'atomizer_title' => $atomizer_title,
						'atomizer_price' => $atomizer_price,
						'desc'           => 'Розпив ' . $size_ml . ' мл' . ($atomizer_title ? ' — ' . $atomizer_title : ''),
					];
				}
			}
		}

		return null;
	}

	/**
	 * Format product price range HTML.
	 *
	 * @param int|string $product_id    Product ID.
	 * @param string     $fallback_html Fallback HTML.
	 * @return string Formatted price range HTML or fallback HTML.
	 */
	public function format_product_price_range_html($product_id, $fallback_html = '')
	{
		$product_id = (int) $product_id;
		if ($product_id <= 0) {
			return $fallback_html;
		}

		$tabs_data = $this->get_product_tabs_data($product_id);
		if (empty($tabs_data) || empty($tabs_data['tabs'])) {
			return $fallback_html;
		}

		$bounds        = $this->collect_tab_prices_and_stock($tabs_data['tabs']);
		$target_prices = ! empty($bounds['available_prices']) ? $bounds['available_prices'] : $bounds['all_prices'];

		if (empty($target_prices)) {
			return $fallback_html;
		}

		$min_price = min($target_prices);
		$max_price = max($target_prices);

		if ($min_price <= 0) {
			return $fallback_html;
		}

		if (abs($min_price - $max_price) < 0.01) {
			return wc_price($min_price);
		}

		$formatted_min = wc_price($min_price, array('aria-hidden' => true));
		$formatted_max = wc_price($max_price, array('aria-hidden' => true));

		return sprintf('%1$s <span aria-hidden="true">&ndash;</span> %2$s', $formatted_min, $formatted_max);
	}

	/**
	 * Count total available options across all tabs for a product.
	 *
	 * @param array<string, mixed> $tabs_data Product tabs payload.
	 * @return int Total available options count.
	 */
	public function count_available_options($tabs_data)
	{
		if (empty($tabs_data['tabs']) || ! is_array($tabs_data['tabs'])) {
			return 0;
		}

		$count = 0;

		foreach ($tabs_data['tabs'] as $tab_key => $tab) {
			if (in_array($tab_key, ['flakony', 'zalyszky'], true)) {
				foreach ((array) ($tab['variants'] ?? []) as $variant) {
					if (! empty($variant['available']) && (float) ($variant['price_value'] ?? 0) > 0) {
						$count++;
					}
				}
			} elseif ('rozpyv' === $tab_key) {
				foreach ((array) ($tab['sizes'] ?? []) as $size) {
					$size_key = (string) $size;
					if (empty($tab['size_options'][$size_key]['available'])) {
						continue;
					}

					foreach ((array) ($tab['atomizers'] ?? []) as $atomizer) {
						$option = $atomizer['options'][$size_key] ?? null;
						if (! empty($option) && ! empty($option['available'])) {
							$count++;
						}
					}
				}
			}
		}

		return $count;
	}

	/**
	 * Order tabs array according to settings priority.
	 *
	 * @param array<string, mixed> $tabs Tabs keyed by slug.
	 * @return array<string, mixed>
	 */
	private function order_tabs_by_priority($tabs)
	{
		$ordered   = [];
		$priority  = $this->settings->get_tabs_priority();

		foreach ($priority as $tab_key) {
			if (isset($tabs[$tab_key])) {
				$ordered[$tab_key] = $tabs[$tab_key];
			}
		}

		foreach ($tabs as $tab_key => $tab_value) {
			if (! isset($ordered[$tab_key])) {
				$ordered[$tab_key] = $tab_value;
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
	private function get_variants_from_acf($field_prefix, $product_id, $product_available = true)
	{
		$variants = [];

		for ($index = 1; $index <= 5; $index++) {
			$group = get_field("{$field_prefix}_variants_{$index}", $product_id);
			if (! is_array($group)) {
				continue;
			}

			$variant_key = sanitize_text_field((string) ($group['key'] ?? ''));

			$price_raw = sanitize_text_field($group['price'] ?? '');
			$old_price_raw = sanitize_text_field($group['old_price'] ?? '');
			$desc  = sanitize_text_field($group['desc'] ?? '');

			$price_value = $this->to_float($price_raw);
			$old_price_value = $this->to_float($old_price_raw);

			// Rows without both key and price are not valid options; skip them entirely.
			if ('' === $variant_key || '' === $price_raw) {
				continue;
			}

			$status = $this->normalize_variant_status($group['status'] ?? '');
			if ($price_value <= 0) {
				$status = 'out_of_stock';
			}

			$old_price = '';
			if ($old_price_value > $price_value && $price_value > 0) {
				$old_price = (string) $old_price_value;
			}

			$variants[] = [
				'index'     => $index,
				'key'       => $variant_key,
				'pos_id'    => sanitize_text_field($group['pos_id'] ?? ''),
				'price'     => $price_value > 0 ? (string) $price_value : '',
				'price_value' => $price_value,
				'old_price' => $old_price,
				'status'    => $status,
				'available' => $product_available && 'in_stock' === $status && $price_value > 0,
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
	private function normalize_variant_status($status)
	{
		if ('in_stock' === sanitize_key((string) $status)) {
			return 'in_stock';
		}

		return 'out_of_stock';
	}

	/**
	 * Check if variant is available for selection.
	 *
	 * @param array<string, mixed> $variant Variant data.
	 * @return bool
	 */
	private function is_variant_instock($variant)
	{
		return 'in_stock' === $this->normalize_variant_status($variant['status'] ?? '');
	}

	/**
	 * Convert a raw numeric value to float, returning 0 for invalid input.
	 *
	 * @param mixed $value Raw value.
	 * @return float
	 */
	private function to_float($value)
	{
		$raw = sanitize_text_field((string) $value);
		$raw = str_replace(',', '.', $raw);

		if ('' === $raw || ! is_numeric($raw)) {
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
	private function find_atomizer($atomizers, $atomizer_id)
	{
		foreach ((array) $atomizers as $atomizer) {
			if (! is_array($atomizer)) {
				continue;
			}
			if (($atomizer['id'] ?? '') === $atomizer_id) {
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
	private function build_rozpyv_atomizers_options($base, $sizes, $atomizers)
	{
		$price_per_ml     = (float) ($base['price_per_ml'] ?? 0);
		$old_price_per_ml = $this->to_float($base['old_price'] ?? 0);
		$base_available   = ! empty($base['available']);

		$size_options = [];
		foreach ((array) $sizes as $size) {
			$size_ml = (int) $size;
			if ($size_ml <= 0) {
				continue;
			}

			$size_options[(string) $size_ml] = [
				'available' => false,
			];
		}

		$resolved_atomizers = [];

		foreach ((array) $atomizers as $atomizer) {
			if (! is_array($atomizer)) {
				continue;
			}

			$in_stock = ! isset($atomizer['in_stock']) || (bool) $atomizer['in_stock'];
			$available_sizes = array_map('intval', (array) ($atomizer['available_sizes'] ?? []));
			$prices = (array) ($atomizer['prices'] ?? []);
			$size_images = (array) ($atomizer['size_images'] ?? []);
			$default_image = (string) ($atomizer['image'] ?? '');

			$options = [];

			foreach ($size_options as $size_key => $unused) {
				$size_ml = (int) $size_key;
				$base_price     = $price_per_ml * $size_ml;
				$old_base_price = ($old_price_per_ml > $price_per_ml && $price_per_ml > 0) ? ($old_price_per_ml * $size_ml) : 0;

				$atomizer_price  = $this->to_float($prices[$size_ml] ?? $prices[$size_key] ?? 0);
				$option_image    = (string) ($size_images[$size_ml] ?? $size_images[$size_key] ?? $default_image);
				$is_size_allowed = in_array($size_ml, $available_sizes, true);
				$total_price     = $base_price + $atomizer_price;
				$old_total_price = ($old_base_price > 0) ? ($old_base_price + $atomizer_price) : 0;

				$available = $base_available
					&& $in_stock
					&& $is_size_allowed
					&& $price_per_ml > 0
					&& $base_price > 0
					&& $atomizer_price >= 0
					&& $total_price > 0;

				if ($available) {
					$size_options[$size_key]['available'] = true;
				}

				$options[$size_key] = [
					'available'       => $available,
					'atomizer_price'  => max(0, $atomizer_price),
					'total_price'     => $available ? $total_price : 0,
					'old_total_price' => ($available && $old_total_price > $total_price) ? $old_total_price : 0,
					'image'           => $option_image,
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
	 * Return configured managed category IDs for tabs products.
	 *
	 * @return int[]
	 */
	private function get_managed_category_ids()
	{
		$ids = [
			(int) $this->settings->get_category_id('flakony'),
			(int) $this->settings->get_category_id('zalyszky'),
			(int) $this->settings->get_category_id('rozpyv'),
		];

		$ids = array_values(array_unique(array_filter($ids)));
		return array_map('intval', $ids);
	}

	/**
	 * Collect all price boundaries and stock flags across all tabs.
	 *
	 * @param array<string, mixed> $tabs Tabs data.
	 * @return array{all_prices: float[], available_prices: float[], has_sale: bool, has_in_stock: bool, best_sale: array{sale_price: float, regular_price: float, pct: float, amount: float}|null}
	 */
	private function collect_tab_prices_and_stock(array $tabs)
	{
		$all_prices       = [];
		$available_prices = [];
		$sale_options     = [];
		$has_in_stock     = false;

		foreach ($tabs as $tab_key => $tab) {
			if (in_array($tab_key, ['flakony', 'zalyszky'], true)) {
				foreach ((array) ($tab['variants'] ?? []) as $variant) {
					$price_val = (float) ($variant['price_value'] ?? 0);
					if ($price_val <= 0) {
						continue;
					}

					$all_prices[] = $price_val;

					if (! empty($variant['available'])) {
						$available_prices[] = $price_val;
						$has_in_stock       = true;

						$old_price_val = $this->to_float($variant['old_price'] ?? 0);
						if ($old_price_val > $price_val) {
							$amount         = $old_price_val - $price_val;
							$pct            = $amount / $old_price_val;
							$sale_options[] = [
								'sale_price'    => $price_val,
								'regular_price' => $old_price_val,
								'pct'           => $pct,
								'amount'        => $amount,
							];
						}
					}
				}
			} elseif ('rozpyv' === $tab_key) {
				$base             = $tab['base'] ?? [];
				$price_per_ml     = (float) ($base['price_per_ml'] ?? 0);
				$old_price_per_ml = $this->to_float($base['old_price'] ?? 0);
				$rozpyv_available = ! empty($base['available']);
				$has_rozpyv_sale  = ($old_price_per_ml > $price_per_ml && $price_per_ml > 0);

				if ($price_per_ml > 0) {
					if ($rozpyv_available) {
						$has_in_stock = true;
					}

					$sizes     = (array) ($tab['sizes'] ?? []);
					$atomizers = (array) ($tab['atomizers'] ?? []);

					foreach ($sizes as $size) {
						$size_ml = (int) $size;
						if ($size_ml <= 0) {
							continue;
						}

						$size_key = (string) $size_ml;
						if (empty($tab['size_options'][$size_key]['available'])) {
							continue;
						}

						$base_price     = $price_per_ml * $size_ml;
						$base_old_price = $has_rozpyv_sale ? ($old_price_per_ml * $size_ml) : 0;

						$all_prices[] = $base_price;

						if (! empty($atomizers)) {
							foreach ($atomizers as $atomizer) {
								$option = $atomizer['options'][$size_key] ?? null;
								if (! empty($option)) {
									$atomizer_price = (float) ($option['atomizer_price'] ?? 0);
									$total_price    = $base_price + $atomizer_price;

									$all_prices[] = $total_price;

									if (! empty($option['available'])) {
										$available_prices[] = $total_price;
										$has_in_stock       = true;

										if ($has_rozpyv_sale) {
											$total_old_price = $base_old_price + $atomizer_price;
											$amount          = $total_old_price - $total_price;
											$pct             = $amount / $total_old_price;
											$sale_options[]  = [
												'sale_price'    => $total_price,
												'regular_price' => $total_old_price,
												'pct'           => $pct,
												'amount'        => $amount,
											];
										}
									}
								}
							}
						} else {
							if ($rozpyv_available) {
								$available_prices[] = $base_price;

								if ($has_rozpyv_sale) {
									$amount         = $base_old_price - $base_price;
									$pct            = $amount / $base_old_price;
									$sale_options[] = [
										'sale_price'    => $base_price,
										'regular_price' => $base_old_price,
										'pct'           => $pct,
										'amount'        => $amount,
									];
								}
							}
						}
					}
				}
			}
		}

		$best_sale = null;
		$has_sale  = ! empty($sale_options);

		if ($has_sale) {
			usort($sale_options, static function ($a, $b) {
				if (abs($a['pct'] - $b['pct']) > 0.0001) {
					return $b['pct'] <=> $a['pct'];
				}
				if (abs($a['amount'] - $b['amount']) > 0.01) {
					return $b['amount'] <=> $a['amount'];
				}
				return $a['sale_price'] <=> $b['sale_price'];
			});

			$best_sale = $sale_options[0];
		}

		return [
			'all_prices'       => $all_prices,
			'available_prices' => $available_prices,
			'has_sale'         => $has_sale,
			'has_in_stock'     => $has_in_stock,
			'best_sale'        => $best_sale,
		];
	}
}
