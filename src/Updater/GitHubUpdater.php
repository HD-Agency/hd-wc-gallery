<?php
/**
 * GitHub-based Auto-Updater for HD WC Gallery using Plugin Update Checker.
 *
 * Integrates yahnis-elsts/plugin-update-checker with Sodium encrypted token storage,
 * non-blocking async background checks, and strict timeout guards.
 *
 * @package HDWCGallery\Updater
 */

declare(strict_types=1);

namespace HDWCGallery\Updater;

use HDWCGallery\Support\Crypto;
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

defined( 'ABSPATH' ) || exit;

final class GitHubUpdater {

	public const TOKEN_OPTION     = '_hd_wc_gallery_github_token';
	public const ASYNC_CHECK_HOOK = 'hdwcg_async_github_update_check';

	private const GITHUB_REPO = 'https://github.com/HD-Agency/hd-wc-gallery';
	private const BRANCH      = 'main';
	private const SLUG        = 'hd-wc-gallery';

	private static ?self $instance = null;
	private mixed $checker         = null;

	public static function init(): ?self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		if ( ! class_exists( PucFactory::class ) ) {
			return;
		}

		$pluginFile = dirname( __DIR__, 2 ) . '/hd-wc-gallery.php';

		$this->checker = PucFactory::buildUpdateChecker(
			self::GITHUB_REPO,
			$pluginFile,
			self::SLUG
		);

		$this->checker->setBranch( self::BRANCH );

		$token = $this->getToken();
		if ( ! empty( $token ) ) {
			$this->checker->setAuthentication( $token );
		}

		// Configure timeouts
		add_filter( 'http_request_args', [ $this, 'filterHttpTimeouts' ], 10, 2 );

		// Register async cron handler
		add_action( self::ASYNC_CHECK_HOOK, [ $this, 'handleAsyncCheck' ] );

		// Schedule async check when visiting relevant admin screens
		if ( is_admin() ) {
			add_action( 'admin_init', [ $this, 'maybeScheduleAsyncCheck' ] );
		}
	}

	/**
	 * Enforce strict HTTP timeouts on GitHub API update checks.
	 */
	public function filterHttpTimeouts( array $args, string $url ): array {
		if ( ! str_contains( $url, 'api.github.com' ) && ! str_contains( $url, 'github.com/HD-Agency' ) ) {
			return $args;
		}

		$args['timeout'] = wp_doing_cron() ? 5.0 : 2.5;

		return $args;
	}

	/**
	 * Trigger background check via WP-Cron on admin visits (rate-limited to 6 hours).
	 */
	public function maybeScheduleAsyncCheck(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$lastCheck = (int) get_option( '_hdwcg_last_update_check', 0 );
		if ( ( time() - $lastCheck ) < 6 * HOUR_IN_SECONDS ) {
			return;
		}

		if ( ! wp_next_scheduled( self::ASYNC_CHECK_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::ASYNC_CHECK_HOOK );
		}

		if ( ! get_transient( '_hdwcg_puc_async_spawn' ) ) {
			set_transient( '_hdwcg_puc_async_spawn', 1, 300 );
			spawn_cron();
		}
	}

	/**
	 * Handle background cron check.
	 */
	public function handleAsyncCheck(): void {
		update_option( '_hdwcg_last_update_check', time(), false );

		if ( $this->checker && method_exists( $this->checker, 'checkForUpdates' ) ) {
			$this->checker->checkForUpdates();
		}
	}

	/**
	 * Force an immediate update check.
	 */
	public function checkNow(): mixed {
		if ( $this->checker && method_exists( $this->checker, 'checkForUpdates' ) ) {
			return $this->checker->checkForUpdates();
		}

		return null;
	}

	// ── Token resolution ────────────────────────────────────────────────

	private function getToken(): ?string {
		$stored = get_option( self::TOKEN_OPTION, '' );
		if ( ! empty( $stored ) ) {
			$decrypted = Crypto::decrypt( $stored );
			if ( '' !== $decrypted ) {
				return $decrypted;
			}
		}

		return self::getEnvironmentToken();
	}

	/**
	 * Resolve token from environment variables or defined constants.
	 */
	public static function getEnvironmentToken(): ?string {
		if ( defined( 'HDWCG_GITHUB_TOKEN' ) && \HDWCG_GITHUB_TOKEN ) {
			return (string) \HDWCG_GITHUB_TOKEN;
		}

		if ( function_exists( 'env' ) ) {
			$val = env( 'HDWCG_GITHUB_TOKEN' );
			if ( ! empty( $val ) ) {
				return (string) $val;
			}
		}

		if ( ! empty( $_ENV['HDWCG_GITHUB_TOKEN'] ) ) {
			return (string) $_ENV['HDWCG_GITHUB_TOKEN'];
		}

		if ( ! empty( $_SERVER['HDWCG_GITHUB_TOKEN'] ) ) {
			return (string) $_SERVER['HDWCG_GITHUB_TOKEN'];
		}

		$envToken = getenv( 'HDWCG_GITHUB_TOKEN' );
		if ( ! empty( $envToken ) ) {
			return (string) $envToken;
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
		$stored = get_option( self::TOKEN_OPTION, '' );
		if ( ! empty( $stored ) && '' !== Crypto::decrypt( $stored ) ) {
			return 'db';
		}

		if ( null !== self::getEnvironmentToken() ) {
			return 'constant';
		}

		return 'none';
	}
}
