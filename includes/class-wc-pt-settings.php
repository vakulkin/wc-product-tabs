<?php

/**
 * Admin settings and runtime configuration access.
 *
 * @package WC_Product_Tabs
 */

if (!defined('ABSPATH')) {
	exit;
}

class WC_PT_Settings
{

	const OPTION_KEY = 'wc_product_tabs_settings';
	const DEFAULT_CAT_FLAKONY = 17;
	const DEFAULT_CAT_ZALYSZKY = 1023;
	const DEFAULT_CAT_ROZPYV = 19;
	const DEFAULT_ROZPYV_SIZES = [2, 3, 5, 10, 15];
	const DEFAULT_TABS_PRIORITY = ['flakony', 'rozpyv', 'zalyszky'];

	/**
	 * Register admin-facing hooks.
	 *
	 * @return void
	 */
	public function register_hooks()
	{
		add_action('admin_menu', [$this, 'register_admin_page']);
		add_action('admin_init', [$this, 'register_settings']);
	}

	/**
	 * One-time migration from legacy atomizers.json file to plugin option.
	 *
	 * @return void
	 */
	public function maybe_migrate_legacy_atomizers()
	{
		$settings = get_option(self::OPTION_KEY, []);
		if (!is_array($settings)) {
			$settings = [];
		}

		$file = WC_PT_PLUGIN_DIR . 'atomizers.json';
		if (!file_exists($file)) {
			return;
		}

		$contents = file_get_contents($file); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if (!is_string($contents) || '' === trim($contents)) {
			return;
		}

		$file_hash = md5($contents);
		$stored_hash = sanitize_text_field((string) ($settings['atomizers_file_hash'] ?? ''));
		$has_saved = !empty($settings['atomizers']) && is_array($settings['atomizers']);

		if ($has_saved && '' !== $stored_hash && hash_equals($stored_hash, $file_hash)) {
			return;
		}

		$decoded = json_decode($contents, true);

		$atomizers = $this->normalize_atomizers(is_array($decoded) ? $decoded : []);
		if (empty($atomizers)) {
			return;
		}

		$settings['atomizers'] = $atomizers;
		$settings['atomizers_file_hash'] = $file_hash;
		update_option(self::OPTION_KEY, $settings, false);
	}

	/**
	 * Register the settings page under Settings.
	 *
	 * @return void
	 */
	public function register_admin_page()
	{
		$title = WC_PT_I18n::get('plugin_name');
		add_options_page(
			$title,
			$title,
			'manage_options',
			'wc-product-tabs',
			[$this, 'render_settings_page']
		);
	}

	/**
	 * Register option with sanitization callback.
	 *
	 * @return void
	 */
	public function register_settings()
	{
		register_setting(
			'wc_product_tabs_settings_group',
			self::OPTION_KEY,
			[$this, 'sanitize_settings']
		);
	}

	/**
	 * Sanitize plugin options.
	 *
	 * @param mixed $input Raw option value.
	 * @return array<string, mixed>
	 */
	public function sanitize_settings($input)
	{
		$defaults = $this->get_default_settings();
		$input = is_array($input) ? $input : [];
		$current = get_option(self::OPTION_KEY, []);

		$atomizers_input = $input['atomizers_json'] ?? ($input['atomizers'] ?? []);
		$atomizers_raw = [];

		if (is_string($atomizers_input)) {
			$decoded = json_decode((string) $atomizers_input, true);
			if (JSON_ERROR_NONE === json_last_error() && is_array($decoded)) {
				$atomizers_raw = $decoded;
			} else {
				add_settings_error(
					self::OPTION_KEY,
					'atomizers_json_invalid',
					esc_html(WC_PT_I18n::get('atomizers_json_invalid')),
					'error'
				);
				$atomizers_raw = $this->get_atomizers();
			}
		} elseif (is_array($atomizers_input)) {
			$atomizers_raw = $atomizers_input;
		}

		// Preserve or generate the cron_secret.
		$current_secret = is_array($current) ? sanitize_text_field((string) ($current['cron_secret'] ?? '')) : '';
		$new_secret     = sanitize_text_field((string) ($input['cron_secret'] ?? ''));
		if ('' === $new_secret) {
			// Empty submission means regenerate was triggered — create a fresh token.
			$new_secret = wp_generate_password(32, false);
		} elseif ($new_secret === $current_secret) {
			// Same value submitted (normal save) — keep it as-is.
			$new_secret = $current_secret;
		}

		$settings = [
			'cat_flakony'      => max(1, (int) ($input['cat_flakony'] ?? $defaults['cat_flakony'])),
			'cat_zalyszky'     => max(1, (int) ($input['cat_zalyszky'] ?? $defaults['cat_zalyszky'])),
			'cat_rozpyv'       => max(1, (int) ($input['cat_rozpyv'] ?? $defaults['cat_rozpyv'])),
			'rozpyv_sizes'     => $this->parse_sizes_csv($input['rozpyv_sizes'] ?? ''),
			'tabs_priority'    => $this->sanitize_tabs_priority($input['tabs_priority'] ?? $defaults['tabs_priority']),
			'atomizers'        => $this->normalize_atomizers($atomizers_raw),
			'api_token'        => sanitize_text_field($input['api_token'] ?? $defaults['api_token']),
			'poster_api_token' => sanitize_text_field($input['poster_api_token'] ?? $defaults['poster_api_token']),
			'notify_url'       => esc_url_raw((string) ($input['notify_url'] ?? $defaults['notify_url'])),
			'cron_secret'      => $new_secret,
		];

		if (empty($settings['rozpyv_sizes'])) {
			$settings['rozpyv_sizes'] = $defaults['rozpyv_sizes'];
		}

		$settings['atomizers_file_hash'] = is_array($current)
			? sanitize_text_field((string) ($current['atomizers_file_hash'] ?? ''))
			: '';

		return $settings;
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public function render_settings_page()
	{
		if (!current_user_can('manage_options')) {
			return;
		}

		$settings = $this->get_settings();
		$atomizers_json = wp_json_encode($this->get_atomizers_for_editor($settings['atomizers']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
		<div class="wrap">
			<h1><?php echo esc_html(WC_PT_I18n::get('plugin_name')); ?></h1>
			<p><?php echo esc_html(WC_PT_I18n::get('settings_desc')); ?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields('wc_product_tabs_settings_group'); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label
								for="wcpt-cat-flakony"><?php echo esc_html(WC_PT_I18n::get('cat_flakony')); ?></label>
						</th>
						<td>
							<input id="wcpt-cat-flakony" name="<?php echo esc_attr(self::OPTION_KEY); ?>[cat_flakony]"
								type="number" min="1" value="<?php echo esc_attr($settings['cat_flakony']); ?>"
								class="small-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label
								for="wcpt-cat-zalyszky"><?php echo esc_html(WC_PT_I18n::get('cat_zalyszky')); ?></label>
						</th>
						<td>
							<input id="wcpt-cat-zalyszky" name="<?php echo esc_attr(self::OPTION_KEY); ?>[cat_zalyszky]"
								type="number" min="1" value="<?php echo esc_attr($settings['cat_zalyszky']); ?>"
								class="small-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label
								for="wcpt-cat-rozpyv"><?php echo esc_html(WC_PT_I18n::get('cat_rozpyv')); ?></label>
						</th>
						<td>
							<input id="wcpt-cat-rozpyv" name="<?php echo esc_attr(self::OPTION_KEY); ?>[cat_rozpyv]"
								type="number" min="1" value="<?php echo esc_attr($settings['cat_rozpyv']); ?>"
								class="small-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label
								for="wcpt-rozpyv-sizes"><?php echo esc_html(WC_PT_I18n::get('rozpyv_sizes')); ?></label>
						</th>
						<td>
							<input id="wcpt-rozpyv-sizes" name="<?php echo esc_attr(self::OPTION_KEY); ?>[rozpyv_sizes]"
								type="text" value="<?php echo esc_attr(implode(', ', $settings['rozpyv_sizes'])); ?>"
								class="regular-text" />
							<p class="description">
								<?php echo esc_html(WC_PT_I18n::get('rozpyv_sizes_desc')); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html(WC_PT_I18n::get('tabs_priority')); ?></th>
						<td>
							<?php
							$priority_options = [
								'flakony'  => WC_PT_I18n::get('flakony'),
								'rozpyv'   => WC_PT_I18n::get('rozpyv'),
								'zalyszky' => WC_PT_I18n::get('zalyszky'),
							];
							$priority = $this->sanitize_tabs_priority($settings['tabs_priority'] ?? []);
							for ($i = 0; $i < 3; $i++):
								$current = $priority[$i] ?? self::DEFAULT_TABS_PRIORITY[$i];
							?>
								<p>
									<label
										for="wcpt-tabs-priority-<?php echo esc_attr((string) $i); ?>"><?php echo esc_html(sprintf(WC_PT_I18n::get('position_n'), $i + 1)); ?></label>
									<select id="wcpt-tabs-priority-<?php echo esc_attr((string) $i); ?>"
										name="<?php echo esc_attr(self::OPTION_KEY); ?>[tabs_priority][]">
										<?php foreach ($priority_options as $value => $label): ?>
											<option value="<?php echo esc_attr($value); ?>" <?php selected($current, $value); ?>>
												<?php echo esc_html($label); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</p>
							<?php endfor; ?>
							<p class="description">
								<?php echo esc_html(WC_PT_I18n::get('tabs_priority_desc')); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label
								for="wcpt-api-token"><?php echo esc_html(WC_PT_I18n::get('api_token')); ?></label></th>
						<td>
							<input id="wcpt-api-token" name="<?php echo esc_attr(self::OPTION_KEY); ?>[api_token]" type="text"
								value="<?php echo esc_attr($settings['api_token'] ?? ''); ?>" class="regular-text" />
							<p class="description">
								<?php echo esc_html(WC_PT_I18n::get('api_token_desc')); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label
								for="wcpt-poster-api-token"><?php echo esc_html(WC_PT_I18n::get('poster_api_token')); ?></label>
						</th>
						<td>
							<input id="wcpt-poster-api-token" name="<?php echo esc_attr(self::OPTION_KEY); ?>[poster_api_token]"
								type="text" value="<?php echo esc_attr($settings['poster_api_token'] ?? ''); ?>"
								class="regular-text" />
							<p class="description">
								<?php echo esc_html(WC_PT_I18n::get('poster_api_token_desc')); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label
								for="wcpt-notify-url"><?php echo esc_html(WC_PT_I18n::get('notify_url')); ?></label>
						</th>
						<td>
							<input id="wcpt-notify-url" name="<?php echo esc_attr(self::OPTION_KEY); ?>[notify_url]"
								type="url" value="<?php echo esc_attr($settings['notify_url'] ?? ''); ?>"
								class="regular-text" placeholder="https://" />
							<p class="description">
								<?php echo esc_html(WC_PT_I18n::get('notify_url_desc')); ?><br>
								<strong><?php echo esc_html(WC_PT_I18n::get('params_appended')); ?></strong>
								<code>phone</code>, <code>product_id</code>, <code>tab</code>, <code>key</code>, <code>size_ml</code>, <code>atomizer_id</code>, <code>label</code><br>
								<strong><?php echo esc_html(WC_PT_I18n::get('expected_json_response')); ?></strong>
								<code>{"success": true}</code> <?php echo esc_html(WC_PT_I18n::get('or')); ?>
								<code>{"success": false, "message": "Error text"}</code>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label
								for="wcpt-atomizers-json"><?php echo esc_html(WC_PT_I18n::get('atomizers_json')); ?></label>
						</th>
						<td>
							<textarea id="wcpt-atomizers-json" name="<?php echo esc_attr(self::OPTION_KEY); ?>[atomizers_json]"
								rows="14"
								class="large-text code"><?php echo esc_textarea((string) $atomizers_json); ?></textarea>
							<p class="description">
								<?php echo esc_html(WC_PT_I18n::get('atomizers_json_desc')); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<?php
			$cron_secret = esc_attr($settings['cron_secret']);
			$start_url   = esc_url(rest_url('wc-product-tabs/v1/poster-sync/start'));
			$reindex_url = esc_url(rest_url('wc-product-tabs/v1/poster-sync/reindex-prices'));
			?>

			<hr />
			<h2><?php echo esc_html(WC_PT_I18n::get('cron_secret')); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wcpt-cron-secret-display"><?php echo esc_html(WC_PT_I18n::get('cron_secret')); ?></label></th>
					<td>
						<input id="wcpt-cron-secret-display" type="text" readonly
							value="<?php echo $cron_secret; ?>"
							class="regular-text code" style="font-family:monospace;" />
						<button type="button" id="wcpt-copy-secret" class="button button-secondary" style="margin-left:6px;">
							<?php echo esc_html(WC_PT_I18n::get('cron_secret_copy')); ?>
						</button>
						<button type="button" id="wcpt-regen-secret" class="button button-secondary" style="margin-left:4px;">
							<?php echo esc_html(WC_PT_I18n::get('cron_secret_regenerate')); ?>
						</button>
						<p class="description"><?php echo wp_kses(WC_PT_I18n::get('cron_secret_desc'), ['code' => []]); ?></p>
						<p class="description" style="margin-top:4px;">
							<strong>CRON URL (start):</strong>
							<code id="wcpt-cron-url-example"><?php echo esc_html(add_query_arg('token', $cron_secret, rest_url('wc-product-tabs/v1/poster-sync/start'))); ?></code>
						</p>
					</td>
				</tr>
			</table>

			<hr />
			<h2><?php echo esc_html(WC_PT_I18n::get('sync_panel_title')); ?></h2>
			<p><?php echo esc_html(WC_PT_I18n::get('sync_panel_desc')); ?></p>
			<p class="description" style="margin-bottom:10px;">
				<?php esc_html_e('Note: The CRON job processes batches automatically every minute. Use these buttons to trigger a sync manually.', 'wc-product-tabs'); ?>
			</p>

			<p>
				<button type="button" id="wcpt-sync-start" class="button button-primary">
					<?php echo esc_html(WC_PT_I18n::get('sync_start_btn')); ?>
				</button>
				&nbsp;
				<button type="button" id="wcpt-sync-reindex" class="button button-secondary">
					<?php echo esc_html(WC_PT_I18n::get('sync_reindex_btn')); ?>
				</button>
			</p>

			<div id="wcpt-sync-status-wrap" style="
				background:#f6f7f7;border:1px solid #c3c4c7;border-radius:4px;
				padding:12px 16px;max-width:600px;min-height:48px;margin-top:8px;
			">
				<p id="wcpt-sync-status-text" style="margin:0;font-style:italic;color:#50575e;">
					<?php echo esc_html(WC_PT_I18n::get('sync_status_idle')); ?>
				</p>
				<details id="wcpt-sync-log-details" style="margin-top:8px;display:none;">
					<summary style="cursor:pointer;font-size:12px;color:#646970;">Log</summary>
					<pre id="wcpt-sync-log" style="
						font-size:11px;max-height:160px;overflow-y:auto;white-space:pre-wrap;
						background:#fff;border:1px solid #c3c4c7;padding:6px 8px;
						margin:6px 0 0;border-radius:3px;
					"></pre>
				</details>
			</div>

		</div>

		<script>
		(function () {
			'use strict';

			const secretInput = document.getElementById('wcpt-cron-secret-display');
			const cronUrlEl   = document.getElementById('wcpt-cron-url-example');
			const copyBtn     = document.getElementById('wcpt-copy-secret');
			const regenBtn    = document.getElementById('wcpt-regen-secret');
			const startBtn    = document.getElementById('wcpt-sync-start');
			const reindexBtn  = document.getElementById('wcpt-sync-reindex');
			const statusText  = document.getElementById('wcpt-sync-status-text');
			const logEl       = document.getElementById('wcpt-sync-log');
			const logDetails  = document.getElementById('wcpt-sync-log-details');

			const URL_START   = <?php echo wp_json_encode(rest_url('wc-product-tabs/v1/poster-sync/start')); ?>;
			const URL_STATUS  = <?php echo wp_json_encode(rest_url('wc-product-tabs/v1/poster-sync/status')); ?>;
			const URL_REINDEX = <?php echo wp_json_encode(rest_url('wc-product-tabs/v1/poster-sync/reindex-prices')); ?>;
			const REST_NONCE  = <?php echo wp_json_encode(wp_create_nonce('wp_rest')); ?>;

			function setStatus(msg, color) {
				statusText.textContent = msg;
				statusText.style.color = color || '#50575e';
			}

			function log(msg) {
				logDetails.style.display = '';
				logEl.textContent += '[' + new Date().toLocaleTimeString() + '] ' + msg + '\n';
				logEl.scrollTop = logEl.scrollHeight;
			}

			function setButtonsDisabled(disabled) {
				startBtn.disabled  = disabled;
				reindexBtn.disabled = disabled;
			}

			async function apiFetch(url) {
				const resp = await fetch(url, {
					headers: { 'X-WP-Nonce': REST_NONCE },
					credentials: 'same-origin',
				});
				if (!resp.ok) {
					const text = await resp.text();
					throw new Error('HTTP ' + resp.status + ' – ' + text.slice(0, 200));
				}
				return resp.json();
			}

			// ── Copy secret ──────────────────────────────────────────────────────
			copyBtn.addEventListener('click', function () {
				navigator.clipboard.writeText(secretInput.value).then(function () {
					copyBtn.textContent = '✓ Copied';
					setTimeout(function () { copyBtn.textContent = <?php echo wp_json_encode(WC_PT_I18n::get('cron_secret_copy')); ?>; }, 2000);
				});
			});

			// ── Regenerate secret ────────────────────────────────────────────────
			regenBtn.addEventListener('click', function () {
				if (!confirm('Regenerate the CRON secret? The old token will stop working once you save settings.')) return;
				const arr = new Uint8Array(16);
				crypto.getRandomValues(arr);
				const hex = Array.from(arr).map(b => b.toString(16).padStart(2, '0')).join('');
				secretInput.value = hex;
				// Keep the hidden form field in sync.
				const hidden = document.getElementById('wcpt-cron-secret-hidden');
				if (hidden) hidden.value = hex;
				// Update the example URL.
				cronUrlEl.textContent = URL_START.split('?')[0] + '?token=' + hex;
				alert('Token regenerated. Click "Save Changes" to persist it.');
			});

			// ── Start Sync ───────────────────────────────────────────────────────
			// Just kicks off the /start route. CRON handles batch processing
			// automatically every minute via /status — no polling needed here.
			startBtn.addEventListener('click', async function () {
				setButtonsDisabled(true);
				logEl.textContent = '';
				logDetails.style.display = 'none';
				setStatus(<?php echo wp_json_encode(WC_PT_I18n::get('sync_status_starting')); ?>);

				try {
					const data = await apiFetch(URL_START);
					log('Response: ' + JSON.stringify(data));
					if (!data.batch_total || data.batch_total === 0) {
						setStatus('Done. No products to update.', '#2a9d3e');
					} else {
						setStatus(
							'Sync started. ' + data.batch_total + ' batch(es) queued for ' +
							data.products_matched + ' product(s). CRON will process them automatically.',
							'#2a9d3e'
						);
					}
				} catch (err) {
					setStatus(<?php echo wp_json_encode(WC_PT_I18n::get('sync_status_error')); ?>.replace('%s', err.message), '#d63638');
					log('ERROR: ' + err.message);
				} finally {
					setButtonsDisabled(false);
				}
			});

			// ── Re-index Prices ──────────────────────────────────────────────────
			// Paginates through all pages synchronously (fast DB-only operation).
			reindexBtn.addEventListener('click', async function () {
				setButtonsDisabled(true);
				logEl.textContent = '';
				logDetails.style.display = 'none';
				setStatus(<?php echo wp_json_encode(WC_PT_I18n::get('sync_status_reindex_running')); ?>.replace('%d', 1));

				try {
					let page    = 1;
					let hasMore = true;

					while (hasMore) {
						const data = await apiFetch(URL_REINDEX + '?page=' + page + '&per_page=50');
						log('Page ' + page + ': ' + JSON.stringify(data));
						hasMore = data.has_more === true;
						if (hasMore) {
							page++;
							setStatus(<?php echo wp_json_encode(WC_PT_I18n::get('sync_status_reindex_running')); ?>.replace('%d', page));
						}
					}

					setStatus(<?php echo wp_json_encode(WC_PT_I18n::get('sync_status_reindex_done')); ?>, '#2a9d3e');
				} catch (err) {
					setStatus(<?php echo wp_json_encode(WC_PT_I18n::get('sync_status_error')); ?>.replace('%s', err.message), '#d63638');
					log('ERROR: ' + err.message);
				} finally {
					setButtonsDisabled(false);
				}
			});

			// ── Inject cron_secret into the settings form ─────────────────────────
			(function () {
				const form = document.querySelector('form[action="options.php"]');
				if (!form) return;
				const hidden = document.createElement('input');
				hidden.type  = 'hidden';
				hidden.id    = 'wcpt-cron-secret-hidden';
				hidden.name  = <?php echo wp_json_encode(self::OPTION_KEY . '[cron_secret]'); ?>;
				hidden.value = secretInput.value;
				form.appendChild(hidden);
			})();

			// ── Load recent sync log history on page load ─────────────────────────
			(async function loadRecentSyncLog() {
				try {
					const data = await apiFetch(URL_STATUS);
					if (data && Array.isArray(data.log) && data.log.length > 0) {
						logDetails.style.display = '';
						logEl.textContent = data.log.join('\n') + '\n';
						logEl.scrollTop = logEl.scrollHeight;
					}
					if (data && data.status === 'processing') {
						setStatus('Sync in progress: ' + (data.batch_done || 0) + '/' + (data.batch_total || 0) + ' batches...', '#2a9d3e');
					} else if (data && data.status === 'completed') {
						setStatus('Last sync completed: ' + (data.updated || 0) + ' updated, ' + (data.errors || 0) + ' errors.', '#50575e');
					}
				} catch (err) {}
			})();
		}());
		</script>

<?php
	}

	/**
	 * Return normalized plugin settings.
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings()
	{
		$defaults = $this->get_default_settings();
		$settings = get_option(self::OPTION_KEY, []);

		if (!is_array($settings)) {
			return $defaults;
		}

		$settings['cat_flakony']         = max(1, (int) ($settings['cat_flakony'] ?? $defaults['cat_flakony']));
		$settings['cat_zalyszky']        = max(1, (int) ($settings['cat_zalyszky'] ?? $defaults['cat_zalyszky']));
		$settings['cat_rozpyv']          = max(1, (int) ($settings['cat_rozpyv'] ?? $defaults['cat_rozpyv']));
		$settings['rozpyv_sizes']        = $this->parse_sizes_csv($settings['rozpyv_sizes'] ?? []);
		$settings['tabs_priority']       = $this->sanitize_tabs_priority($settings['tabs_priority'] ?? $defaults['tabs_priority']);
		$settings['atomizers']           = $this->normalize_atomizers($settings['atomizers'] ?? []);
		$settings['api_token']           = sanitize_text_field($settings['api_token'] ?? $defaults['api_token']);
		$settings['poster_api_token']    = sanitize_text_field($settings['poster_api_token'] ?? $defaults['poster_api_token']);
		$settings['notify_url']          = esc_url_raw((string) ($settings['notify_url'] ?? $defaults['notify_url']));
		$settings['cron_secret']         = sanitize_text_field((string) ($settings['cron_secret'] ?? ''));
		$settings['atomizers_file_hash'] = sanitize_text_field((string) ($settings['atomizers_file_hash'] ?? $defaults['atomizers_file_hash']));

		// Auto-generate a secret on first load if none has been saved yet.
		if ('' === $settings['cron_secret']) {
			$settings['cron_secret'] = wp_generate_password(32, false);
			$stored = get_option(self::OPTION_KEY, []);
			if (is_array($stored)) {
				$stored['cron_secret'] = $settings['cron_secret'];
				update_option(self::OPTION_KEY, $stored, false);
			}
		}

		if (empty($settings['rozpyv_sizes'])) {
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
	public function get_category_id($type)
	{
		$settings = $this->get_settings();
		$map = [
			'flakony' => 'cat_flakony',
			'zalyszky' => 'cat_zalyszky',
			'rozpyv' => 'cat_rozpyv',
		];

		if (!isset($map[$type])) {
			return 0;
		}

		return (int) $settings[$map[$type]];
	}

	/**
	 * Get allowed rozpyv sizes.
	 *
	 * @return int[]
	 */
	public function get_rozpyv_sizes()
	{
		$settings = $this->get_settings();
		return array_map('intval', (array) $settings['rozpyv_sizes']);
	}

	/**
	 * Get external notify API URL.
	 *
	 * @return string
	 */
	public function get_notify_url()
	{
		$settings = $this->get_settings();
		return (string) ($settings['notify_url'] ?? '');
	}

	/**
	 * Get API token for external access.
	 *
	 * @return string
	 */
	public function get_api_token()
	{
		$settings = $this->get_settings();
		return (string) ($settings['api_token'] ?? '');
	}

	/**
	 * Get CRON secret token used to authenticate external CRON requests.
	 *
	 * @return string
	 */
	public function get_cron_secret()
	{
		$settings = $this->get_settings();
		return (string) ($settings['cron_secret'] ?? '');
	}

	/**
	 * Get Poster POS API token.
	 *
	 * @return string
	 */
	public function get_poster_api_token()
	{
		$settings = $this->get_settings();
		return (string) ($settings['poster_api_token'] ?? '');
	}

	/**
	 * Get atomizers configuration from settings.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_atomizers()
	{
		$settings = $this->get_settings();
		return (array) $settings['atomizers'];
	}

	/**
	 * Get tabs priority used for UI and default selection.
	 *
	 * @return string[]
	 */
	public function get_tabs_priority()
	{
		$settings = $this->get_settings();
		return $this->sanitize_tabs_priority($settings['tabs_priority'] ?? []);
	}

	/**
	 * Get default settings values.
	 *
	 * @return array<string, mixed>
	 */
	private function get_default_settings()
	{
		return [
			'cat_flakony'        => self::DEFAULT_CAT_FLAKONY,
			'cat_zalyszky'       => self::DEFAULT_CAT_ZALYSZKY,
			'cat_rozpyv'         => self::DEFAULT_CAT_ROZPYV,
			'rozpyv_sizes'       => self::DEFAULT_ROZPYV_SIZES,
			'tabs_priority'      => self::DEFAULT_TABS_PRIORITY,
			'atomizers'          => [],
			'api_token'          => '',
			'poster_api_token'   => '',
			'notify_url'         => '',
			'cron_secret'        => '',
			'atomizers_file_hash' => '',
		];
	}

	/**
	 * Sanitize tabs priority value to unique known tab slugs.
	 *
	 * @param mixed $raw Raw priority value.
	 * @return string[]
	 */
	private function sanitize_tabs_priority($raw)
	{
		$allowed = ['flakony', 'rozpyv', 'zalyszky'];
		$items = is_array($raw) ? $raw : [$raw];

		$priority = [];
		foreach ($items as $item) {
			$key = sanitize_key((string) $item);
			if (in_array($key, $allowed, true) && !in_array($key, $priority, true)) {
				$priority[] = $key;
			}
		}

		foreach (self::DEFAULT_TABS_PRIORITY as $fallback) {
			if (!in_array($fallback, $priority, true)) {
				$priority[] = $fallback;
			}
		}

		return array_slice($priority, 0, 3);
	}

	/**
	 * Parse and sanitize sizes input into unique ascending integers.
	 *
	 * @param string|array<int|string> $raw Raw input value.
	 * @return int[]
	 */
	private function parse_sizes_csv($raw)
	{
		if (is_array($raw)) {
			$raw = implode(',', $raw);
		}

		$parts = array_map('trim', explode(',', (string) $raw));
		$parts = array_filter($parts, 'strlen');

		$sizes = [];
		foreach ($parts as $part) {
			$size = (int) $part;
			if ($size > 0) {
				$sizes[] = $size;
			}
		}

		$sizes = array_values(array_unique($sizes));
		sort($sizes, SORT_NUMERIC);

		return $sizes;
	}

	/**
	 * Normalize atomizers settings to a trusted runtime format.
	 *
	 * @param array<int, mixed> $atomizers Raw atomizers.
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize_atomizers($atomizers)
	{
		$normalized = [];

		foreach ((array) $atomizers as $item) {
			if (!is_array($item)) {
				continue;
			}

			$id = sanitize_key($item['id'] ?? '');
			$title = sanitize_text_field($item['title'] ?? '');

			if ('' === $id) {
				$id = sanitize_key(sanitize_title($title));
			}
			if ('' === $id) {
				continue;
			}

			$image = $this->sanitize_atomizer_image($item['image'] ?? '');
			$in_stock = true;
			if (array_key_exists('in_stock', $item)) {
				$in_stock = filter_var($item['in_stock'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
				$in_stock = null === $in_stock ? true : (bool) $in_stock;
			}

			$sizes_map = [];
			$size_images = [];

			if (isset($item['sizes']) && is_array($item['sizes'])) {
				foreach ($item['sizes'] as $size_raw => $size_config) {
					$size = (int) $size_raw;
					if ($size <= 0) {
						continue;
					}

					$price_raw = $size_config;
					if (is_array($size_config)) {
						$price_raw = $size_config['price'] ?? ($size_config['atomizer_price'] ?? 0);
						$size_image = $this->sanitize_atomizer_image($size_config['image'] ?? '');
						if ('' !== $size_image) {
							$size_images[(string) $size] = $size_image;
						}
					}

					$sizes_map[(string) $size] = (float) $price_raw;
				}
			}

			if (empty($sizes_map) && isset($item['prices']) && is_array($item['prices'])) {
				foreach ($item['prices'] as $size_raw => $price_raw) {
					$size = (int) $size_raw;
					if ($size <= 0) {
						continue;
					}
					$sizes_map[(string) $size] = (float) $price_raw;
				}
			}

			if ('' === $image && !empty($size_images)) {
				$image = (string) reset($size_images);
			}

			$sizes = $this->parse_sizes_csv($item['available_sizes'] ?? array_keys($sizes_map));
			if (empty($sizes) && !empty($sizes_map)) {
				$sizes = $this->parse_sizes_csv(array_keys($sizes_map));
			}

			foreach ($sizes as $size) {
				if (!isset($sizes_map[(string) $size])) {
					$sizes_map[(string) $size] = 0.0;
				}
			}

			if (empty($sizes_map) && !empty($sizes)) {
				foreach ($sizes as $size) {
					$sizes_map[(string) $size] = 0.0;
				}
			}

			ksort($sizes_map, SORT_NUMERIC);
			$available_sizes = $this->parse_sizes_csv(array_keys($sizes_map));

			$normalized[] = [
				'id' => $id,
				'title' => '' !== $title ? $title : $id,
				'image' => $image,
				'size_images' => $size_images,
				'in_stock' => $in_stock,
				'sizes' => $sizes_map,
				'available_sizes' => $available_sizes,
				'prices' => $sizes_map,
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
	private function get_atomizers_for_editor($atomizers)
	{
		$result = [];

		foreach ((array) $atomizers as $item) {
			if (!is_array($item)) {
				continue;
			}

			$sizes_map = [];
			if (isset($item['sizes']) && is_array($item['sizes'])) {
				$sizes_map = $item['sizes'];
			} elseif (isset($item['prices']) && is_array($item['prices'])) {
				$sizes_map = $item['prices'];
			}

			$size_images = [];
			if (isset($item['size_images']) && is_array($item['size_images'])) {
				foreach ($item['size_images'] as $size_raw => $image_raw) {
					$size = (int) $size_raw;
					if ($size <= 0) {
						continue;
					}
					$image = $this->sanitize_atomizer_image($image_raw);
					if ('' !== $image) {
						$size_images[(string) $size] = $image;
					}
				}
			}

			$normalized_sizes = [];
			foreach ($sizes_map as $size_raw => $price_raw) {
				$size = (int) $size_raw;
				if ($size <= 0) {
					continue;
				}

				$size_key = (string) $size;
				$price = (float) $price_raw;
				if (isset($size_images[$size_key])) {
					$normalized_sizes[$size_key] = [
						'price' => $price,
						'image' => $size_images[$size_key],
					];
				} else {
					$normalized_sizes[$size_key] = $price;
				}
			}

			ksort($normalized_sizes, SORT_NUMERIC);

			$result[] = [
				'id' => sanitize_key($item['id'] ?? ''),
				'title' => sanitize_text_field($item['title'] ?? ''),
				'image' => $this->sanitize_atomizer_image($item['image'] ?? ''),
				'in_stock' => array_key_exists('in_stock', $item) ? (bool) $item['in_stock'] : true,
				'sizes' => $normalized_sizes,
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
	private function sanitize_atomizer_image($value)
	{
		$image = trim((string) $value);
		if ('' === $image) {
			return '';
		}

		if (preg_match('#^(https?:)?//#i', $image)) {
			return esc_url_raw($image);
		}

		if (0 === strpos($image, 'data:image/')) {
			return $image;
		}

		return sanitize_file_name($image);
	}
}
