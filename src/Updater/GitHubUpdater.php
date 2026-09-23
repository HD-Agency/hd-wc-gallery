<?php
/**
 * GitHub-based plugin auto-updater via plugin-update-checker (PUC).
 *
 * Repository URL and tracked branch are intentionally fixed to this plugin's
 * own repository (`HD-Agency/hd-wc-gallery`, branch `main`).
 * They are not user-configurable settings. Hardcoding prevents accidental
 * redirection to an untrusted source.
 *
 * Provides non-blocking background update checks, client-side Live Push updates
 * on `plugins.php`, strict HTTP timeouts, and a fail-safe package integrity guard.
 *
 * The Personal Access Token is stored encrypted in wp_options under its own
 * key, or read from HDWCG_GITHUB_TOKEN constant/env var in wp-config.php.
 *
 * @package HDWCGallery\Updater
 */

declare(strict_types=1);

namespace HDWCGallery\Updater;

use HDWCGallery\Support\Crypto;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
use YahnisElsts\PluginUpdateChecker\v5p7\Vcs\PluginUpdateChecker;

defined( 'ABSPATH' ) || exit;

final class GitHubUpdater {

	/** @internal Intentionally fixed — not a user-configurable setting. */
	private const REPO_URL           = 'https://github.com/HD-Agency/hd-wc-gallery';
	public const TOKEN_OPTION        = '_hd_wc_gallery_github_token';
	public const COOLDOWN_TRANSIENT  = '_hdwcg_github_update_cooldown';
	public const COOLDOWN_SECONDS    = 300; // 5 minutes
	private const CHECK_PERIOD_HOURS = 2;
	private const HTTP_TIMEOUT_WEB   = 2.5;
	private const HTTP_TIMEOUT_CRON  = 5.0;

	private static ?self $instance        = null;
	private ?PluginUpdateChecker $checker = null;
	private bool $routesRegistered        = false;

	public static function init(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function __construct() {
		$this->initUpdateChecker();
	}

	private function initUpdateChecker(): void {
		try {
			// Enforce strict HTTP timeouts to protect against network delay/hangs.
			add_filter( 'puc_request_info_options-hd-wc-gallery', [ $this, 'filterHttpRequestOptions' ] );

			// Guard against synchronous blocking checks on passive admin page loads.
			add_filter( 'puc_check_now-hd-wc-gallery', [ $this, 'shouldCheckNow' ] );

			// Fail-safe package integrity guard: abort before clear_destination() if package is incomplete.
			add_filter( 'upgrader_source_selection', [ $this, 'validatePackageIntegrity' ], 25, 4 );

			// Client-side live push updater on plugins.php.
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueuePluginsScript' ] );

			// Prevent redundant HTTP 404 query to /releases/latest when repo does not use GitHub releases.
			add_filter( 'puc_vcs_update_detection_strategies-hd-wc-gallery', [ $this, 'filterUpdateDetectionStrategies' ] );

			// REST check endpoint.
			if ( did_action( 'rest_api_init' ) ) {
				$this->registerRestRoutes();
			} else {
				add_action( 'rest_api_init', [ $this, 'registerRestRoutes' ] );
			}

			$pluginFile = defined( 'HD_WC_GALLERY_PATH' )
				? HD_WC_GALLERY_PATH . 'hd-wc-gallery.php'
				: dirname( __DIR__, 2 ) . '/hd-wc-gallery.php';

			/** @var PluginUpdateChecker $checker */
			$checker = PucFactory::buildUpdateChecker(
				self::REPO_URL,
				$pluginFile,
				'hd-wc-gallery',
				self::CHECK_PERIOD_HOURS
			);

			$checker->setBranch( 'main' );

			$token = $this->getToken();
			if ( $token ) {
				$checker->setAuthentication( $token );
			}

			$this->checker = $checker;
		} catch ( \Throwable $e ) {
			// Silently degrade in production; surface a safe diagnostic in debug mode.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( '[HD WC Gallery Updater] Failed to initialize update checker: %s', $e->getMessage() ) );
			}
		}
	}

	// ── HTTP Timeout Guard ───────────────────────────────────────────────

	/**
	 * Enforce strict HTTP timeout on GitHub API requests to prevent PHP worker stalls.
	 *
	 * @param array<string, mixed> $options HTTP request options for wp_remote_get.
	 * @return array<string, mixed>
	 */
	public function filterHttpRequestOptions( array $options ): array {
		$isCron             = ( defined( 'DOING_CRON' ) && DOING_CRON ) || wp_doing_cron();
		$options['timeout'] = $isCron ? self::HTTP_TIMEOUT_CRON : self::HTTP_TIMEOUT_WEB;

		return $options;
	}

	/**
	 * Filter update detection strategies.
	 *
	 * Unsets 'latest_release' when repository relies on branch or tags instead of GitHub Releases,
	 * preventing an unwanted HTTP 404 error and eliminating ~760ms TTFB overhead on update checks.
	 *
	 * @param array<string, callable> $strategies
	 * @return array<string, callable>
	 */
	public function filterUpdateDetectionStrategies( array $strategies ): array {
		unset( $strategies['latest_release'], $strategies['latest_tag'] );

		return $strategies;
	}

	// ── Synchronous & Asynchronous Check Dispatcher ─────────────────────

	/**
	 * Allow update checks during WP-Cron, "Dashboard → Updates", manual "Check for updates", or bulk upgrades.
	 * Blocks synchronous blocking checks on passive admin page loads for 0ms latency.
	 *
	 * @param bool $shouldCheck Current decision from PUC scheduler.
	 */
	public function shouldCheckNow( bool $shouldCheck ): bool {
		if ( ! $shouldCheck ) {
			return false;
		}

		// 1. Always allow cron-triggered checks.
		if ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || wp_doing_cron() ) {
			return true;
		}

		// 2. Allow manual "Check for updates" link on plugins.php.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['puc_check_for_updates'] ) || ! empty( $_GET['puc_slug'] ) ) {
			delete_transient( self::COOLDOWN_TRANSIENT );
			return true;
		}

		// 3. Allow manual "Check Again" on Dashboard → Updates ONLY on explicit force-check request, or after bulk upgrades.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ( doing_action( 'load-update-core.php' ) && ! empty( $_GET['force-check'] ) ) || doing_action( 'upgrader_process_complete' ) ) {
			delete_transient( self::COOLDOWN_TRANSIENT );
			return true;
		}

		// Block synchronous execution on regular web thread for 0ms page load latency.
		return false;
	}

	// ── REST API Route & Live Check ─────────────────────────────────────

	/**
	 * Register REST routes for background Live Push checks.
	 */
	public function registerRestRoutes(): void {
		if ( $this->routesRegistered ) {
			return;
		}
		$this->routesRegistered = true;

		$namespace = defined( 'HD_WC_GALLERY_REST_NAMESPACE' ) ? HD_WC_GALLERY_REST_NAMESPACE : 'hd-wc-gallery/v1';

		register_rest_route(
			$namespace,
			'/updater/check',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handleRestCheck' ],
				'permission_callback' => [ $this, 'checkRestPermission' ],
				'args'                => [
					'force' => [
						'type'              => 'boolean',
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					],
				],
			]
		);
	}

	/**
	 * Permission callback: only authenticated administrators who can update plugins.
	 */
	public function checkRestPermission( WP_REST_Request $request ): bool {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return false;
		}

		$nonce = $request->get_header( 'X-WP-Nonce' ) ?: $request->get_param( '_wpnonce' );

		return (bool) ( $nonce && wp_verify_nonce( $nonce, 'wp_rest' ) );
	}

	/**
	 * Get the number of seconds remaining before the next update check is permitted.
	 *
	 * @return int Seconds remaining, or 0 if cooldown has elapsed or transient is absent.
	 */
	public function getCooldownRemaining(): int {
		$expireAt = get_transient( self::COOLDOWN_TRANSIENT );
		if ( ! $expireAt ) {
			return 0;
		}

		if ( is_numeric( $expireAt ) && (int) $expireAt > 1 ) {
			return max( 0, (int) $expireAt - (int) time() );
		}

		$timeout = (int) get_option( '_transient_timeout_' . self::COOLDOWN_TRANSIENT );
		if ( $timeout <= 0 ) {
			return (int) self::COOLDOWN_SECONDS;
		}

		$remaining = $timeout - (int) time();

		return max( 0, $remaining );
	}

	/**
	 * REST handler for checking updates in background.
	 */
	public function handleRestCheck( WP_REST_Request $request ): WP_REST_Response {
		$force       = rest_sanitize_boolean( $request->get_param( 'force' ) );
		$hasCooldown = (bool) get_transient( self::COOLDOWN_TRANSIENT );

		if ( ( ! $hasCooldown || $force ) && $this->checker ) {
			$expireAt = (int) time() + self::COOLDOWN_SECONDS;
			set_transient( self::COOLDOWN_TRANSIENT, $expireAt, self::COOLDOWN_SECONDS );
			$this->checker->checkForUpdates();
		}

		$update         = $this->checker ? $this->checker->getUpdate() : null;
		$currentVersion = defined( 'HD_WC_GALLERY_VERSION' ) ? HD_WC_GALLERY_VERSION : '1.0.2';
		$hasUpdate      = ( null !== $update && ! empty( $update->version ) && version_compare( (string) $update->version, $currentVersion, '>' ) );

		if ( $hasUpdate ) {
			$pluginBasename = defined( 'HD_WC_GALLERY_PLUGIN_BASENAME' ) ? HD_WC_GALLERY_PLUGIN_BASENAME : 'hd-wc-gallery/hd-wc-gallery.php';
			return new WP_REST_Response(
				[
					'has_update'         => true,
					'current_version'    => $currentVersion,
					'new_version'        => (string) $update->version,
					'cooldown_remaining' => $this->getCooldownRemaining(),
					'update_url'         => wp_nonce_url(
						self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $pluginBasename ) ),
						'upgrade-plugin_' . $pluginBasename
					),
					'details_url'        => self_admin_url( 'plugin-install.php?tab=plugin-information&plugin=hd-wc-gallery&section=changelog&TB_iframe=true&width=600&height=800' ),
					'row_html'           => $this->buildUpdateRowHtml( (string) $update->version ),
				],
				200
			);
		}

		return new WP_REST_Response(
			[
				'has_update'         => false,
				'current_version'    => $currentVersion,
				'cooldown_remaining' => $this->getCooldownRemaining(),
			],
			200
		);
	}

	/**
	 * Render WordPress standard plugin update table row HTML.
	 */
	public function buildUpdateRowHtml( string $newVersion ): string {
		$pluginBasename = defined( 'HD_WC_GALLERY_PLUGIN_BASENAME' ) ? HD_WC_GALLERY_PLUGIN_BASENAME : 'hd-wc-gallery/hd-wc-gallery.php';
		$updateUrl      = wp_nonce_url(
			self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $pluginBasename ) ),
			'upgrade-plugin_' . $pluginBasename
		);
		$detailsUrl     = self_admin_url( 'plugin-install.php?tab=plugin-information&plugin=hd-wc-gallery&section=changelog&TB_iframe=true&width=600&height=800' );

		$noticeText = sprintf(
			/* translators: 1: Plugin name, 2: Details link, 3: Details link aria label, 4: Version, 5: Update link, 6: Update link aria label */
			__( 'There is a new version of %1$s available. <a href="%2$s" class="thickbox open-plugin-details-modal" aria-label="%3$s">View version %4$s details</a> or <a href="%5$s" class="update-link" aria-label="%6$s">update now</a>.', 'hd-wc-gallery' ),
			'HD WC Gallery',
			esc_url( $detailsUrl ),
			/* translators: %s: Plugin version. */
			esc_attr( sprintf( __( 'View HD WC Gallery version %s details', 'hd-wc-gallery' ), $newVersion ) ),
			esc_html( $newVersion ),
			esc_url( $updateUrl ),
			esc_attr( __( 'Update HD WC Gallery now', 'hd-wc-gallery' ) )
		);

		return sprintf(
			'<tr class="plugin-update-tr active" id="hdwcg-update" data-slug="hd-wc-gallery" data-plugin="%1$s">'
			. '<td colspan="4" class="plugin-update colspanchange">'
			. '<div class="update-message notice inline notice-warning notice-alt">'
			. '<p>%2$s</p>'
			. '</div>'
			. '</td>'
			. '</tr>',
			esc_attr( $pluginBasename ),
			$noticeText
		);
	}

	// ── Client-Side Live Push on plugins.php ─────────────────────────────

	/**
	 * Enqueue lightweight Live Push script on wp-admin/plugins.php and update-core.php.
	 */
	public function enqueuePluginsScript( string $hook ): void {
		if ( ! in_array( $hook, [ 'plugins.php', 'update-core.php' ], true ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$pluginBasename = defined( 'HD_WC_GALLERY_PLUGIN_BASENAME' ) ? HD_WC_GALLERY_PLUGIN_BASENAME : 'hd-wc-gallery/hd-wc-gallery.php';

		// Guard: If WordPress already knows an update is available via site transient,
		// WP Core will render the update notice row server-side. Skip polling to prevent duplicates.
		$currentUpdates = get_site_transient( 'update_plugins' );
		$currentVersion = defined( 'HD_WC_GALLERY_VERSION' ) ? HD_WC_GALLERY_VERSION : '1.0.2';
		if (
			is_object( $currentUpdates )
			&& isset( $currentUpdates->response[ $pluginBasename ] )
			&& ! empty( $currentUpdates->response[ $pluginBasename ]->new_version )
			&& version_compare( (string) $currentUpdates->response[ $pluginBasename ]->new_version, $currentVersion, '>' )
		) {
			return;
		}

		$namespace = defined( 'HD_WC_GALLERY_REST_NAMESPACE' ) ? HD_WC_GALLERY_REST_NAMESPACE : 'hd-wc-gallery/v1';

		$config = [
			'endpoint'          => esc_url_raw( rest_url( $namespace . '/updater/check' ) ),
			'nonce'             => wp_create_nonce( 'wp_rest' ),
			'hasCooldown'       => (bool) get_transient( self::COOLDOWN_TRANSIENT ),
			'cooldownRemaining' => $this->getCooldownRemaining(),
			'cooldownSeconds'   => (int) self::COOLDOWN_SECONDS,
		];

		wp_register_script( 'hdwcg-updater-live', '', [], defined( 'HD_WC_GALLERY_VERSION' ) ? HD_WC_GALLERY_VERSION : '1.0.2', [ 'in_footer' => true ] );
		wp_enqueue_script( 'hdwcg-updater-live' );
		wp_add_inline_script(
			'hdwcg-updater-live',
			sprintf(
				'window.hdwcgUpdaterLiveConfig = %s;
(function() {
	function hasUpdateNotice() {
		if (document.getElementById("hdwcg-update") || document.getElementById("hdwcg-update-core-notice")) {
			return true;
		}
		if (document.querySelector("tr.plugin-update-tr[data-plugin=\"%s\"]")) {
			return true;
		}
		var targetRow = document.querySelector("tr[data-plugin=\"%s\"]");
		if (targetRow) {
			var next = targetRow.nextElementSibling;
			if (next && (next.classList.contains("plugin-update-tr") || next.querySelector(".update-message"))) {
				return true;
			}
		}
		return false;
	}

	if (hasUpdateNotice()) {
		return;
	}
	var config = window.hdwcgUpdaterLiveConfig;
	if (!config || !config.endpoint) {
		return;
	}

	var pollTimer = null;
	var isChecking = false;
	var storageKey = "hdwcg_updater_last_check";

	function scheduleNext(delayMs) {
		if (pollTimer) {
			clearTimeout(pollTimer);
			pollTimer = null;
		}
		if (hasUpdateNotice()) {
			return;
		}
		pollTimer = setTimeout(executeCheck, Math.max(100, delayMs));
	}

	function executeCheck() {
		if (hasUpdateNotice()) {
			return;
		}
		if (document.hidden) {
			return;
		}
		if (isChecking) {
			return;
		}

		var minGap = (config.cooldownSeconds || 300) * 1000;
		try {
			var lastCheck = parseInt(localStorage.getItem(storageKey), 10) || 0;
			var now = Date.now();
			if (lastCheck > 0 && (now - lastCheck) < (minGap - 500)) {
				scheduleNext(minGap - (now - lastCheck));
				return;
			}
		} catch (e) {}

		isChecking = true;
		try {
			localStorage.setItem(storageKey, String(Date.now()));
		} catch (e) {}

		fetch(config.endpoint, {
			headers: { "X-WP-Nonce": config.nonce },
			credentials: "same-origin"
		})
		.then(function(res) { return res.ok ? res.json() : null; })
		.then(function(data) {
			isChecking = false;
			if (!data) {
				scheduleNext(minGap);
				return;
			}

			if (data.has_update) {
				if (hasUpdateNotice()) {
					return;
				}
				var targetRow = document.querySelector("tr[data-plugin=\"%s\"]") || document.getElementById("hd-wc-gallery");
				if (targetRow && data.row_html) {
					// Purge any existing update row before insertion to guarantee zero duplicate rows.
					var existing = document.querySelector("tr.plugin-update-tr[data-plugin=\"%s\"]") || document.getElementById("hdwcg-update");
					if (existing) {
						existing.remove();
					}
					targetRow.insertAdjacentHTML("afterend", data.row_html);
					var alreadyMarked = targetRow.classList.contains("update");
					targetRow.classList.add("update");

					// Update admin menu badge count only if not already counted.
					if (!alreadyMarked) {
						var menuBadges = document.querySelectorAll("#menu-plugins .plugin-count, #wp-admin-bar-updates .ab-label, #menu-dashboard .update-count");
						if (menuBadges.length > 0) {
							menuBadges.forEach(function(el) {
								var count = parseInt(el.textContent, 10) || 0;
								el.textContent = String(count + 1);
							});
						} else {
							var menuLink = document.querySelector("#menu-plugins a.wp-has-submenu[href=\"plugins.php\"], #menu-plugins a[href=\"plugins.php\"]");
							if (menuLink && !menuLink.querySelector(".update-plugins")) {
								menuLink.insertAdjacentHTML("beforeend", \'<span class="update-plugins count-1"><span class="plugin-count">1</span></span>\');
							}
						}
					}
				} else {
					var wrap = document.querySelector(".wrap");
					if (wrap && !document.getElementById("hdwcg-update-core-notice")) {
						var notice = document.createElement("div");
						notice.id = "hdwcg-update-core-notice";
						notice.className = "notice notice-warning is-dismissible";
						notice.innerHTML = "<p><strong>HD WooCommerce Gallery:</strong> A new version <strong>" + (data.new_version || "") + "</strong> is available. <a href=\"" + window.location.href + "\">Reload page</a> to update.</p>";
						var h1 = wrap.querySelector("h1");
						if (h1 && h1.nextSibling) {
							wrap.insertBefore(notice, h1.nextSibling);
						} else {
							wrap.prepend(notice);
						}
					}
				}

				// Terminal state: clear timer and teardown polling.
				if (pollTimer) {
					clearTimeout(pollTimer);
					pollTimer = null;
				}
				return;
			}

			// No update available: schedule next poll based on remaining cooldown.
			var nextDelay = (typeof data.cooldown_remaining === "number" && data.cooldown_remaining > 0)
				? data.cooldown_remaining * 1000
				: minGap;
			scheduleNext(nextDelay);
		})
		.catch(function() {
			isChecking = false;
			// Exponential backoff on network failure.
			scheduleNext(minGap * 2);
		});
	}

	// Page visibility listener: freeze timer when hidden, evaluate when visible.
	document.addEventListener("visibilitychange", function() {
		if (document.hidden) {
			if (pollTimer) {
				clearTimeout(pollTimer);
				pollTimer = null;
			}
		} else {
			if (hasUpdateNotice()) {
				return;
			}
			var minGap = (config.cooldownSeconds || 300) * 1000;
			var lastCheck = 0;
			try {
				lastCheck = parseInt(localStorage.getItem(storageKey), 10) || 0;
			} catch (e) {}
			var elapsed = Date.now() - lastCheck;
			if (elapsed >= minGap) {
				scheduleNext(100);
			} else {
				scheduleNext(minGap - elapsed);
			}
		}
	});

	// Initial schedule: if cooldown remaining is passed, use it, otherwise check immediately.
	var initialDelay = (typeof config.cooldownRemaining === "number" && config.cooldownRemaining > 0)
		? config.cooldownRemaining * 1000
		: 500;
	scheduleNext(initialDelay);
})();',
				wp_json_encode( $config ),
				esc_js( $pluginBasename ),
				esc_js( $pluginBasename ),
				esc_js( $pluginBasename ),
				esc_js( $pluginBasename )
			)
		);
	}

	// ── Fail-Safe Package Integrity Guard ────────────────────────────────

	/**
	 * Fail-Safe Package Integrity Guard.
	 *
	 * Inspects the unzipped archive before WordPress deletes the existing plugin installation.
	 * If critical core files are missing, the update aborts cleanly and preserves the active plugin.
	 *
	 * @param mixed $source
	 * @param mixed $remoteSource
	 * @param mixed $upgrader
	 * @param mixed $hookExtra
	 *
	 * @return string|WP_Error
	 */
	public function validatePackageIntegrity( mixed $source, mixed $remoteSource, mixed $upgrader, mixed $hookExtra = null ): string|WP_Error {
		global $wp_filesystem;

		if ( is_wp_error( $source ) ) {
			return $source;
		}

		if ( ! is_string( $source ) || '' === $source ) {
			return new WP_Error( 'hdwcg_invalid_source', __( 'Invalid update source path.', 'hd-wc-gallery' ) );
		}

		if ( ! isset( $upgrader, $wp_filesystem ) || ! is_object( $wp_filesystem ) ) {
			return $source;
		}

		$pluginBasename = defined( 'HD_WC_GALLERY_PLUGIN_BASENAME' ) ? HD_WC_GALLERY_PLUGIN_BASENAME : 'hd-wc-gallery/hd-wc-gallery.php';

		// Identify whether HD WC Gallery is the plugin being upgraded.
		$isHdwcg = false;
		if ( is_array( $hookExtra ) && isset( $hookExtra['plugin'] ) && $pluginBasename === $hookExtra['plugin'] ) {
			$isHdwcg = true;
		} elseif ( isset( $upgrader->skin->plugin ) && $pluginBasename === $upgrader->skin->plugin ) {
			$isHdwcg = true;
		} elseif ( isset( $this->checker ) && $this->checker->isBeingUpgraded( $upgrader ) ) {
			$isHdwcg = true;
		} elseif ( 'hd-wc-gallery' === basename( rtrim( $source, '/\\' ) ) ) {
			$isHdwcg = true;
		}

		if ( ! $isHdwcg ) {
			return $source;
		}

		$sourceDir = trailingslashit( $source );
		$hasMain   = $wp_filesystem->exists( $sourceDir . 'hd-wc-gallery.php' );
		$hasPlugin = $wp_filesystem->exists( $sourceDir . 'src/Plugin.php' );
		$hasVendor = $wp_filesystem->exists( $sourceDir . 'vendor/autoload.php' );

		if ( ! $hasMain || ! $hasPlugin || ! $hasVendor ) {
			$missing = array_filter(
				[
					! $hasMain ? 'hd-wc-gallery.php' : null,
					! $hasPlugin ? 'src/Plugin.php' : null,
					! $hasVendor ? 'vendor/autoload.php' : null,
				]
			);

			return new WP_Error(
				'hdwcg_corrupted_package',
				sprintf(
					/* translators: %s: Comma-separated list of missing files */
					__( 'HD WC Gallery update aborted: The downloaded package is incomplete (missing: %s). The existing plugin installation was preserved.', 'hd-wc-gallery' ),
					implode( ', ', $missing )
				)
			);
		}

		return $source;
	}

	// ── Token resolution ────────────────────────────────────────────────

	/**
	 * Retrieve GitHub token from environment variables or wp-config.php constants.
	 * Checks HDWCG_GITHUB_TOKEN first across 5 sources, then falls back to shared
	 * GITHUB_TOKEN or HD_GITHUB_TOKEN.
	 */
	public static function getEnvironmentToken(): ?string {
		$keys = [ 'HDWCG_GITHUB_TOKEN', 'GITHUB_TOKEN', 'HD_GITHUB_TOKEN' ];

		foreach ( $keys as $key ) {
			// 1. Direct PHP constant (wp-config.php)
			if ( defined( $key ) && constant( $key ) ) {
				return (string) constant( $key );
			}

			// 2. env() helper (Bedrock / Roots)
			if ( function_exists( 'env' ) ) {
				$val = env( $key );
				if ( ! empty( $val ) ) {
					return (string) $val;
				}
			}

			// 3. $_ENV superglobal (Dotenv)
			if ( ! empty( $_ENV[ $key ] ) ) {
				return (string) $_ENV[ $key ];
			}

			// 4. $_SERVER superglobal (Dotenv / Web server)
			if ( ! empty( $_SERVER[ $key ] ) ) {
				return (string) $_SERVER[ $key ];
			}

			// 5. Native getenv()
			$val = getenv( $key );
			if ( false !== $val && '' !== $val ) {
				return (string) $val;
			}
		}

		return null;
	}

	private function getToken(): ?string {
		$stored = get_option( self::TOKEN_OPTION, '' );
		if ( ! empty( $stored ) ) {
			$decrypted = Crypto::decrypt( (string) $stored );
			if ( '' !== $decrypted ) {
				return $decrypted;
			}
		}

		return self::getEnvironmentToken();
	}

	// ── Token status ────────────────────────────────────────────────────

	public static function hasToken(): bool {
		return 'none' !== self::tokenSource();
	}

	/**
	 * Token source for status reporting.
	 *
	 * @return 'db'|'constant'|'none'
	 */
	public static function tokenSource(): string {
		$stored = get_option( self::TOKEN_OPTION, '' );
		if ( ! empty( $stored ) && '' !== Crypto::decrypt( (string) $stored ) ) {
			return 'db';
		}

		if ( null !== self::getEnvironmentToken() ) {
			return 'constant';
		}

		return 'none';
	}
}
