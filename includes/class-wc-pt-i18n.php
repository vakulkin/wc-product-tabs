<?php

/**
 * Translation management class for WC Product Tabs.
 *
 * @package WC_Product_Tabs
 */

if (! defined('ABSPATH')) {
	exit;
}

class WC_PT_I18n
{

	/**
	 * Map of translation keys to translated strings.
	 * Every unique text in the plugin is defined exactly once here.
	 *
	 * @return array<string, string>
	 */
	public static function get_strings()
	{
		static $strings = null;

		if (null === $strings) {
			$strings = [
				// Frontend / JS i18n
				'add_to_cart'                    => __('Додати в кошик', 'wc-product-tabs'),
				'added'                          => __('Додано!', 'wc-product-tabs'),
				'select_option'                  => __('Оберіть варіант', 'wc-product-tabs'),
				'select_atomizer'                => __('Оберіть атомайзер', 'wc-product-tabs'),
				'out_of_stock'                   => __('Немає в наявності', 'wc-product-tabs'),
				'notify_title'                   => __('Повідомити про надходження', 'wc-product-tabs'),
				'notify_desc'                    => __('Залиште контакт — ми сповістимо вас, коли аромат знову буде доступний у розмірі', 'wc-product-tabs'),
				'notify_desc_global'             => __('Залиште контакт — ми сповістимо вас, коли аромат знову буде доступний.', 'wc-product-tabs'),
				'notify_placeholder'             => __('+380 XX XXX XX XX', 'wc-product-tabs'),
				'notify_submit'                  => __('Сповістити', 'wc-product-tabs'),
				'notify_success'                 => __('Дякуємо! Повідомимо вас.', 'wc-product-tabs'),
				'notify_error'                   => __('Помилка. Спробуйте ще раз.', 'wc-product-tabs'),
				'notify_error_phone'             => __('Введіть номер телефону.', 'wc-product-tabs'),

				// Cart / Checkout Notices
				'cart_items_removed'             => __('Деякі товари були видалені з кошика, оскільки їх немає в наявності.', 'wc-product-tabs'),
				'cart_prices_updated'            => __('Ціну товарів у кошику було оновлено відповідно до актуальних цін.', 'wc-product-tabs'),

				// Order / Cart Metadata Labels
				'meta_tab'                       => __('Тип', 'wc-product-tabs'),
				'meta_key'                       => __('Варіант', 'wc-product-tabs'),
				'meta_price'                     => __('Ціна', 'wc-product-tabs'),
				'meta_size_ml'                   => __("Об'єм", 'wc-product-tabs'),
				'meta_atomizer_title'            => __('Атомайзер', 'wc-product-tabs'),
				'meta_atomizer_price'            => __('Ціна атомайзера', 'wc-product-tabs'),
				'tab_flakony'                    => __('Флакон', 'wc-product-tabs'),
				'tab_zalyszky'                   => __('Залишок', 'wc-product-tabs'),
				'tab_rozpyv'                     => __('Розпив', 'wc-product-tabs'),

				// Admin / Settings
				'plugin_name'                    => __('WC Product Tabs', 'wc-product-tabs'),
				'atomizers_json_invalid'         => __('Atomizers JSON is invalid. Previous valid value has been kept.', 'wc-product-tabs'),
				'settings_desc'                  => __('Category IDs and available розпив sizes used by the plugin logic.', 'wc-product-tabs'),
				'cat_flakony'                    => __('Flakony category ID', 'wc-product-tabs'),
				'cat_zalyszky'                   => __('Zalyszky category ID', 'wc-product-tabs'),
				'cat_rozpyv'                     => __('Rozpyv category ID', 'wc-product-tabs'),
				'rozpyv_sizes'                   => __('Rozpyv sizes (ml)', 'wc-product-tabs'),
				'rozpyv_sizes_desc'              => __('Comma-separated list, for example: 2, 3, 5, 10, 15', 'wc-product-tabs'),
				'tabs_priority'                  => __('Tabs display and default priority', 'wc-product-tabs'),
				'tabs_priority_desc'             => __('Used for tab order in UI and default auto-selection.', 'wc-product-tabs'),
				'flakony'                        => __('Flakony', 'wc-product-tabs'),
				'rozpyv'                         => __('Rozpyv', 'wc-product-tabs'),
				'zalyszky'                       => __('Zalyszky', 'wc-product-tabs'),
				'position_n'                     => __('Position %d', 'wc-product-tabs'),
				'api_token'                      => __('API Token', 'wc-product-tabs'),
				'api_token_desc'                 => __('Secret token for the /wp-json/wc-product-tabs/v1/products endpoint. Pass as: Authorization: Bearer <token>', 'wc-product-tabs'),
				'poster_api_token'               => __('Poster API Token', 'wc-product-tabs'),
				'poster_api_token_desc'          => __('Poster POS API token (format: account_id:token). Used for automatic price sync via menu.getProducts.', 'wc-product-tabs'),
				'notify_url'                     => __('Notify API URL (out-of-stock)', 'wc-product-tabs'),
				'notify_url_desc'                => __('GET request sent when a customer submits their phone for an out-of-stock item.', 'wc-product-tabs'),
				'params_appended'                => __('Params appended:', 'wc-product-tabs'),
				'expected_json_response'         => __('Expected JSON response:', 'wc-product-tabs'),
				'or'                             => __('or', 'wc-product-tabs'),
				'atomizers_json'                 => __('Atomizers JSON', 'wc-product-tabs'),
				'atomizers_json_desc'            => __('Use simplified format: id, title, image, in_stock, sizes. Example: "in_stock": true, "sizes": { "2": 10, "3": 15 }', 'wc-product-tabs'),

				// CRON authentication
				'cron_secret'                    => __('CRON Secret Token', 'wc-product-tabs'),
				'cron_secret_desc'               => __('Pass this token in CRON requests as <code>?token=…</code> or <code>Authorization: Bearer …</code>. Keep it secret.', 'wc-product-tabs'),
				'cron_secret_regenerate'         => __('Regenerate', 'wc-product-tabs'),
				'cron_secret_copy'               => __('Copy', 'wc-product-tabs'),

				// Admin Poster Sync panel
				'sync_panel_title'               => __('Poster Price Sync', 'wc-product-tabs'),
				'sync_panel_desc'                => __('Manually trigger the Poster price sync or rebuild the price-bounds index.', 'wc-product-tabs'),
				'sync_start_btn'                 => __('Start Sync', 'wc-product-tabs'),
				'sync_reindex_btn'               => __('Re-index Prices', 'wc-product-tabs'),
				'sync_status_idle'               => __('Idle – click a button to start.', 'wc-product-tabs'),
				'sync_status_starting'           => __('Starting…', 'wc-product-tabs'),
				'sync_status_running'            => __('Running batch %d / %d…', 'wc-product-tabs'),
				'sync_status_done'               => __('Done. Updated: %d | Errors: %d', 'wc-product-tabs'),
				'sync_status_reindex_running'    => __('Re-indexing page %d…', 'wc-product-tabs'),
				'sync_status_reindex_done'       => __('Re-index complete.', 'wc-product-tabs'),
				'sync_status_error'              => __('Error: %s', 'wc-product-tabs'),

				// Poster API Sync
				'poster_token_not_configured'    => __('Poster API token is not configured.', 'wc-product-tabs'),
				'poster_request_failed'          => __('Poster API request failed: %s', 'wc-product-tabs'),
				'poster_unexpected_response'     => __('Poster API returned unexpected response (HTTP %d).', 'wc-product-tabs'),
				'poster_no_products'             => __('Poster API returned no products.', 'wc-product-tabs'),
				'poster_storage_request_failed'  => __('Poster Storage API request failed: %s', 'wc-product-tabs'),
				'poster_storage_unexpected_resp' => __('Poster Storage API returned unexpected response (HTTP %d).', 'wc-product-tabs'),
				'cron_unauthorized'              => __('Missing or invalid CRON token.', 'wc-product-tabs'),
			];
		}

		return $strings;
	}

	/**
	 * Get a translated string by key.
	 *
	 * @param string $key Translation key.
	 * @param string $default Default text if key not found.
	 * @return string Translated text.
	 */
	public static function get($key, $default = '')
	{
		$strings = self::get_strings();
		return $strings[$key] ?? $default;
	}

	/**
	 * Get JS i18n array for script payload.
	 *
	 * @return array<string, string>
	 */
	public static function get_frontend_i18n()
	{
		$strings = self::get_strings();
		$keys    = [
			'add_to_cart',
			'added',
			'select_option',
			'select_atomizer',
			'out_of_stock',
			'notify_title',
			'notify_desc',
			'notify_desc_global',
			'notify_placeholder',
			'notify_submit',
			'notify_success',
			'notify_error',
			'notify_error_phone',
		];

		$i18n = [];
		foreach ($keys as $key) {
			if (isset($strings[$key])) {
				$i18n[$key] = $strings[$key];
			}
		}

		return $i18n;
	}
}
