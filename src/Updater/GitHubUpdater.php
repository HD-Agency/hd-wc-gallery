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
 * key (_hd_wc_gallery_github_token), with fallback to plugin-specific HDWCG_GITHUB_TOKEN
 * or the unified shared GITHUB_TOKEN in .env / config/application.php.
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
	private const REPO_URL              = 'https://github.com/HD-Agency/hd-wc-gallery';
	public const TOKEN_OPTION           = '_hd_wc_gallery_github_token';
	public const COOLDOWN_TRANSIENT     = '_hdwcg_github_update_cooldown';
	public const COOLDOWN_SECONDS       = 300; // 5 minutes
	private const CHECK_PERIOD_HOURS    = 2;
	private const HTTP_TIMEOUT_WEB      = 5.0;
	private const HTTP_TIMEOUT_CRON     = 10.0;
	private const MAX_DOWNLOAD_ATTEMPTS = 3;

	private static ?self $instance        = null;
	private ?PluginUpdateChecker $checker = null;
	private bool $routesRegistered        = false;
	private ?string $cachedToken          = null;
	private bool $tokenLoaded             = false;

	public static function init(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public static function instance(): ?self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function __construct() {
		self::$instance = $this;
		$this->initUpdateChecker();
	}

	public function getChecker(): ?PluginUpdateChecker {
		return $this->checker;
	}

	/**
	 * Reset in-memory cached token state after option mutation.
	 */
	public function resetTokenCache(): void {
		$this->tokenLoaded = false;
		$this->cachedToken = null;
	}

	/**
	 * Purge cached update state in PUC and WordPress update_plugins transient.
	 */
	public function resetCachedUpdateState(): void {
		if ( null !== $this->checker ) {
			$this->checker->resetUpdateState();
		}

		$pluginBasename = defined( 'HD_WC_GALLERY_PLUGIN_BASENAME' ) ? HD_WC_GALLERY_PLUGIN_BASENAME : 'hd-wc-gallery/hd-wc-gallery.php';
		$transient      = get_site_transient( 'update_plugins' );
		if ( is_object( $transient ) ) {
			$changed = false;
			if ( isset( $transient->response[ $pluginBasename ] ) ) {
				unset( $transient->response[ $pluginBasename ] );
				$changed = true;
			}
			if ( isset( $transient->no_update[ $pluginBasename ] ) ) {
				unset( $transient->no_update[ $pluginBasename ] );
				$changed = true;
			}
			if ( $changed ) {
				set_site_transient( 'update_plugins', $transient );
			}
		}
	}

	/**
	 * Check whether PUC currently holds a valid cached Update object with a non-empty download_url.
	 */
	public function hasValidCachedUpdate(): bool {
		if ( null === $this->checker ) {
			return false;
		}

		$update         = $this->checker->getUpdate();
		$currentVersion = defined( 'HD_WC_GALLERY_VERSION' ) ? HD_WC_GALLERY_VERSION : '1.0.7';

		return null !== $update
			&& ! empty( $update->version )
			&& ! empty( $update->download_url )
			&& version_compare( (string) $update->version, $currentVersion, '>' );
	}

	private function initUpdateChecker(): void {
		if ( ! class_exists( PucFactory::class ) ) {
			return;
		}

		try {
			// Enforce strict HTTP timeouts and Bearer authentication on GitHub API requests.
			add_filter( 'puc_request_info_options-hd-wc-gallery', [ $this, 'filterHttpRequestOptions' ] );

			// Guard against synchronous blocking checks on passive admin page loads and when token is missing.
			add_filter( 'puc_check_now-hd-wc-gallery', [ $this, 'shouldCheckNow' ] );

			// Synchronize or suppress WordPress update_plugins transient based on PUC state & token presence.
			add_filter( 'site_transient_update_plugins', [ $this, 'injectUpdateFromPucState' ], 20 );

			// Suppress PUC "Check for updates" row action link when no token is configured.
			add_filter( 'puc_manual_check_link-hd-wc-gallery', [ $this, 'filterManualCheckLink' ] );

			// Strip Authorization header on external redirect (e.g. AWS S3 signed URLs) while preserving codeload.
			add_action( 'requests-requests.before_redirect', [ $this, 'filterRequestsRedirect' ], 10, 2 );

			// Whitelist trusted GitHub archive and CDN domains in SSRF validation to prevent Windows DNS timeout errors.
			add_filter( 'http_request_host_is_external', [ $this, 'filterExternalHosts' ], 10, 2 );

			// Bypass wp_http_validate_url() gethostbyname() flakiness strictly for allowlisted HD WC Gallery GitHub URLs.
			add_filter( 'http_request_args', [ $this, 'filterTrustedHttpRequestArgs' ], 9999, 2 );

			// Pre-unpack archive integrity guard: validate ZIP magic bytes and reject corrupt/truncated downloads.
			add_filter( 'upgrader_pre_download', [ $this, 'validateDownloadArchive' ], 20, 4 );

			// Normalize GitHub archive directory on Windows (Laragon/NTFS) to prevent rename failures.
			add_filter( 'upgrader_source_selection', [ $this, 'normalizeSourceDirectory' ], 5, 4 );

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
			remove_filter( 'upgrader_pre_download', [ $checker->getVcsApi(), 'addDownloadRequestFilters' ] );

			$this->checker = $checker;
		} catch ( \Throwable $e ) {
			// Silently degrade in production; surface a safe diagnostic in debug mode.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( '[HD WC Gallery Updater] Failed to initialize update checker: %s', $e->getMessage() ) );
			}
		}
	}

	/**
	 * Suppress PUC's "Check for updates" link on plugins.php when no GitHub token is configured.
	 */
	public function filterManualCheckLink( mixed $link ): string {
		if ( ! self::hasToken() || ! is_string( $link ) ) {
			return '';
		}

		return $link;
	}

	// ── HTTP Timeout & Auth Guard ────────────────────────────────────────

	/**
	 * Enforce strict HTTP timeout and Bearer token on GitHub API requests to prevent PHP worker stalls.
	 *
	 * @param array<string, mixed> $options HTTP request options for wp_remote_get.
	 * @return array<string, mixed>
	 */
	public function filterHttpRequestOptions( mixed $options ): mixed {
		if ( ! is_array( $options ) ) {
			return $options;
		}

		$token = $this->getToken();
		if ( null !== $token && '' !== $token ) {
			$options['headers']                  = is_array( $options['headers'] ?? null ) ? $options['headers'] : [];
			$options['headers']['Authorization'] = 'Bearer ' . $token;
		}

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
	 * @param mixed $strategies
	 * @return mixed
	 */
	public function filterUpdateDetectionStrategies( mixed $strategies ): mixed {
		if ( ! is_array( $strategies ) ) {
			return $strategies;
		}

		unset( $strategies['latest_release'], $strategies['latest_tag'] );

		return $strategies;
	}

	// ── Synchronous & Asynchronous Check Dispatcher ─────────────────────

	/**
	 * Allow update checks during WP-Cron, "Dashboard → Updates", manual "Check for updates", or bulk upgrades.
	 * Completely disables update checks when no GitHub token is configured in DB or environment fallback.
	 * Protects cached download_url during upgrade execution so transient network timeouts never wipe StateStore.
	 *
	 * @param mixed $shouldCheck Current decision from PUC scheduler.
	 */
	public function shouldCheckNow( mixed $shouldCheck = false ): bool {
		if ( ! (bool) $shouldCheck ) {
			return false;
		}

		$token = $this->getToken();
		if ( null === $token || '' === $token ) {
			return false;
		}

		// 1. Always allow cron-triggered checks when a token is configured.
		if ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || wp_doing_cron() ) {
			return true;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$reqAction = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] )
			? sanitize_key( wp_unslash( $_REQUEST['action'] ) )
			: '';
		if ( '' === $reqAction || '-1' === $reqAction ) {
			$reqAction = isset( $_REQUEST['action2'] ) && is_string( $_REQUEST['action2'] )
				? sanitize_key( wp_unslash( $_REQUEST['action2'] ) )
				: '';
		}
		$ajaxAction = wp_doing_ajax() ? $reqAction : '';

		$isExplicitManualCheck = ! empty( $_GET['puc_check_for_updates'] )
			|| ! empty( $_GET['puc_slug'] )
			|| ( doing_action( 'load-update-core.php' ) && ! empty( $_GET['force-check'] ) );

		$isUpgradeExecution = in_array( $ajaxAction, [ 'update-plugin', 'upgrade-plugin' ], true )
			|| in_array( $reqAction, [ 'update-selected', 'do-plugin-upgrade', 'upgrade-plugin' ], true )
			|| doing_action( 'load-update.php' );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( $isExplicitManualCheck ) {
			delete_transient( self::COOLDOWN_TRANSIENT );
			return true;
		}

		if ( doing_action( 'upgrader_process_complete' ) ) {
			delete_transient( self::COOLDOWN_TRANSIENT );
			return false;
		}

		if ( $isUpgradeExecution ) {
			// If PUC already holds a valid cached Update with download_url, serve it immediately (0ms)
			// rather than risking a live GitHub API timeout that overwrites StateStore with null.
			if ( $this->hasValidCachedUpdate() ) {
				return false;
			}

			delete_transient( self::COOLDOWN_TRANSIENT );
			return true;
		}

		// Block synchronous execution on regular web thread for 0ms page load latency.
		return false;
	}

	/**
	 * Synchronize WordPress's `update_plugins` transient with PUC's cached state,
	 * or strip stale update notifications and auto-update UI items when no token is configured.
	 *
	 * @param mixed $transient Site transient `update_plugins` value.
	 * @return mixed
	 */
	public function injectUpdateFromPucState( mixed $transient ): mixed {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$pluginBasename = defined( 'HD_WC_GALLERY_PLUGIN_BASENAME' ) ? HD_WC_GALLERY_PLUGIN_BASENAME : 'hd-wc-gallery/hd-wc-gallery.php';

		if ( ! self::hasToken() ) {
			if ( isset( $transient->response ) && is_array( $transient->response ) ) {
				unset( $transient->response[ $pluginBasename ] );
			}
			if ( isset( $transient->no_update ) && is_array( $transient->no_update ) ) {
				unset( $transient->no_update[ $pluginBasename ] );
			}
			return $transient;
		}

		if ( null === $this->checker ) {
			return $transient;
		}

		$update = $this->checker->getUpdate();
		if ( null === $update || empty( $update->version ) || empty( $update->download_url ) ) {
			return $transient;
		}

		$currentVersion = defined( 'HD_WC_GALLERY_VERSION' ) ? HD_WC_GALLERY_VERSION : '1.0.7';
		if ( version_compare( (string) $update->version, $currentVersion, '>' ) ) {
			if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
				$transient->response = [];
			}
			if ( empty( $transient->response[ $pluginBasename ] ) || empty( $transient->response[ $pluginBasename ]->package ) ) {
				$transient->response[ $pluginBasename ] = $update->toWpFormat();
			}
		} elseif ( isset( $transient->response[ $pluginBasename ] ) ) {
			unset( $transient->response[ $pluginBasename ] );
		}

		return $transient;
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
	 * Completely disabled when no GitHub token is configured in DB or environment fallback.
	 */
	public function handleRestCheck( WP_REST_Request $request ): WP_REST_Response {
		$currentVersion = defined( 'HD_WC_GALLERY_VERSION' ) ? HD_WC_GALLERY_VERSION : '1.0.7';

		if ( ! self::hasToken() ) {
			$this->resetCachedUpdateState();

			return new WP_REST_Response(
				[
					'has_update'           => false,
					'auto_update_disabled' => true,
					'current_version'      => $currentVersion,
					'cooldown_remaining'   => 0,
				],
				200
			);
		}

		$force       = rest_sanitize_boolean( $request->get_param( 'force' ) );
		$hasCooldown = (bool) get_transient( self::COOLDOWN_TRANSIENT );
		$hasThrottle = (bool) get_transient( 'hdwcg_updater_force_throttle' );

		$canCheck = ! $hasCooldown || ( $force && ! $hasThrottle );

		if ( $canCheck && $this->checker ) {
			$previousUpdate = $this->checker->getUpdate();
			$expireAt       = (int) time() + self::COOLDOWN_SECONDS;
			set_transient( self::COOLDOWN_TRANSIENT, $expireAt, self::COOLDOWN_SECONDS );
			if ( $force ) {
				set_transient( 'hdwcg_updater_force_throttle', 1, 30 );
			}
			$this->checker->checkForUpdates();

			// Preserve valid cached update if a transient GitHub API timeout occurred during the check.
			if ( null === $this->checker->getUpdate() && null !== $previousUpdate && ! empty( $this->checker->getLastRequestApiErrors() ) ) {
				$this->checker->getUpdateState()->setUpdate( $previousUpdate )->save();
			}
		}

		$update            = $this->checker ? $this->checker->getUpdate() : null;
		$hasUpdate         = ( null !== $update && ! empty( $update->version ) && version_compare( (string) $update->version, $currentVersion, '>' ) );
		$cooldownRemaining = $this->getCooldownRemaining();

		if ( $hasUpdate ) {
			$pluginBasename = defined( 'HD_WC_GALLERY_PLUGIN_BASENAME' ) ? HD_WC_GALLERY_PLUGIN_BASENAME : 'hd-wc-gallery/hd-wc-gallery.php';
			$newVersion     = sanitize_text_field( (string) $update->version );

			$siteTransient = get_site_transient( 'update_plugins' );
			if ( ! is_object( $siteTransient ) ) {
				$siteTransient = new \stdClass();
			}
			if ( ! isset( $siteTransient->response ) || ! is_array( $siteTransient->response ) ) {
				$siteTransient->response = [];
			}
			$siteTransient->response[ $pluginBasename ] = $update->toWpFormat();
			set_site_transient( 'update_plugins', $siteTransient );

			return new WP_REST_Response(
				[
					'has_update'         => true,
					'current_version'    => $currentVersion,
					'new_version'        => $newVersion,
					'cooldown_remaining' => $cooldownRemaining,
					'update_url'         => wp_nonce_url(
						self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $pluginBasename ) ),
						'upgrade-plugin_' . $pluginBasename
					),
					'details_url'        => self_admin_url( 'plugin-install.php?tab=plugin-information&plugin=hd-wc-gallery&section=changelog&TB_iframe=true&width=600&height=800' ),
					'row_html'           => $this->buildUpdateRowHtml( $newVersion ),
				],
				200
			);
		}

		return new WP_REST_Response(
			[
				'has_update'         => false,
				'current_version'    => $currentVersion,
				'cooldown_remaining' => $cooldownRemaining,
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
	public function enqueuePluginsScript( mixed $hook = '' ): void {
		if ( ! is_string( $hook ) || ! in_array( $hook, [ 'plugins.php', 'update-core.php' ], true ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		// Completely disable Live Push polling when no GitHub token is configured in DB or environment fallback.
		if ( ! self::hasToken() ) {
			return;
		}

		$pluginBasename = defined( 'HD_WC_GALLERY_PLUGIN_BASENAME' ) ? HD_WC_GALLERY_PLUGIN_BASENAME : 'hd-wc-gallery/hd-wc-gallery.php';

		// Guard: If WordPress already knows an update is available via site transient,
		// WP Core will render the update notice row server-side. Skip polling to prevent duplicates.
		$currentUpdates = get_site_transient( 'update_plugins' );
		$currentVersion = defined( 'HD_WC_GALLERY_VERSION' ) ? HD_WC_GALLERY_VERSION : '1.0.7';
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

		wp_register_script( 'hdwcg-updater-live', '', [], defined( 'HD_WC_GALLERY_VERSION' ) ? HD_WC_GALLERY_VERSION : '1.0.7', [ 'in_footer' => true ] );
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

	// ── Windows NTFS Normalization Guard ─────────────────────────────────

	/**
	 * Normalize GitHub archive directory name to standard plugin slug.
	 *
	 * GitHub zipball downloads unpack into arbitrary directory names (e.g. `HD-Agency-hd-wc-gallery-1234567`).
	 * On Windows (Laragon/NTFS), PHP's rename() inside WP_Filesystem_Direct::move() fails with
	 * Win32 Error 5 (Access Denied). WordPress core's move_dir() gracefully falls back to copy_dir()
	 * and deletes the source, preventing `puc-rename-failed` update aborts.
	 *
	 * Hooked at priority 5 (before PUC's fixDirectoryName at priority 10).
	 *
	 * @param mixed $source
	 * @param mixed $remoteSource
	 * @param mixed $upgrader
	 * @param mixed $hookExtra
	 *
	 * @return string|WP_Error
	 */
	public function normalizeSourceDirectory( mixed $source, mixed $remoteSource, mixed $upgrader, mixed $hookExtra = null ): string|WP_Error {
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		if ( ! is_string( $source ) || '' === $source ) {
			return new WP_Error( 'hdwcg_invalid_source', __( 'Invalid update source path.', 'hd-wc-gallery' ) );
		}

		if ( ! is_string( $remoteSource ) || '' === $remoteSource ) {
			return $source;
		}

		if ( ! $this->isHdwcgUpgrade( $source, $upgrader, $hookExtra ) ) {
			return $source;
		}

		$correctedSource = trailingslashit( $remoteSource ) . 'hd-wc-gallery/';
		if ( trailingslashit( $source ) === $correctedSource ) {
			return $source;
		}

		if ( ! function_exists( 'move_dir' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$result = move_dir( $source, $correctedSource, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $correctedSource;
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

		// Identify whether HD WC Gallery is the plugin being upgraded.
		if ( ! $this->isHdwcgUpgrade( $source, $upgrader, $hookExtra ) ) {
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

	/**
	 * Determine if current upgrade action targets HD WC Gallery.
	 *
	 * @param string $source
	 * @param mixed  $upgrader
	 * @param mixed  $hookExtra
	 *
	 * @return bool
	 */
	private function isHdwcgUpgrade( string $source, mixed $upgrader, mixed $hookExtra ): bool {
		$pluginBasename = defined( 'HD_WC_GALLERY_PLUGIN_BASENAME' ) ? HD_WC_GALLERY_PLUGIN_BASENAME : 'hd-wc-gallery/hd-wc-gallery.php';

		if ( is_array( $hookExtra ) ) {
			if ( isset( $hookExtra['theme'] ) ) {
				return false;
			}
			if ( isset( $hookExtra['plugin'] ) && is_string( $hookExtra['plugin'] ) && '' !== $hookExtra['plugin'] ) {
				return $pluginBasename === $hookExtra['plugin'];
			}
		}

		if ( is_object( $upgrader ) && isset( $upgrader->skin ) && is_object( $upgrader->skin ) ) {
			if ( isset( $upgrader->skin->plugin ) && is_string( $upgrader->skin->plugin ) && '' !== $upgrader->skin->plugin ) {
				return $pluginBasename === $upgrader->skin->plugin;
			}
		}

		if ( isset( $this->checker ) && is_object( $upgrader ) && $this->checker->isBeingUpgraded( $upgrader ) ) {
			return true;
		}

		$baseName = strtolower( basename( rtrim( $source, '/\\' ) ) );
		if ( 'hd-wc-gallery' === $baseName || str_starts_with( $baseName, 'hd-agency-hd-wc-gallery-' ) ) {
			return true;
		}

		$repoPath = strtolower( $this->getRepoPath() );
		if ( '' !== $repoPath && str_contains( strtolower( wp_normalize_path( $source ) ), $repoPath . '/' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Get the GitHub repository path (e.g. `HD-Agency/hd-wc-gallery`) derived from REPO_URL.
	 */
	private function getRepoPath(): string {
		return trim( (string) wp_parse_url( self::REPO_URL, PHP_URL_PATH ), '/' );
	}

	/**
	 * Verify that a remote package URL is an HTTPS GitHub / S3 download target for HD WC Gallery.
	 */
	private function isTrustedPackageUrl( string $url ): bool {
		$parsed = wp_parse_url( $url );
		if ( ! is_array( $parsed ) || 'https' !== strtolower( (string) ( $parsed['scheme'] ?? '' ) ) ) {
			return false;
		}

		$host = strtolower( trim( (string) ( $parsed['host'] ?? '' ), '.' ) );
		$path = strtolower( (string) ( $parsed['path'] ?? '' ) );

		if ( in_array( $host, [ 'api.github.com', 'codeload.github.com', 'github.com' ], true ) ) {
			return str_contains( $path, '/hd-agency/hd-wc-gallery' );
		}

		return 's3.amazonaws.com' === $host
			|| str_ends_with( $host, '.s3.amazonaws.com' )
			|| str_ends_with( $host, '.githubusercontent.com' );
	}

	/**
	 * Bypass wp_http_validate_url() DNS lookup failures (gethostbyname flakiness on Windows)
	 * strictly for allowlisted HD WC Gallery GitHub API and archive download URLs.
	 *
	 * @param mixed $args HTTP request arguments.
	 * @param mixed $url  The request URL.
	 * @return mixed
	 */
	public function filterTrustedHttpRequestArgs( mixed $args, mixed $url ): mixed {
		if ( ! is_array( $args ) || ! is_string( $url ) || '' === $url ) {
			return $args;
		}

		if ( $this->isTrustedPackageUrl( $url ) ) {
			$args['reject_unsafe_urls'] = false;
		}

		return $args;
	}

	// ── Pre-Unpack ZIP Magic Bytes & Filesize Guard ──────────────────────

	/**
	 * Validate and process update archive download before unpacking.
	 *
	 * @param mixed                $reply     Pass-through value from earlier filters.
	 * @param string               $package   Package URL or local file path.
	 * @param mixed                $upgrader  WP_Upgrader instance.
	 * @param array<string, mixed> $hookExtra Extra parameters passed by WordPress upgrader.
	 * @return mixed Downloaded file path on success, WP_Error on failure, or pass-through value.
	 */
	public function validateDownloadArchive( mixed $reply, string $package, mixed $upgrader, array $hookExtra = [] ): mixed {
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}

		if ( ! $this->isHdwcgUpgrade( $package, $upgrader, $hookExtra ) ) {
			return $reply;
		}

		$token = $this->getToken();
		if ( null === $token || '' === $token ) {
			return new WP_Error(
				'hdwcg_missing_token',
				__( 'HD WC Gallery update aborted: GitHub authentication token is missing. Auto-updates are disabled.', 'hd-wc-gallery' )
			);
		}

		// Purge any stale archive artifacts before download proceeds.
		$this->cleanStaleTempArchives();

		// If a previous filter already supplied a local package path, validate its magic bytes.
		if ( is_string( $reply ) && '' !== $reply && file_exists( $reply ) ) {
			$validation = $this->verifyZipMagicBytes( $reply );
			if ( is_wp_error( $validation ) ) {
				$tempDir     = function_exists( 'get_temp_dir' ) ? get_temp_dir() : sys_get_temp_dir();
				$normReply   = wp_normalize_path( $reply );
				$normTempDir = wp_normalize_path( $tempDir );
				if ( str_starts_with( $normReply, $normTempDir ) && is_file( $reply ) ) {
					wp_delete_file( $reply );
				}
				return $validation;
			}
			return $reply;
		}

		// If package is already a local file, validate and return untouched or error.
		if ( ! preg_match( '!^(http|https|ftp)://!i', $package ) && file_exists( $package ) ) {
			$validation = $this->verifyZipMagicBytes( $package );
			if ( is_wp_error( $validation ) ) {
				return $validation;
			}
			return $package;
		}

		if ( ! $this->isTrustedPackageUrl( $package ) ) {
			return new WP_Error(
				'hdwcg_untrusted_package_url',
				__( 'HD WC Gallery update aborted: Untrusted or non-HTTPS package download URL.', 'hd-wc-gallery' )
			);
		}

		return $this->downloadArchiveToTempFile( $package, $token );
	}

	/**
	 * Download the GitHub zipball using a 2-step pre-resolved redirect strategy and retry loop.
	 *
	 * @param string $package Remote package URL.
	 * @param string $token   Decrypted GitHub token.
	 * @return string|WP_Error Verified temporary file path or WP_Error.
	 */
	private function downloadArchiveToTempFile( string $package, string $token ): string|WP_Error {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$userAgent = 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url();
		$lastError = null;

		for ( $attempt = 1; $attempt <= self::MAX_DOWNLOAD_ATTEMPTS; $attempt++ ) {
			$downloadUrl = $package;
			$parsedPkg   = wp_parse_url( $package );
			$pkgHost     = strtolower( (string) ( $parsedPkg['host'] ?? '' ) );
			$pkgPath     = strtolower( (string) ( $parsedPkg['path'] ?? '' ) );

			// Step 1: Pre-resolve api.github.com 302 redirect per attempt without streaming to a file.
			if ( 'api.github.com' === $pkgHost ) {
				$probeAccept = str_contains( $pkgPath, '/releases/assets/' )
					? 'application/octet-stream'
					: 'application/vnd.github+json';
				$preflight   = wp_remote_get(
					$package,
					[
						'timeout'            => 15,
						'redirection'        => 0,
						'stream'             => false,
						'reject_unsafe_urls' => false,
						'headers'            => [
							'Authorization'   => 'Bearer ' . $token,
							'Accept'          => $probeAccept,
							'Accept-Encoding' => 'identity',
							'User-Agent'      => $userAgent,
						],
					]
				);

				if ( ! is_wp_error( $preflight ) ) {
					$preCode = (int) wp_remote_retrieve_response_code( $preflight );
					if ( in_array( $preCode, [ 401, 403, 404 ], true ) ) {
						return new WP_Error(
							'hdwcg_download_http_error',
							sprintf(
								/* translators: %d: HTTP status code */
								__( 'HD WC Gallery update aborted: GitHub returned HTTP %d when downloading the package. Verify your GitHub token permissions.', 'hd-wc-gallery' ),
								$preCode
							)
						);
					}

					$location = (string) wp_remote_retrieve_header( $preflight, 'location' );
					if ( in_array( $preCode, [ 301, 302, 303, 307, 308 ], true ) && '' !== $location ) {
						if ( ! $this->isTrustedPackageUrl( $location ) ) {
							return new WP_Error(
								'hdwcg_untrusted_package_url',
								__( 'HD WC Gallery update aborted: Untrusted redirect package URL.', 'hd-wc-gallery' )
							);
						}
						$downloadUrl = $location;
					}
				}
			}

			// Step 2: Stream from resolved URL into a fresh temp file.
			$tmpFile = wp_tempnam( 'hd-wc-gallery-package.zip' );
			if ( ! $tmpFile ) {
				return new WP_Error(
					'hdwcg_temp_failed',
					__( 'HD WC Gallery update aborted: Could not create temporary file for package download.', 'hd-wc-gallery' )
				);
			}

			$parsedDl   = wp_parse_url( $downloadUrl );
			$dlHost     = strtolower( (string) ( $parsedDl['host'] ?? '' ) );
			$dlPath     = strtolower( (string) ( $parsedDl['path'] ?? '' ) );
			$dlQuery    = (string) ( $parsedDl['query'] ?? '' );
			$isGitHub   = in_array( $dlHost, [ 'api.github.com', 'codeload.github.com', 'github.com' ], true );
			$hasSignedQ = str_starts_with( $dlQuery, 'token=' )
				|| str_contains( $dlQuery, '&token=' )
				|| str_contains( $dlQuery, 'AWSAccessKeyId=' )
				|| str_contains( $dlQuery, 'X-Amz-Signature=' );

			$dlAccept = str_contains( $dlPath, '/releases/assets/' )
				? 'application/octet-stream'
				: 'application/zip, application/octet-stream, */*';
			$headers  = [
				'Accept'          => $dlAccept,
				'Accept-Encoding' => 'identity',
				'User-Agent'      => $userAgent,
			];
			if ( $isGitHub && ! $hasSignedQ ) {
				$headers['Authorization'] = 'Bearer ' . $token;
			}

			$response = wp_remote_get(
				$downloadUrl,
				[
					'timeout'            => 300,
					'redirection'        => 5,
					'stream'             => true,
					'filename'           => $tmpFile,
					'reject_unsafe_urls' => false,
					'headers'            => $headers,
				]
			);

			if ( is_wp_error( $response ) ) {
				if ( is_file( $tmpFile ) ) {
					wp_delete_file( $tmpFile );
				}
				$lastError = $response;
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 !== $code ) {
				if ( is_file( $tmpFile ) ) {
					wp_delete_file( $tmpFile );
				}
				$lastError = new WP_Error(
					'hdwcg_download_http_error',
					sprintf(
						/* translators: 1: HTTP status code, 2: HTTP status message */
						__( 'HD WC Gallery update aborted: GitHub returned HTTP %1$d (%2$s).', 'hd-wc-gallery' ),
						$code,
						wp_remote_retrieve_response_message( $response )
					)
				);
				if ( $code >= 400 && $code < 500 && 429 !== $code ) {
					break;
				}
				continue;
			}

			$contentType = strtolower( trim( (string) wp_remote_retrieve_header( $response, 'content-type' ) ) );
			if ( str_starts_with( $contentType, 'text/html' ) || str_starts_with( $contentType, 'application/json' ) ) {
				if ( is_file( $tmpFile ) ) {
					wp_delete_file( $tmpFile );
				}
				$lastError = new WP_Error(
					'hdwcg_invalid_archive_signature',
					sprintf(
						/* translators: %s: HTTP Content-Type header */
						__( 'HD WC Gallery update aborted: The downloaded package is not a valid ZIP archive (missing PK magic header) [Content-Type: %s].', 'hd-wc-gallery' ),
						$contentType
					)
				);
				continue;
			}

			$check = $this->verifyZipMagicBytes( $tmpFile );
			if ( is_wp_error( $check ) ) {
				if ( is_file( $tmpFile ) ) {
					wp_delete_file( $tmpFile );
				}
				$lastError = $check;
				continue;
			}

			return $tmpFile;
		}

		return $lastError instanceof WP_Error
			? $lastError
			: new WP_Error( 'hdwcg_download_failed', __( 'HD WC Gallery update aborted: Package download failed.', 'hd-wc-gallery' ) );
	}

	/**
	 * Verify that a file exists, has sufficient size, starts with the ZIP magic signature,
	 * contains a valid End of Central Directory (EOCD) record, and passes ZipArchive consistency checks.
	 *
	 * @param string $filePath Full filesystem path to the archive file.
	 * @return bool|WP_Error True if valid ZIP archive, WP_Error otherwise.
	 */
	public function verifyZipMagicBytes( string $filePath ): bool|WP_Error {
		clearstatcache( true, $filePath );

		if ( ! is_file( $filePath ) ) {
			return new WP_Error(
				'hdwcg_archive_missing',
				__( 'The update package file does not exist.', 'hd-wc-gallery' )
			);
		}

		$size = filesize( $filePath );
		if ( false === $size || $size < 10240 ) {
			return new WP_Error(
				'hdwcg_archive_truncated',
				sprintf(
					/* translators: %d: File size in bytes */
					__( 'HD WC Gallery update aborted: The downloaded package is truncated or invalid (%d bytes).', 'hd-wc-gallery' ),
					(int) $size
				)
			);
		}

		if ( ! is_readable( $filePath ) ) {
			return new WP_Error(
				'hdwcg_archive_unreadable',
				__( 'HD WC Gallery update aborted: Package file is not readable for verification.', 'hd-wc-gallery' )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $filePath, 'rb' );
		if ( false === $handle ) {
			return new WP_Error(
				'hdwcg_archive_unreadable',
				__( 'HD WC Gallery update aborted: Could not open package file for binary verification.', 'hd-wc-gallery' )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		$prefix = fread( $handle, 1024 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );

		if ( is_string( $prefix ) && '' !== $prefix && ! str_starts_with( $prefix, "\x50\x4b\x03\x04" ) ) {
			if ( str_starts_with( $prefix, "\x1f\x8b\x08" ) && $this->tryDecompressGzipArchive( $filePath ) ) {
				clearstatcache( true, $filePath );
				$size = filesize( $filePath );
			} else {
				$pkOffset = strpos( $prefix, "\x50\x4b\x03\x04" );
				if ( false !== $pkOffset && $pkOffset > 0 && $this->tryStripLeadingBytes( $filePath, $pkOffset ) ) {
					clearstatcache( true, $filePath );
					$size = filesize( $filePath );
				}
			}

			if ( false === $size || $size < 10240 ) {
				return new WP_Error(
					'hdwcg_archive_truncated',
					sprintf(
						/* translators: %d: File size in bytes */
						__( 'HD WC Gallery update aborted: The downloaded package is truncated or invalid (%d bytes).', 'hd-wc-gallery' ),
						(int) $size
					)
				);
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $filePath, 'rb' );
		if ( false === $handle ) {
			return new WP_Error(
				'hdwcg_archive_unreadable',
				__( 'HD WC Gallery update aborted: Could not open package file for binary verification.', 'hd-wc-gallery' )
			);
		}

		// 1. Verify 4-byte ZIP Local File Header (LFH) signature at offset 0: PK\x03\x04.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		$header = fread( $handle, 4 );

		// 2. Verify 22-byte ZIP End of Central Directory (EOCD) signature (\x50\x4b\x05\x06) in the last 65557 bytes.
		$tailSize = min( (int) $size, 65557 );
		$hasEocd  = false;
		if ( 0 === fseek( $handle, -$tailSize, SEEK_END ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$tail = fread( $handle, $tailSize );
			if ( is_string( $tail ) ) {
				$eocdPos = strrpos( $tail, "\x50\x4b\x05\x06" );
				$hasEocd = ( false !== $eocdPos && ( strlen( $tail ) - $eocdPos ) >= 22 );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );

		if ( "\x50\x4b\x03\x04" !== $header ) {
			$trimmedPrefix = is_string( $prefix ) ? ltrim( $prefix ) : '';
			$detectedHint  = '';
			if ( str_starts_with( strtolower( $trimmedPrefix ), '<!doctype' ) || str_starts_with( strtolower( $trimmedPrefix ), '<html' ) ) {
				$detectedHint = ' [detected: HTML document]';
			} elseif ( str_starts_with( $trimmedPrefix, '{' ) ) {
				$detectedHint = ' [detected: JSON response]';
			}

			return new WP_Error(
				'hdwcg_invalid_archive_signature',
				__( 'HD WC Gallery update aborted: The downloaded package is not a valid ZIP archive (missing PK magic header).', 'hd-wc-gallery' ) . $detectedHint
			);
		}

		if ( ! $hasEocd ) {
			return new WP_Error(
				'hdwcg_archive_truncated',
				__( 'HD WC Gallery update aborted: The downloaded ZIP archive is incomplete (missing End of Central Directory record).', 'hd-wc-gallery' )
			);
		}

		// 3. When ext-zip is available, run full structural consistency validation (ZipArchive::CHECKCONS).
		if ( class_exists( \ZipArchive::class ) ) {
			$zip = new \ZipArchive();
			$res = $zip->open( $filePath, \ZipArchive::CHECKCONS );
			if ( true !== $res ) {
				return new WP_Error(
					'hdwcg_invalid_archive_structure',
					sprintf(
						/* translators: %s: ZipArchive error code */
						__( 'HD WC Gallery update aborted: Archive failed structural consistency check (ZipArchive code: %s).', 'hd-wc-gallery' ),
						(string) $res
					)
				);
			}
			$numFiles = $zip->numFiles;
			$zip->close();

			if ( $numFiles <= 0 ) {
				return new WP_Error(
					'hdwcg_invalid_archive_structure',
					__( 'HD WC Gallery update aborted: The downloaded ZIP archive contains zero files.', 'hd-wc-gallery' )
				);
			}
		}

		return true;
	}

	/**
	 * Decompress a GZIP-wrapped archive file in-place when an upstream proxy or transport
	 * failed to decode `Content-Encoding: gzip` on a streamed download.
	 *
	 * @param string $filePath Full path to the GZIP-encoded temporary file.
	 */
	private function tryDecompressGzipArchive( string $filePath ): bool {
		if ( ! function_exists( 'gzopen' ) ) {
			return false;
		}

		$gz = gzopen( $filePath, 'rb' );
		if ( ! is_resource( $gz ) ) {
			return false;
		}

		$unpackedPath = $filePath . '.gunzip';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$out = fopen( $unpackedPath, 'wb' );
		if ( ! is_resource( $out ) ) {
			gzclose( $gz );
			return false;
		}

		$written = 0;
		while ( ! gzeof( $gz ) ) {
			$chunk = gzread( $gz, 65536 );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			$bytes = fwrite( $out, $chunk );
			if ( false === $bytes ) {
				break;
			}
			$written += $bytes;
		}

		gzclose( $gz );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out );

		if ( $written < 10240 ) {
			wp_delete_file( $unpackedPath );
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		if ( rename( $unpackedPath, $filePath ) ) {
			return true;
		}

		wp_delete_file( $unpackedPath );
		return false;
	}

	/**
	 * Strip leading BOM, whitespace, or proxy prefix bytes before the ZIP `PK\x03\x04` signature.
	 *
	 * @param string $filePath Full path to the temporary archive file.
	 * @param int    $offset   Byte offset where `\x50\x4b\x03\x04` begins.
	 */
	private function tryStripLeadingBytes( string $filePath, int $offset ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$in = fopen( $filePath, 'rb' );
		if ( ! is_resource( $in ) ) {
			return false;
		}

		if ( 0 !== fseek( $in, $offset, SEEK_SET ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $in );
			return false;
		}

		$strippedPath = $filePath . '.strip';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$out = fopen( $strippedPath, 'wb' );
		if ( ! is_resource( $out ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $in );
			return false;
		}

		$copied = stream_copy_to_stream( $in, $out );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $in );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out );

		if ( false === $copied || $copied < 10240 ) {
			wp_delete_file( $strippedPath );
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		if ( rename( $strippedPath, $filePath ) ) {
			return true;
		}

		wp_delete_file( $strippedPath );
		return false;
	}

	// ── Requests 2.x Redirect & Stale Temp Archive Guard ─────────────────

	/**
	 * Strip Authorization header when following redirects to external AWS S3 or pre-signed Codeload storage.
	 *
	 * @param mixed                $location Target redirect URI.
	 * @param array<string, mixed> $headers  Outgoing HTTP request headers passed by reference.
	 */
	public function filterRequestsRedirect( mixed &$location, mixed &$headers = [] ): void {
		if ( ! is_string( $location ) || ! is_array( $headers ) ) {
			return;
		}

		$parsedUrl = wp_parse_url( $location );
		$host      = strtolower( (string) ( $parsedUrl['host'] ?? '' ) );
		$query     = (string) ( $parsedUrl['query'] ?? '' );

		$isAwsS3       = str_ends_with( $host, '.s3.amazonaws.com' ) || 's3.amazonaws.com' === $host;
		$hasAmzSig     = str_contains( $query, 'AWSAccessKeyId=' ) || str_contains( $query, 'X-Amz-Signature=' );
		$hasQueryToken = str_starts_with( $query, 'token=' ) || str_contains( $query, '&token=' );

		if ( $isAwsS3 || $hasAmzSig || $hasQueryToken ) {
			foreach ( array_keys( $headers ) as $headerKey ) {
				if ( 'authorization' === strtolower( (string) $headerKey ) ) {
					$authVal = is_string( $headers[ $headerKey ] ) ? $headers[ $headerKey ] : '';
					if ( ! str_starts_with( $authVal, 'AWS4-HMAC-SHA256' ) ) {
						unset( $headers[ $headerKey ] );
					}
				}
			}
		}
	}

	/**
	 * Whitelist GitHub archive and S3 CDN hosts in WordPress SSRF validation.
	 *
	 * Prevents Windows DNS timeout failures during wp_http_validate_url() on codeload.github.com redirects.
	 *
	 * @param mixed $isExternal Existing decision.
	 * @param mixed $host       Host name being validated.
	 * @return bool
	 */
	public function filterExternalHosts( mixed $isExternal, mixed $host ): bool {
		if ( ! is_string( $host ) || '' === $host ) {
			return (bool) $isExternal;
		}

		$cleanHost = strtolower( trim( $host, '.' ) );
		if (
			in_array( $cleanHost, [ 'api.github.com', 'codeload.github.com', 'github.com', 's3.amazonaws.com' ], true )
			|| str_ends_with( $cleanHost, '.github.com' )
			|| str_ends_with( $cleanHost, '.githubusercontent.com' )
			|| str_ends_with( $cleanHost, '.s3.amazonaws.com' )
		) {
			return true;
		}

		return (bool) $isExternal;
	}

	/**
	 * Purge stale HD-WC-Gallery archive temporary files from system temp directory.
	 *
	 * Prevents Windows NTFS rename collisions when download_url() renames temp files
	 * to existing locked or 0-byte destination paths.
	 *
	 * @param int $maxAgeSeconds Maximum age in seconds before a file is considered stale. Default 300 (5 minutes).
	 */
	public function cleanStaleTempArchives( int $maxAgeSeconds = 300 ): void {
		$tempDir = function_exists( 'get_temp_dir' ) ? get_temp_dir() : sys_get_temp_dir();
		$tempDir = trailingslashit( $tempDir );
		if ( ! is_dir( $tempDir ) || ! is_readable( $tempDir ) ) {
			return;
		}

		$patterns = [ 'hd-agency-hd-wc-gallery-*.zip', 'hd-wc-gallery-package*.tmp', 'hd-wc-gallery-package*.zip' ];
		$now      = time();

		foreach ( $patterns as $pattern ) {
			$files = glob( $tempDir . $pattern );
			if ( is_array( $files ) ) {
				foreach ( $files as $file ) {
					if ( is_file( $file ) && wp_is_writable( $file ) ) {
						$mtime = filemtime( $file );
						if ( false !== $mtime && ( $now - $mtime ) >= $maxAgeSeconds ) {
							wp_delete_file( $file );
						}
					}
				}
			}
		}
	}

	// ── Token resolution (Priority #1: Database, #2 Fallback: HDWCG_GITHUB_TOKEN -> GITHUB_TOKEN) ──

	private static function getStoredToken(): ?string {
		$stored = get_option( self::TOKEN_OPTION, '' );
		if ( is_string( $stored ) && '' !== $stored ) {
			$decrypted = Crypto::decrypt( $stored );
			if ( '' !== $decrypted ) {
				return $decrypted;
			}
		}

		return null;
	}

	private function getToken(): ?string {
		if ( $this->tokenLoaded ) {
			return $this->cachedToken;
		}

		$this->tokenLoaded = true;
		$this->cachedToken = self::getStoredToken() ?? self::getEnvironmentToken();

		return $this->cachedToken;
	}

	/**
	 * Resolve fallback token from environment variables (.env) or defined constants (wp-config.php / application.php).
	 * Checks plugin-specific HDWCG_GITHUB_TOKEN first (if defined as a dedicated per-plugin fallback),
	 * then falls back to the unified shared GITHUB_TOKEN.
	 * Only used when no valid token is stored in the database (`_hd_wc_gallery_github_token`).
	 */
	public static function getEnvironmentToken(): ?string {
		$keys = [ 'HDWCG_GITHUB_TOKEN', 'GITHUB_TOKEN' ];

		foreach ( $keys as $key ) {
			if ( defined( $key ) ) {
				$constVal = constant( $key );
				if ( is_string( $constVal ) && '' !== trim( $constVal ) ) {
					return trim( $constVal );
				}
			}

			if ( function_exists( 'Env\\env' ) ) {
				$bedrockEnv = \Env\env( $key );
				if ( is_string( $bedrockEnv ) && '' !== trim( $bedrockEnv ) ) {
					return trim( $bedrockEnv );
				}
			} elseif ( function_exists( 'env' ) ) {
				$globalEnv = env( $key );
				if ( is_string( $globalEnv ) && '' !== trim( $globalEnv ) ) {
					return trim( $globalEnv );
				}
			}

			if ( isset( $_ENV[ $key ] ) && is_string( $_ENV[ $key ] ) && '' !== trim( $_ENV[ $key ] ) ) {
				return trim( $_ENV[ $key ] );
			}

			if ( isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) && '' !== trim( $_SERVER[ $key ] ) ) {
				return trim( $_SERVER[ $key ] );
			}

			$envVal = getenv( $key );
			if ( is_string( $envVal ) && '' !== trim( $envVal ) ) {
				return trim( $envVal );
			}
		}

		return null;
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
		if ( null !== self::getStoredToken() ) {
			return 'db';
		}

		if ( null !== self::getEnvironmentToken() ) {
			return 'constant';
		}

		return 'none';
	}
}
