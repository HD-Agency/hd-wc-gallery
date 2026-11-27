<?php
/**
 * GitHub-based plugin auto-updater via plugin-update-checker (PUC v6.0).
 *
 * Orchestrates PUC lifecycle, cooldown throttling, and `update_plugins` transient synchronization.
 * Delegates token lookup to `GitHubTokenResolver` and archive security & verification to
 * `GitHubArchiveVerifier`.
 *
 * @package HDWCGallery\Updater
 */

declare(strict_types=1);

namespace HDWCGallery\Updater;

use HDWCGallery\Plugin;
use Throwable;
use WP_Error;
use YahnisElsts\PluginUpdateChecker\v6\PucFactory;

defined( 'ABSPATH' ) || exit;

final class GitHubUpdater {

	/** @internal Intentionally fixed — not a user-configurable setting. */
	private const REPO_URL                = 'https://github.com/HD-Agency/hd-wc-gallery';
	public const PLUGIN_BASENAME          = 'hd-wc-gallery/hd-wc-gallery.php';
	public const PLUGIN_SLUG              = 'hd-wc-gallery';
	public const CRON_HOOK                = 'puc_cron_check_updates-hd-wc-gallery';
	public const TOKEN_OPTION             = GitHubTokenResolver::TOKEN_OPTION;
	public const COOLDOWN_TRANSIENT       = '_hdwcg_github_update_cooldown';
	public const FORCE_THROTTLE_TRANSIENT = 'hdwcg_updater_force_throttle';
	public const COOLDOWN_SECONDS         = 300;
	private const CHECK_PERIOD_HOURS      = 2;
	private const HTTP_TIMEOUT_WEB        = 5.0;
	private const HTTP_TIMEOUT_CRON       = 10.0;

	private static ?self $instance   = null;
	private ?object $checker         = null;
	private bool $hooksRegistered    = false;
	private bool $injectingTransient = false;

	public static function init(): self {
		return self::$instance ??= new self();
	}

	public static function instance(): ?self {
		return self::$instance ??= new self();
	}

	public function __construct() {
		self::$instance = $this;
		$this->registerHooks();
		if ( $this->shouldEagerlyInitChecker() ) {
			$this->ensureChecker();
		}
	}

	public function getChecker(): ?object {
		return $this->ensureChecker();
	}

	private function registerHooks(): void {
		if ( $this->hooksRegistered ) {
			return;
		}
		$this->hooksRegistered = true;

		add_filter( 'puc_request_info_options-hd-wc-gallery', [ $this, 'filterHttpRequestOptions' ] );
		add_filter( 'puc_check_now-hd-wc-gallery', [ $this, 'shouldCheckNow' ] );
		add_filter( 'puc_request_update_result-hd-wc-gallery', [ $this, 'recordUpdateCheckCooldown' ] );
		add_filter( 'site_transient_update_plugins', [ $this, 'injectUpdateFromPucState' ], 20 );
		add_filter( 'puc_manual_check_link-hd-wc-gallery', [ $this, 'filterManualCheckLink' ] );
		add_filter( 'puc_view_details_link-hd-wc-gallery', [ $this, 'filterManualCheckLink' ] );
		add_filter( 'upgrader_source_selection', [ $this, 'normalizeSourceDirectory' ], 5, 4 );
		add_filter( 'upgrader_source_selection', [ $this, 'validatePackageIntegrity' ], 25, 4 );
		add_action( 'admin_init', [ $this, 'maybeInitCheckerOnAdminInit' ], 1, 0 );
		add_filter( 'http_request_args', [ $this, 'filterTrustedHttpRequestArgs' ], 9999, 2 );
		add_filter( 'upgrader_pre_download', [ $this, 'validateDownloadArchive' ], 20, 4 );
		add_filter( 'puc_vcs_update_detection_strategies-hd-wc-gallery', [ $this, 'filterUpdateDetectionStrategies' ] );
	}

	public function maybeInitCheckerOnAdminInit(): void {
		global $pagenow;
		if ( in_array( $pagenow ?? '', [ 'plugins.php', 'update-core.php', 'update.php', 'plugin-install.php' ], true ) ) {
			$this->ensureChecker();
		}
	}

	private function shouldEagerlyInitChecker(): bool {
		if ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_ajax() || Plugin::isRestRequest() ) {
			return true;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['puc_check_for_updates'] ) || ! empty( $_GET['force-check'] ) ) {
			return true;
		}

		$reqAction = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] )
			? sanitize_key( wp_unslash( $_REQUEST['action'] ) )
			: '';

		return in_array( $reqAction, [ 'update-selected', 'do-plugin-upgrade', 'upgrade-plugin' ], true );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	private function ensureChecker(): ?object {
		if ( null !== $this->checker ) {
			return $this->checker;
		}

		$token = GitHubTokenResolver::resolve();
		if ( null === $token || '' === $token ) {
			if ( false !== wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_clear_scheduled_hook( self::CRON_HOOK );
			}
			return null;
		}

		if ( ! class_exists( PucFactory::class ) ) {
			return null;
		}

		try {
			$pluginFile = defined( 'HD_WC_GALLERY_PATH' ) ? HD_WC_GALLERY_PATH . 'hd-wc-gallery.php' : dirname( __DIR__, 2 ) . '/hd-wc-gallery.php';
			$checker    = PucFactory::buildUpdateChecker(
				self::REPO_URL,
				$pluginFile,
				self::PLUGIN_SLUG,
				self::CHECK_PERIOD_HOURS
			);

			if ( is_object( $checker ) ) {
				if ( method_exists( $checker, 'setBranch' ) ) {
					$checker->setBranch( 'main' );
				}
				$vcsApi = method_exists( $checker, 'getVcsApi' ) ? $checker->getVcsApi() : null;
				if ( is_object( $vcsApi ) && method_exists( $vcsApi, 'addDownloadRequestFilters' ) ) {
					remove_filter( 'upgrader_pre_download', [ $vcsApi, 'addDownloadRequestFilters' ] );
				}
				$this->checker = $checker;
			}
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( '[HDWCG Updater] Failed to initialize update checker: %s', $e->getMessage() ) );
			}
		}

		return $this->checker;
	}

	public function resetTokenCache(): void {
		GitHubTokenResolver::resetCache();
		delete_transient( self::COOLDOWN_TRANSIENT );
		delete_transient( self::FORCE_THROTTLE_TRANSIENT );
	}

	public function resetCachedUpdateState(): void {
		if ( null !== $this->checker && method_exists( $this->checker, 'resetUpdateState' ) ) {
			$this->checker->resetUpdateState();
		} else {
			delete_site_option( 'external_updates-' . self::PLUGIN_SLUG );
		}

		if ( ! self::hasToken() ) {
			$this->checker = null;
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}

		$pluginBasename = $this->pluginBasename();
		$transient      = get_site_transient( 'update_plugins' );
		if ( ! is_object( $transient ) ) {
			return;
		}
		$changed = false;
		foreach ( [ 'response', 'no_update' ] as $bucket ) {
			if ( isset( $transient->$bucket ) && is_array( $transient->$bucket ) && isset( $transient->$bucket[ $pluginBasename ] ) ) {
				unset( $transient->$bucket[ $pluginBasename ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			set_site_transient( 'update_plugins', $transient );
		}
	}

	public function hasValidCachedUpdate(): bool {
		$pluginBasename = $this->pluginBasename();
		$currentVersion = defined( 'HD_WC_GALLERY_VERSION' ) ? HD_WC_GALLERY_VERSION : '1.1.1';
		if ( ! $this->injectingTransient ) {
			$siteTransient = get_site_transient( 'update_plugins' );
			if ( is_object( $siteTransient ) && is_object( $siteTransient->response[ $pluginBasename ] ?? null ) ) {
				$item = $siteTransient->response[ $pluginBasename ];
				if ( ! empty( $item->new_version ) && ! empty( $item->package ) && version_compare( (string) $item->new_version, $currentVersion, '>' ) ) {
					return true;
				}
			}
		}

		$checker = $this->checker ?? $this->ensureChecker();
		if ( null === $checker || ! method_exists( $checker, 'getUpdate' ) ) {
			return false;
		}

		$update = $checker->getUpdate();

		return is_object( $update )
			&& ! empty( $update->version )
			&& ! empty( $update->download_url )
			&& version_compare( (string) $update->version, $currentVersion, '>' );
	}

	public function filterHttpRequestOptions( mixed $options ): mixed {
		if ( ! is_array( $options ) ) {
			return $options;
		}

		$token = GitHubTokenResolver::resolve();
		if ( null !== $token && '' !== $token ) {
			$options['headers']                  = is_array( $options['headers'] ?? null ) ? $options['headers'] : [];
			$options['headers']['Authorization'] = 'Bearer ' . $token;
		}

		$isCron             = ( defined( 'DOING_CRON' ) && DOING_CRON ) || wp_doing_cron();
		$options['timeout'] = $isCron ? self::HTTP_TIMEOUT_CRON : self::HTTP_TIMEOUT_WEB;

		return $options;
	}

	public function shouldCheckNow( mixed $shouldCheck = false ): bool {
		$token = GitHubTokenResolver::resolve();
		if ( null === $token || '' === $token ) {
			return false;
		}

		if ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || wp_doing_cron() ) {
			return (bool) $shouldCheck && false === get_transient( self::COOLDOWN_TRANSIENT );
		}

		if ( doing_action( 'upgrader_process_complete' ) ) {
			delete_transient( self::COOLDOWN_TRANSIENT );
			return false;
		}

		if ( ! current_user_can( 'update_plugins' ) ) {
			return false;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Explicit trigger inspection; puc_check_for_updates verifies _wpnonce below, Core validates upgrade/update-core nonces downstream.
		$reqAction = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] )
			? sanitize_key( wp_unslash( $_REQUEST['action'] ) )
			: '';
		if ( '' === $reqAction || '-1' === $reqAction ) {
			$reqAction = isset( $_REQUEST['action2'] ) && is_string( $_REQUEST['action2'] )
				? sanitize_key( wp_unslash( $_REQUEST['action2'] ) )
				: '';
		}
		$ajaxAction = wp_doing_ajax() ? $reqAction : '';
		$pucSlug    = isset( $_GET['puc_slug'] ) && is_string( $_GET['puc_slug'] )
			? sanitize_key( wp_unslash( $_GET['puc_slug'] ) )
			: '';
		$pucNonce   = isset( $_GET['_wpnonce'] ) && is_string( $_GET['_wpnonce'] )
			? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) )
			: '';

		$isExplicitManualCheck = ( self::PLUGIN_SLUG === $pucSlug && ! empty( $_GET['puc_check_for_updates'] ) && false !== wp_verify_nonce( $pucNonce, 'puc_check_for_updates' ) )
			|| ( doing_action( 'load-update-core.php' ) && ! empty( $_GET['force-check'] ) );

		$isUpgradeExecution = in_array( $ajaxAction, [ 'update-plugin', 'upgrade-plugin' ], true )
			|| in_array( $reqAction, [ 'update-selected', 'do-plugin-upgrade', 'upgrade-plugin' ], true )
			|| doing_action( 'load-update.php' );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( $isExplicitManualCheck ) {
			delete_transient( self::COOLDOWN_TRANSIENT );
			return true;
		}

		if ( $isUpgradeExecution ) {
			if ( $this->hasValidCachedUpdate() ) {
				return false;
			}
			delete_transient( self::COOLDOWN_TRANSIENT );
			return true;
		}

		return false;
	}

	public function recordUpdateCheckCooldown( mixed $update ): mixed {
		set_transient( self::COOLDOWN_TRANSIENT, time() + self::COOLDOWN_SECONDS, self::COOLDOWN_SECONDS );
		return $update;
	}

	public function injectUpdateFromPucState( mixed $transient ): mixed {
		if ( ! is_object( $transient ) || $this->injectingTransient ) {
			return $transient;
		}

		$this->injectingTransient = true;
		try {
			$pluginBasename = $this->pluginBasename();
			$token          = GitHubTokenResolver::resolve();
			$isCron         = ( defined( 'DOING_CRON' ) && DOING_CRON ) || wp_doing_cron();

			if ( null === $token || '' === $token || ( ! $isCron && is_user_logged_in() && ! current_user_can( 'update_plugins' ) ) ) {
				foreach ( [ 'response', 'no_update' ] as $bucket ) {
					if ( isset( $transient->$bucket ) && is_array( $transient->$bucket ) ) {
						unset( $transient->$bucket[ $pluginBasename ] );
					}
				}
				return $transient;
			}

			$existingPackage = isset( $transient->response[ $pluginBasename ] ) && is_object( $transient->response[ $pluginBasename ] )
				? (string) ( $transient->response[ $pluginBasename ]->package ?? '' )
				: '';

			if ( '' !== $existingPackage || ( null === $this->checker && ! $this->hasPendingPucOptionUpdate() ) ) {
				return $transient;
			}

			$checker = $this->checker ?? $this->ensureChecker();
			if ( null === $checker || ! method_exists( $checker, 'getUpdate' ) ) {
				return $transient;
			}

			$update         = $checker->getUpdate();
			$currentVersion = defined( 'HD_WC_GALLERY_VERSION' ) ? HD_WC_GALLERY_VERSION : '1.1.1';
			if (
				is_object( $update )
				&& ! empty( $update->version )
				&& ! empty( $update->download_url )
				&& version_compare( (string) $update->version, $currentVersion, '>' )
				&& method_exists( $update, 'toWpFormat' )
			) {
				if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
					$transient->response = [];
				}
				if ( isset( $transient->no_update ) && is_array( $transient->no_update ) ) {
					unset( $transient->no_update[ $pluginBasename ] );
				}
				$transient->response[ $pluginBasename ] = $update->toWpFormat();
			}

			return $transient;
		} finally {
			$this->injectingTransient = false;
		}
	}

	private function hasPendingPucOptionUpdate(): bool {
		$pucState = get_site_option( 'external_updates-' . self::PLUGIN_SLUG, false );
		if ( ! is_object( $pucState ) || ! isset( $pucState->update ) || ! is_object( $pucState->update ) ) {
			return false;
		}

		$version        = isset( $pucState->update->version ) ? (string) $pucState->update->version : '';
		$downloadUrl    = isset( $pucState->update->download_url ) ? (string) $pucState->update->download_url : '';
		$currentVersion = defined( 'HD_WC_GALLERY_VERSION' ) ? HD_WC_GALLERY_VERSION : '1.1.1';

		return '' !== $version && '' !== $downloadUrl && version_compare( $version, $currentVersion, '>' );
	}

	public function getCooldownRemaining(): int {
		$expireAt = get_transient( self::COOLDOWN_TRANSIENT );
		if ( ! $expireAt ) {
			return 0;
		}

		if ( is_numeric( $expireAt ) && (int) $expireAt > 1 ) {
			return max( 0, (int) $expireAt - time() );
		}

		$timeout = (int) get_option( '_transient_timeout_' . self::COOLDOWN_TRANSIENT );
		return $timeout <= 0 ? self::COOLDOWN_SECONDS : max( 0, $timeout - time() );
	}

	public function filterUpdateDetectionStrategies( mixed $strategies ): mixed {
		if ( is_array( $strategies ) ) {
			unset( $strategies['latest_release'], $strategies['latest_tag'] );
		}

		return $strategies;
	}

	public function filterManualCheckLink( mixed $link ): string {
		$token = GitHubTokenResolver::resolve();
		if ( null === $token || '' === $token || ! is_string( $link ) || ! current_user_can( 'update_plugins' ) ) {
			return '';
		}

		return $link;
	}

	public function normalizeSourceDirectory( mixed $source, mixed $remoteSource, mixed $upgrader, mixed $hookExtra = null ): string|WP_Error {
		return GitHubArchiveVerifier::normalizeSourceDirectory( $source, $remoteSource, $upgrader, $hookExtra, $this->checker );
	}

	public function validatePackageIntegrity( mixed $source, mixed $remoteSource, mixed $upgrader, mixed $hookExtra = null ): string|WP_Error {
		unset( $remoteSource );
		return GitHubArchiveVerifier::validatePackageIntegrity( $source, $upgrader, $hookExtra, $this->checker );
	}

	public function validateDownloadArchive( mixed $reply, mixed $package = '', mixed $upgrader = null, mixed $hookExtra = [] ): mixed {
		return GitHubArchiveVerifier::validateDownloadArchive( $reply, $package, $upgrader, $hookExtra, $this->checker );
	}

	public function filterTrustedHttpRequestArgs( mixed $args, mixed $url ): mixed {
		return GitHubArchiveVerifier::filterTrustedHttpRequestArgs( $args, $url );
	}

	public static function hasToken(): bool {
		return GitHubTokenResolver::hasToken();
	}

	/** @return 'db'|'constant'|'none' */
	public static function tokenSource(): string {
		return GitHubTokenResolver::tokenSource();
	}

	private function pluginBasename(): string {
		return defined( 'HD_WC_GALLERY_PLUGIN_BASENAME' ) ? HD_WC_GALLERY_PLUGIN_BASENAME : self::PLUGIN_BASENAME;
	}
}
