<?php
/**
 * Plugin Settings Manager.
 *
 * Provides a classic WordPress Settings API options screen under WooCommerce menu.
 *
 * @package HDWCGallery\Admin
 */

declare(strict_types=1);

namespace HDWCGallery\Admin;

use HDWCGallery\Admin\Settings\AutoUpdateSection;

defined( 'ABSPATH' ) || exit;

final class Settings {
	public const OPTION_KEY = 'hd_wc_gallery_settings';

	/** Inline CSS for the settings page. */
	private const ADMIN_CSS = '
		.hdwcg-tab-content { display: none; }
		.hdwcg-tab-content.active { display: block; }
		.hdwcg-settings .nav-tab-wrapper { margin-bottom: 20px; }
		.hdwcg-channel-block { border: 1px solid #c3c4c7; padding: 16px 20px; margin-bottom: 12px; border-radius: 4px; background: #f9f9f9; }
		.hdwcg-channel-block h3 { margin: 0 0 10px; font-size: 14px; display: flex; align-items: center; gap: 8px; }

		/* Auto-Update Vault Styling inside hdwcg-channel-block */
		.hdwcg-channel-block .hdwcg-vault-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 12px; }
		.hdwcg-channel-block .hdwcg-vault-header-left { display: flex; align-items: center; gap: 12px; }
		.hdwcg-channel-block .hdwcg-vault-icon { width: 38px; height: 38px; background: #0f172a; color: #fff; border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
		.hdwcg-channel-block .hdwcg-vault-icon svg { width: 20px; height: 20px; stroke: currentColor; fill: none; }
		.hdwcg-channel-block .hdwcg-vault-title { margin: 0 !important; font-size: 15px !important; font-weight: 600 !important; color: #0f172a !important; line-height: 1.3 !important; }
		.hdwcg-channel-block .hdwcg-vault-subtitle { margin: 3px 0 0 !important; font-size: 12.5px !important; color: #64748b !important; line-height: 1.4 !important; }
		.hdwcg-channel-block .hdwcg-status-indicator { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 9999px; font-size: 12px; font-weight: 600; }
		.hdwcg-channel-block .hdwcg-status-indicator.active { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
		.hdwcg-channel-block .hdwcg-status-indicator.active .hdwcg-status-dot { width: 8px; height: 8px; border-radius: 50%; background: #10b981; box-shadow: 0 0 0 2px rgba(16,185,129,0.25); }
		.hdwcg-channel-block .hdwcg-status-indicator.inactive { background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; }
		.hdwcg-channel-block .hdwcg-status-indicator.inactive .hdwcg-status-dot { width: 8px; height: 8px; border-radius: 50%; background: #94a3b8; }
		.hdwcg-channel-block .hdwcg-status-source { font-size: 11px; font-weight: 500; opacity: 0.85; }

		.hdwcg-channel-block .hdwcg-token-active-view { display: flex; justify-content: space-between; align-items: center; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 18px; flex-wrap: wrap; gap: 16px; box-shadow: 0 1px 2px rgba(0,0,0,0.02); }
		.hdwcg-channel-block .hdwcg-token-key-display .hdwcg-key-label { display: block; font-size: 10.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: #64748b; margin-bottom: 4px; }
		.hdwcg-channel-block .hdwcg-token-key-display .hdwcg-key-hash { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 15px; font-weight: 600; color: #0f172a; letter-spacing: 0.08em; }
		.hdwcg-channel-block .hdwcg-token-key-display .hdwcg-key-specs { display: flex; gap: 14px; margin-top: 6px; font-size: 11.5px; color: #64748b; }
		.hdwcg-channel-block .hdwcg-spec-item { display: inline-flex; align-items: center; gap: 4px; }
		.hdwcg-channel-block .hdwcg-spec-item .dashicons { font-size: 14px; width: 14px; height: 14px; }
		.hdwcg-channel-block .hdwcg-token-active-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

		/* Semantic Action Buttons */
		.hdwcg-channel-block .btn-action-primary { display: inline-flex; align-items: center; justify-content: center; height: 32px; padding: 0 14px; font-size: 12.5px; font-weight: 600; color: #1d4ed8 !important; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 6px; text-decoration: none !important; cursor: pointer; transition: all 0.15s ease; box-shadow: 0 1px 2px rgba(29,78,216,0.05); }
		.hdwcg-channel-block .btn-action-primary:hover { background: #dbeafe !important; border-color: #93c5fd !important; color: #1e40af !important; transform: translateY(-1px); box-shadow: 0 2px 4px rgba(29,78,216,0.12); }

		.hdwcg-channel-block .btn-action-neutral { display: inline-flex; align-items: center; justify-content: center; height: 32px; padding: 0 14px; font-size: 12.5px; font-weight: 500; color: #334155 !important; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; text-decoration: none !important; cursor: pointer; transition: all 0.15s ease; box-shadow: 0 1px 2px rgba(0,0,0,0.03); }
		.hdwcg-channel-block .btn-action-neutral:hover { background: #f8fafc !important; border-color: #94a3b8 !important; color: #0f172a !important; }

		.hdwcg-channel-block .btn-action-danger { display: inline-flex; align-items: center; justify-content: center; height: 32px; padding: 0 12px; font-size: 12.5px; font-weight: 500; color: #dc2626 !important; background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; text-decoration: none !important; cursor: pointer; transition: all 0.15s ease; }
		.hdwcg-channel-block .btn-action-danger:hover { background: #fee2e2 !important; border-color: #fca5a5 !important; color: #991b1b !important; }

		.hdwcg-channel-block .hdwcg-token-edit-view .hdwcg-setup-guide { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 12px 16px; margin-bottom: 14px; }
		.hdwcg-channel-block .hdwcg-setup-guide p { margin: 0; color: #166534; font-size: 13px; font-weight: 500; line-height: 1.5; }
		.hdwcg-channel-block .hdwcg-token-edit-view .hdwcg-edit-notice { display: flex; align-items: center; gap: 8px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 10px 14px; margin-bottom: 14px; color: #1e40af; font-size: 13px; font-weight: 500; }
		.hdwcg-channel-block .hdwcg-input-action-card .hdwcg-input-label { display: block; font-size: 13px; font-weight: 600; color: #0f172a; margin-bottom: 6px; }
		.hdwcg-channel-block .hdwcg-token-input-wrapper { position: relative; max-width: 520px; }
		.hdwcg-channel-block .hdwcg-token-input-wrapper .hdwcg-input-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 16px; width: 16px; height: 16px; pointer-events: none; z-index: 2; }
		.hdwcg-channel-block .hdwcg-token-input-wrapper input { width: 100%; padding: 6px 12px 6px 36px !important; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 13px; border: 1px solid #cbd5e1; border-radius: 6px; background: #fff; height: 36px; box-sizing: border-box; }
		.hdwcg-channel-block .hdwcg-token-input-wrapper input:focus { border-color: #2563eb; box-shadow: 0 0 0 2px rgba(37,99,235,0.2); outline: none; }
		.hdwcg-channel-block .hdwcg-input-actions-row { display: flex; align-items: center; gap: 8px; margin-top: 14px; flex-wrap: wrap; }

		.hdwcg-channel-block .btn-action-submit { display: inline-flex; align-items: center; justify-content: center; height: 34px; padding: 0 16px; font-size: 13px; font-weight: 600; color: #ffffff !important; background: #2271b1 !important; border: 1px solid #2271b1 !important; border-radius: 6px !important; cursor: pointer; box-shadow: 0 1px 2px rgba(34,113,177,0.25); transition: all 0.15s ease !important; }
		.hdwcg-channel-block .btn-action-submit:hover { background: #135e96 !important; border-color: #135e96 !important; transform: translateY(-1px); box-shadow: 0 2px 4px rgba(34,113,177,0.35); }

		.hdwcg-channel-block .btn-action-cancel { display: inline-flex; align-items: center; justify-content: center; height: 34px; padding: 0 12px; font-size: 13px; font-weight: 500; color: #475569 !important; background: transparent !important; border: 1px solid #cbd5e1 !important; border-radius: 6px; cursor: pointer; transition: all 0.15s ease; }
		.hdwcg-channel-block .btn-action-cancel:hover { background: #f1f5f9 !important; border-color: #94a3b8 !important; color: #0f172a !important; }
	';

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'addSettingsPage' ] );
		add_action( 'admin_init', [ self::class, 'registerSettings' ] );
	}

	/**
	 * Add settings submenu under WooCommerce.
	 */
	public static function addSettingsPage(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Product Gallery Settings', 'hd-wc-gallery' ),
			__( 'Product Gallery', 'hd-wc-gallery' ),
			'manage_options',
			'hd-wc-gallery-settings',
			[ self::class, 'renderSettingsPage' ]
		);
	}

	/**
	 * Render settings page content.
	 */
	public static function renderSettingsPage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tabs = [
			'general' => __( 'General', 'hd-wc-gallery' ),
			'update'  => __( 'Auto-Update', 'hd-wc-gallery' ),
		];

		?>
		<style><?php echo self::ADMIN_CSS; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></style>
		<div class="wrap hdwcg-settings">
			<h1><?php esc_html_e( 'Product Gallery Settings', 'hd-wc-gallery' ); ?></h1>

			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="#" class="nav-tab<?php echo 'general' === $slug ? ' nav-tab-active' : ''; ?>" data-tab="hdwcg-tab-<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_KEY ); ?>

				<div class="hdwcg-tab-content active" id="hdwcg-tab-general">
					<?php do_settings_sections( 'hd-wc-gallery-settings' ); ?>
				</div>

				<?php AutoUpdateSection::renderTab(); ?>

				<?php submit_button(); ?>
			</form>
		</div>

		<script>
		(function() {
			const wrap = document.querySelector('.hdwcg-settings');
			if (!wrap) return;

			function activateTab(tabId) {
				if (!tabId) return;
				const tab = wrap.querySelector('.nav-tab[data-tab="' + tabId + '"]');
				const content = wrap.querySelector('#' + tabId);
				const submitBtn = wrap.querySelector('p.submit');
				if (tab && content) {
					wrap.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('nav-tab-active'));
					wrap.querySelectorAll('.hdwcg-tab-content').forEach(c => c.classList.remove('active'));
					tab.classList.add('nav-tab-active');
					content.classList.add('active');
					if (submitBtn) {
						submitBtn.style.display = tabId === 'hdwcg-tab-update' ? 'none' : 'block';
					}
				}
			}

			const initialHash = window.location.hash.replace(/^#/, '');
			if (initialHash) {
				activateTab(initialHash);
			}

			wrap.querySelectorAll('.nav-tab').forEach(tab => {
				tab.addEventListener('click', e => {
					e.preventDefault();
					const targetId = tab.dataset.tab;
					activateTab(targetId);
					if (history.replaceState) {
						history.replaceState(null, '', '#' + targetId);
					} else {
						window.location.hash = targetId;
					}
				});
			});

			// Auto-Update Vault Handlers
			const vaultRestUrl   = <?php echo wp_json_encode( esc_url_raw( rest_url( 'hd-wc-gallery/v1/settings/github-token' ) ) ); ?>;
			const vaultNonce     = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
			const saveTokenBtn   = document.getElementById('hdwcg-save-token');
			const deleteTokenBtn = document.getElementById('hdwcg-delete-token');
			const editTokenBtn   = document.getElementById('hdwcg-btn-edit-token');
			const cancelTokenBtn = document.getElementById('hdwcg-btn-cancel-edit');
			const tokenInput     = document.getElementById('hdwcg-github-token');
			const activeView     = document.getElementById('hdwcg-token-active-view');
			const editView       = document.getElementById('hdwcg-token-edit-view');

			if (editTokenBtn) {
				editTokenBtn.addEventListener('click', function() {
					if (activeView) activeView.style.display = 'none';
					if (editView) editView.style.display = 'block';
					if (tokenInput) tokenInput.focus();
				});
			}

			if (cancelTokenBtn) {
				cancelTokenBtn.addEventListener('click', function() {
					if (editView) editView.style.display = 'none';
					if (activeView) activeView.style.display = 'flex';
				});
			}

			if (saveTokenBtn) {
				saveTokenBtn.addEventListener('click', async function() {
					const token = tokenInput ? tokenInput.value.trim() : '';
					if (!token) {
						alert(<?php echo wp_json_encode( __( 'Please enter a valid access token.', 'hd-wc-gallery' ) ); ?>);
						return;
					}
					saveTokenBtn.disabled = true;
					try {
						const res = await fetch(vaultRestUrl, {
							method: 'POST',
							headers: {
								'Content-Type': 'application/json',
								'X-WP-Nonce': vaultNonce,
							},
							body: JSON.stringify({ token: token })
						});
						const data = await res.json();
						alert(data.message || <?php echo wp_json_encode( __( 'Access token saved securely.', 'hd-wc-gallery' ) ); ?>);
						window.location.reload();
					} catch (err) {
						alert(<?php echo wp_json_encode( __( 'Error saving access token.', 'hd-wc-gallery' ) ); ?>);
					} finally {
						saveTokenBtn.disabled = false;
					}
				});
			}

			if (deleteTokenBtn) {
				deleteTokenBtn.addEventListener('click', async function() {
					if (!confirm(<?php echo wp_json_encode( __( 'Are you sure you want to remove the stored access token?', 'hd-wc-gallery' ) ); ?>)) {
						return;
					}
					deleteTokenBtn.disabled = true;
					try {
						const res = await fetch(vaultRestUrl, {
							method: 'DELETE',
							headers: {
								'X-WP-Nonce': vaultNonce,
							}
						});
						const data = await res.json();
						alert(data.message || <?php echo wp_json_encode( __( 'Access token removed.', 'hd-wc-gallery' ) ); ?>);
						window.location.reload();
					} catch (err) {
						alert(<?php echo wp_json_encode( __( 'Error removing access token.', 'hd-wc-gallery' ) ); ?>);
					} finally {
						deleteTokenBtn.disabled = false;
					}
				});
			}
		})();
		</script>
		<?php
	}

	/**
	 * Register settings, sections, and fields.
	 */
	public static function registerSettings(): void {
		register_setting(
			self::OPTION_KEY,
			self::OPTION_KEY,
			[
				'type'              => 'array',
				'sanitize_callback' => [ self::class, 'sanitizeSettings' ],
				'default'           => self::getDefaults(),
			]
		);

		add_settings_section(
			'hd_wc_gallery_general',
			__( 'General Settings', 'hd-wc-gallery' ),
			'__return_empty_string',
			'hd-wc-gallery-settings'
		);

		$fields = self::getFieldsConfig();
		foreach ( $fields as $key => $field ) {
			add_settings_field(
				$key,
				$field['label'],
				[ self::class, 'renderField' ],
				'hd-wc-gallery-settings',
				'hd_wc_gallery_general',
				[
					'key'   => $key,
					'field' => $field,
				]
			);
		}
	}

	/**
	 * Sanitize fields before saving.
	 */
	public static function sanitizeSettings( array $input ): array {
		$output = [];
		$fields = self::getFieldsConfig();

		foreach ( $fields as $key => $field ) {
			if ( ! isset( $input[ $key ] ) ) {
				if ( 'toggle' === $field['type'] ) {
					$output[ $key ] = false;
				} else {
					$output[ $key ] = $field['default'] ?? '';
				}
				continue;
			}

			$val = $input[ $key ];
			if ( 'toggle' === $field['type'] ) {
				$output[ $key ] = (bool) $val;
			} elseif ( 'number' === $field['type'] ) {
				$output[ $key ] = is_numeric( $val ) ? (float) $val : ( $field['default'] ?? 0 );
			} elseif ( 'select' === $field['type'] && isset( $field['options'] ) ) {
				$valStr         = (string) $val;
				$output[ $key ] = array_key_exists( $valStr, $field['options'] ) ? $valStr : ( $field['default'] ?? '' );
			} else {
				$output[ $key ] = sanitize_text_field( (string) $val );
			}
		}

		return $output;
	}

	/**
	 * Render settings input fields.
	 */
	public static function renderField( array $args ): void {
		$key      = $args['key'];
		$field    = $args['field'];
		$options  = self::getOptions();
		$current  = $options[ $key ] ?? ( $field['default'] ?? '' );
		$nameAttr = self::OPTION_KEY . '[' . esc_attr( $key ) . ']';

		if ( 'toggle' === $field['type'] ) {
			printf(
				'<input type="checkbox" name="%s" value="1" %s />',
				esc_attr( $nameAttr ),
				checked( (bool) $current, true, false )
			);
		} elseif ( 'select' === $field['type'] ) {
			printf( '<select name="%s">', esc_attr( $nameAttr ) );
			foreach ( $field['options'] as $val => $label ) {
				printf(
					'<option value="%s" %s>%s</option>',
					esc_attr( (string) $val ),
					selected( (string) $current, (string) $val, false ),
					esc_html( $label )
				);
			}
			echo '</select>';
		} elseif ( 'number' === $field['type'] ) {
			printf(
				'<input type="number" name="%s" value="%s" min="%s" max="%s" step="%s" class="small-text" />',
				esc_attr( $nameAttr ),
				esc_attr( (string) $current ),
				esc_attr( (string) ( $field['min'] ?? '' ) ),
				esc_attr( (string) ( $field['max'] ?? '' ) ),
				esc_attr( (string) ( $field['step'] ?? '' ) )
			);
		}

		if ( ! empty( $field['help'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $field['help'] ) );
		}
	}

	/**
	 * Retrieve all plugin options (cached or get_option).
	 */
	public static function getOptions(): array {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}

		$options = get_option( self::OPTION_KEY, [] );
		$cached  = array_merge( self::getDefaults(), is_array( $options ) ? $options : [] );

		return $cached;
	}

	/**
	 * Retrieve single option.
	 */
	public static function getOption( string $key, mixed $defaultValue = null ): mixed {
		$options = self::getOptions();
		return $options[ $key ] ?? $defaultValue;
	}

	/**
	 * Settings Fields definition.
	 */
	public static function getFieldsConfig(): array {
		return [
			'gallery_layout'            => [
				'type'    => 'select',
				'label'   => __( 'Gallery Layout', 'hd-wc-gallery' ),
				'options' => [
					'below'   => __( 'Slider — Thumbs Below', 'hd-wc-gallery' ),
					'left'    => __( 'Slider — Thumbs Left', 'hd-wc-gallery' ),
					'right'   => __( 'Slider — Thumbs Right', 'hd-wc-gallery' ),
					'stacked' => __( 'Stacked (2-Column Grid, no slider)', 'hd-wc-gallery' ),
				],
				'default' => 'below',
			],
			'gallery_object_fit'        => [
				'type'    => 'select',
				'label'   => __( 'Object Fit', 'hd-wc-gallery' ),
				'options' => [
					'contain' => __( 'Contain (keep full image, transparent bounds)', 'hd-wc-gallery' ),
					'cover'   => __( 'Cover (fill frame, crop edges)', 'hd-wc-gallery' ),
				],
				'default' => 'contain',
			],
			'gallery_nav_arrows'        => [
				'type'    => 'toggle',
				'label'   => __( 'Show Navigation Arrows', 'hd-wc-gallery' ),
				'default' => true,
				'help'    => __( 'Show prev/next arrows on main gallery slider', 'hd-wc-gallery' ),
			],
			'gallery_pagination'        => [
				'type'    => 'toggle',
				'label'   => __( 'Show Mobile Pagination', 'hd-wc-gallery' ),
				'default' => true,
				'help'    => __( 'Show mobile pagination dots on slider', 'hd-wc-gallery' ),
			],
			'gallery_zoom'              => [
				'type'    => 'toggle',
				'label'   => __( 'Enable Hover Zoom', 'hd-wc-gallery' ),
				'default' => true,
				'help'    => __( 'Enable cursor-tracking hover zoom on main product image', 'hd-wc-gallery' ),
			],
			'gallery_zoom_scale'        => [
				'type'    => 'number',
				'label'   => __( 'Zoom Scale', 'hd-wc-gallery' ),
				'default' => 2.0,
				'min'     => 1.2,
				'max'     => 4.0,
				'step'    => 0.1,
				'help'    => __( 'Zoom magnification multiplier (1.2x – 4.0x)', 'hd-wc-gallery' ),
			],
			'gallery_lens_mode'         => [
				'type'    => 'select',
				'label'   => __( 'Lens Mode', 'hd-wc-gallery' ),
				'options' => [
					'inner'  => __( 'Inner (smooth inner image bounds zoom)', 'hd-wc-gallery' ),
					'circle' => __( 'Circle (magnifying glass lens)', 'hd-wc-gallery' ),
				],
				'default' => 'inner',
			],
			'gallery_lens_size'         => [
				'type'    => 'number',
				'label'   => __( 'Lens Size (px)', 'hd-wc-gallery' ),
				'default' => 150,
				'min'     => 80,
				'max'     => 400,
				'step'    => 10,
				'help'    => __( 'Lens diameter in pixels (only used for Circle mode)', 'hd-wc-gallery' ),
			],
			'gallery_lightbox'          => [
				'type'    => 'toggle',
				'label'   => __( 'Enable Lightbox', 'hd-wc-gallery' ),
				'default' => true,
				'help'    => __( 'Enable modern touch-friendly PhotoSwipe v5 fullscreen lightbox', 'hd-wc-gallery' ),
			],
			'gallery_lightbox_thumbs'   => [
				'type'    => 'toggle',
				'label'   => __( 'Lightbox Thumbnail Strip', 'hd-wc-gallery' ),
				'default' => true,
				'help'    => __( 'Show interactive thumbnail strip at the bottom of fullscreen lightbox', 'hd-wc-gallery' ),
			],
			'gallery_video_autoplay'    => [
				'type'    => 'toggle',
				'label'   => __( 'Video Autoplay', 'hd-wc-gallery' ),
				'default' => true,
				'help'    => __( 'Automatically play video when active slide transitions into view', 'hd-wc-gallery' ),
			],
			'gallery_product_video_pos' => [
				'type'    => 'select',
				'label'   => __( 'Product Video Position', 'hd-wc-gallery' ),
				'options' => [
					'first_slide' => __( 'First Slide (Hero)', 'hd-wc-gallery' ),
					'last_slide'  => __( 'Last Slide', 'hd-wc-gallery' ),
					'overlay'     => __( 'Floating Overlay Button', 'hd-wc-gallery' ),
				],
				'default' => 'first_slide',
			],
			'gallery_variation_mode'    => [
				'type'    => 'select',
				'label'   => __( 'Variation Gallery Mode', 'hd-wc-gallery' ),
				'options' => [
					'replace' => __( 'Replace — show only variation images', 'hd-wc-gallery' ),
					'prepend' => __( 'Prepend — variation images first, then product gallery', 'hd-wc-gallery' ),
				],
				'default' => 'replace',
			],
			'gallery_thumbs_mobile'     => [
				'type'    => 'number',
				'label'   => __( 'Thumbnail Count - Mobile', 'hd-wc-gallery' ),
				'default' => 3,
				'min'     => 0,
				'max'     => 6,
				'help'    => __( 'Number of visible thumbnails on mobile (0 = auto)', 'hd-wc-gallery' ),
			],
			'gallery_thumbs_tablet'     => [
				'type'    => 'number',
				'label'   => __( 'Thumbnail Count - Tablet', 'hd-wc-gallery' ),
				'default' => 4,
				'min'     => 0,
				'max'     => 8,
				'help'    => __( 'Number of visible thumbnails on tablet (0 = auto)', 'hd-wc-gallery' ),
			],
			'gallery_thumbs_desktop'    => [
				'type'    => 'number',
				'label'   => __( 'Thumbnail Count - Desktop', 'hd-wc-gallery' ),
				'default' => 5,
				'min'     => 0,
				'max'     => 10,
				'help'    => __( 'Number of visible thumbnails on desktop (0 = auto)', 'hd-wc-gallery' ),
			],
		];
	}

	/**
	 * Get default settings values.
	 */
	public static function getDefaults(): array {
		$defaults = [];
		foreach ( self::getFieldsConfig() as $key => $field ) {
			$defaults[ $key ] = $field['default'] ?? '';
		}
		return $defaults;
	}
}
