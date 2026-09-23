<?php
/**
 * Unit Tests — GitHubUpdater (HD WC Gallery).
 *
 * Covers cooldown logic, manual check bypass, package integrity guard,
 * Live Push response rendering, and SettingsController endpoints.
 *
 * @package HDWCGallery\Tests\Unit
 */

declare(strict_types=1);

namespace HDWCGallery\Tests\Unit;

use HDWCGallery\API\SettingsController;
use HDWCGallery\Plugin;
use HDWCGallery\Support\Crypto;
use HDWCGallery\Updater\GitHubUpdater;
use PHPUnit\Framework\TestCase;
use stdClass;
use WP_Error;
use WP_REST_Request;

final class GitHubUpdaterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wp_test_transients']     = [];
		$GLOBALS['wp_test_options']        = [];
		$GLOBALS['hd_test_user_can']       = true;
		$GLOBALS['wp_test_inline_scripts'] = [];
		$GLOBALS['wp_test_rest_routes']    = [];
		$_GET                              = [];
	}

	protected function tearDown(): void {
		delete_option( GitHubUpdater::TOKEN_OPTION );
		$_GET                          = [];
		$GLOBALS['wp_test_transients'] = [];
		parent::tearDown();
	}

	public function testCryptoEncryptDecryptRoundtrip(): void {
		$secret    = 'ghp_test_token_1234567890abcdef';
		$encrypted = Crypto::encrypt( $secret );

		$this->assertNotEmpty( $encrypted );
		$this->assertNotSame( $secret, $encrypted );

		$decrypted = Crypto::decrypt( $encrypted );
		$this->assertSame( $secret, $decrypted );
	}

	public function testCryptoEmptyStringsAndInvalidPayloads(): void {
		$this->assertSame( '', Crypto::encrypt( '' ) );
		$this->assertSame( '', Crypto::decrypt( '' ) );
		$this->assertSame( '', Crypto::decrypt( 'invalid_base64_not_encrypted' ) );
		$this->assertSame( '', Crypto::decrypt( base64_encode( 'short' ) ) );
	}

	public function testTokenSourceReportsDbWhenEncryptedOptionExists(): void {
		$token     = 'ghp_db_token_xyz987';
		$encrypted = Crypto::encrypt( $token );
		update_option( GitHubUpdater::TOKEN_OPTION, $encrypted, false );

		$this->assertSame( 'db', GitHubUpdater::tokenSource() );
		$this->assertTrue( GitHubUpdater::hasToken() );
	}

	public function testSettingsControllerEndpoints(): void {
		$controller = new SettingsController();

		// Initial state: token from environment or none
		$statusRes = $controller->getTokenStatus();
		$this->assertSame( 200, $statusRes->get_status() );
		$data = $statusRes->get_data();
		$this->assertArrayHasKey( 'has_token', $data );
		$this->assertArrayHasKey( 'source', $data );

		// Save Token
		$request = new WP_REST_Request( 'POST', '/hd-wc-gallery/v1/settings/github-token' );
		$request->set_param( 'token', 'ghp_new_rest_token_456' );
		$saveRes = $controller->saveToken( $request );
		$this->assertSame( 200, $saveRes->get_status() );
		$saveData = $saveRes->get_data();
		$this->assertTrue( $saveData['ok'] );
		$this->assertSame( 'db', $saveData['source'] );

		// Delete Token
		$delRes = $controller->deleteToken();
		$this->assertSame( 200, $delRes->get_status() );
		$delData = $delRes->get_data();
		$this->assertTrue( $delData['ok'] );
	}

	public function testSaveEmptyTokenReturnsBadRequest(): void {
		$controller = new SettingsController();
		$request    = new WP_REST_Request( 'POST', '/hd-wc-gallery/v1/settings/github-token' );
		$request->set_param( 'token', '   ' );
		$res = $controller->saveToken( $request );

		$this->assertSame( 400, $res->get_status() );
	}

	public function test_filter_http_request_options_sets_timeouts(): void {
		$updater = new GitHubUpdater();

		$options = $updater->filterHttpRequestOptions( [ 'timeout' => 30 ] );
		$this->assertSame( 2.5, $options['timeout'] );
	}

	public function test_token_source_resolution(): void {
		$hasToken = GitHubUpdater::hasToken();
		$source   = GitHubUpdater::tokenSource();

		$this->assertIsBool( $hasToken );
		$this->assertContains( $source, [ 'db', 'constant', 'none' ] );
	}

	public function test_should_check_now_blocks_passive_web_browsing(): void {
		$updater = new GitHubUpdater();
		// In passive web browsing without cron or query params, shouldCheckNow must return false (0ms latency).
		$this->assertFalse( $updater->shouldCheckNow( true ) );
		$this->assertFalse( $updater->shouldCheckNow( false ) );
	}

	public function test_should_check_now_allows_manual_check_and_resets_cooldown(): void {
		$updater = new GitHubUpdater();
		set_transient( GitHubUpdater::COOLDOWN_TRANSIENT, 1, 7200 );
		$this->assertTrue( (bool) get_transient( GitHubUpdater::COOLDOWN_TRANSIENT ) );

		$_GET['puc_check_for_updates'] = '1';
		$_GET['puc_slug']              = 'hd-wc-gallery';

		$allowed = $updater->shouldCheckNow( true );
		$this->assertTrue( $allowed );
		// Manual check must clear cooldown transient so next checks are fresh.
		$this->assertFalse( (bool) get_transient( GitHubUpdater::COOLDOWN_TRANSIENT ) );
	}

	public function test_validate_package_integrity_passes_when_core_files_exist(): void {
		global $wp_filesystem;

		$wp_filesystem = new class() {
			public function exists( string $path ): bool {
				return true;
			}
		};

		$updater        = new GitHubUpdater();
		$upgrader       = new stdClass();
		$skin           = new stdClass();
		$skin->plugin   = 'hd-wc-gallery/hd-wc-gallery.php';
		$upgrader->skin = $skin;

		$source = '/tmp/upgrade/hd-wc-gallery.tmp/hd-wc-gallery/';
		$result = $updater->validatePackageIntegrity( $source, '/tmp/upgrade/', $upgrader );

		$this->assertSame( $source, $result );
	}

	public function test_validate_package_integrity_aborts_when_core_files_are_missing(): void {
		global $wp_filesystem;

		// Simulate archive missing vendor/autoload.php
		$wp_filesystem = new class() {
			public function exists( string $path ): bool {
				if ( str_ends_with( $path, 'vendor/autoload.php' ) ) {
					return false;
				}
				return true;
			}
		};

		$updater        = new GitHubUpdater();
		$upgrader       = new stdClass();
		$skin           = new stdClass();
		$skin->plugin   = 'hd-wc-gallery/hd-wc-gallery.php';
		$upgrader->skin = $skin;

		$source = '/tmp/upgrade/hd-wc-gallery.tmp/hd-wc-gallery/';
		$result = $updater->validatePackageIntegrity( $source, '/tmp/upgrade/', $upgrader );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'hdwcg_corrupted_package', $result->get_error_code() );
		$this->assertStringContainsString( 'vendor/autoload.php', $result->get_error_message() );
	}

	public function test_validate_package_integrity_handles_null_or_invalid_source(): void {
		$updater = new GitHubUpdater();

		// 1. Existing WP_Error passed through
		$inputError = new WP_Error( 'upgrader_pre_error', 'Pre-existing error' );
		$result     = $updater->validatePackageIntegrity( $inputError, null, null );
		$this->assertSame( $inputError, $result );

		// 2. Null source returns WP_Error, never null
		$resultNull = $updater->validatePackageIntegrity( null, null, null );
		$this->assertInstanceOf( WP_Error::class, $resultNull );
		$this->assertSame( 'hdwcg_invalid_source', $resultNull->get_error_code() );

		// 3. Empty string source returns WP_Error
		$resultEmpty = $updater->validatePackageIntegrity( '', null, null );
		$this->assertInstanceOf( WP_Error::class, $resultEmpty );
		$this->assertSame( 'hdwcg_invalid_source', $resultEmpty->get_error_code() );
	}

	public function test_validate_package_integrity_identifies_hdwcg_via_hook_extra(): void {
		global $wp_filesystem;

		// Simulate missing hd-wc-gallery.php in archive
		$wp_filesystem = new class() {
			public function exists( string $path ): bool {
				return ! str_ends_with( $path, 'hd-wc-gallery.php' );
			}
		};

		$updater   = new GitHubUpdater();
		$upgrader  = new stdClass();
		$hookExtra = [ 'plugin' => 'hd-wc-gallery/hd-wc-gallery.php' ];

		$result = $updater->validatePackageIntegrity( '/tmp/upgrade/custom-dir/', '/tmp/upgrade/', $upgrader, $hookExtra );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'hdwcg_corrupted_package', $result->get_error_code() );
		$this->assertStringContainsString( 'hd-wc-gallery.php', $result->get_error_message() );
	}

	public function test_build_update_row_html_contains_version_and_native_markup(): void {
		$updater = new GitHubUpdater();
		$html    = $updater->buildUpdateRowHtml( '1.0.2' );

		$this->assertStringContainsString( 'plugin-update-tr', $html );
		$this->assertStringContainsString( 'id="hdwcg-update"', $html );
		$this->assertStringContainsString( '1.0.2', $html );
		$this->assertStringContainsString( 'update-link', $html );
		$this->assertStringContainsString( 'update.php?action=upgrade-plugin', $html );
	}

	public function test_check_rest_permission_requires_update_plugins_cap_and_nonce(): void {
		$updater = new GitHubUpdater();
		$request = new WP_REST_Request( 'GET', '/hd-wc-gallery/v1/updater/check' );

		// 1. Without nonce -> forbidden
		$this->assertFalse( $updater->checkRestPermission( $request ) );

		// 2. With valid nonce and cap -> allowed
		$request->set_header( 'X-WP-Nonce', 'valid-nonce' );
		$this->assertTrue( $updater->checkRestPermission( $request ) );

		// 3. Lacking capability -> forbidden
		$GLOBALS['hd_test_user_can'] = false;
		$this->assertFalse( $updater->checkRestPermission( $request ) );
	}

	public function test_handle_rest_check_returns_false_when_no_update(): void {
		$updater  = new GitHubUpdater();
		$request  = new WP_REST_Request( 'GET', '/hd-wc-gallery/v1/updater/check' );
		$response = $updater->handleRestCheck( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'has_update', $data );
		$this->assertFalse( $data['has_update'] );
		$this->assertArrayHasKey( 'current_version', $data );
	}

	public function test_enqueue_plugins_script_runs_on_plugins_and_update_core(): void {
		$updater = new GitHubUpdater();

		// On other admin pages like edit.php -> do not enqueue
		$GLOBALS['wp_test_inline_scripts'] = [];
		$updater->enqueuePluginsScript( 'edit.php' );
		$this->assertEmpty( $GLOBALS['wp_test_inline_scripts'] );

		// On plugins.php with capability -> enqueues inline script
		$updater->enqueuePluginsScript( 'plugins.php' );
		$this->assertNotEmpty( $GLOBALS['wp_test_inline_scripts'] );

		// On update-core.php with capability -> enqueues inline script
		$GLOBALS['wp_test_inline_scripts'] = [];
		$updater->enqueuePluginsScript( 'update-core.php' );
		$this->assertNotEmpty( $GLOBALS['wp_test_inline_scripts'] );
	}

	public function test_should_check_now_blocks_on_passive_update_core(): void {
		$updater        = new GitHubUpdater();
		$oldDoingAction = $GLOBALS['wp_test_doing_action'] ?? null;
		$oldGet         = $_GET;

		try {
			$GLOBALS['wp_test_doing_action'] = 'load-update-core.php';
			$_GET                            = [];

			$this->assertFalse( $updater->shouldCheckNow( true ) );
		} finally {
			$GLOBALS['wp_test_doing_action'] = $oldDoingAction;
			$_GET                            = $oldGet;
		}
	}

	public function test_should_check_now_allows_on_explicit_force_check(): void {
		$updater        = new GitHubUpdater();
		$oldDoingAction = $GLOBALS['wp_test_doing_action'] ?? null;
		$oldGet         = $_GET;

		try {
			$GLOBALS['wp_test_doing_action'] = 'load-update-core.php';
			$_GET['force-check']             = '1';

			$this->assertTrue( $updater->shouldCheckNow( true ) );
		} finally {
			$GLOBALS['wp_test_doing_action'] = $oldDoingAction;
			$_GET                            = $oldGet;
		}
	}

	public function test_is_rest_request_detection(): void {
		$oldServer = $_SERVER;
		$oldGet    = $_GET;

		try {
			// 1. Regular web page
			$_SERVER['REQUEST_URI'] = '/sample-page/';
			$_GET                   = [];
			$this->assertFalse( Plugin::isRestRequest() );

			// 2. REST API pretty permalink
			$_SERVER['REQUEST_URI'] = '/wp-json/hd-wc-gallery/v1/updater/check';
			$this->assertTrue( Plugin::isRestRequest() );

			// 3. REST API query parameter
			$_SERVER['REQUEST_URI'] = '/index.php';
			$_GET['rest_route']     = '/hd-wc-gallery/v1/updater/check';
			$this->assertTrue( Plugin::isRestRequest() );
		} finally {
			$_SERVER = $oldServer;
			$_GET    = $oldGet;
		}
	}

	public function test_register_rest_routes_registers_endpoint_and_is_idempotent(): void {
		$GLOBALS['wp_test_rest_routes'] = [];
		$updater                        = new GitHubUpdater();

		$updater->registerRestRoutes();
		$this->assertArrayHasKey( 'hd-wc-gallery/v1', $GLOBALS['wp_test_rest_routes'] );
		$this->assertArrayHasKey( '/updater/check', $GLOBALS['wp_test_rest_routes']['hd-wc-gallery/v1'] );

		// Second invocation must be a no-op (idempotent)
		$updater->registerRestRoutes();
		$this->assertArrayHasKey( '/updater/check', $GLOBALS['wp_test_rest_routes']['hd-wc-gallery/v1'] );
	}

	public function test_filter_update_detection_strategies_unsets_latest_release_and_tag(): void {
		$updater    = new GitHubUpdater();
		$strategies = [
			'latest_release' => static fn() => 'release',
			'latest_tag'     => static fn() => 'tag',
			'branch'         => static fn() => 'branch',
		];

		$filtered = $updater->filterUpdateDetectionStrategies( $strategies );

		$this->assertArrayNotHasKey( 'latest_release', $filtered );
		$this->assertArrayNotHasKey( 'latest_tag', $filtered );
		$this->assertArrayHasKey( 'branch', $filtered );
	}

	public function test_get_cooldown_remaining_returns_correct_duration(): void {
		$updater = new GitHubUpdater();

		// Case 1: No transient set -> returns 0
		$this->assertSame( 0, $updater->getCooldownRemaining() );

		// Case 2: Transient with absolute timestamp set
		$future = time() + 120;
		set_transient( GitHubUpdater::COOLDOWN_TRANSIENT, $future, 120 );
		$remaining = $updater->getCooldownRemaining();
		$this->assertGreaterThanOrEqual( 118, $remaining );
		$this->assertLessThanOrEqual( 120, $remaining );
	}

	public function test_enqueue_plugins_script_skips_when_update_already_in_transient(): void {
		$updater = new GitHubUpdater();

		$pluginBasename      = defined( 'HD_WC_GALLERY_PLUGIN_BASENAME' ) ? HD_WC_GALLERY_PLUGIN_BASENAME : 'hd-wc-gallery/hd-wc-gallery.php';
		$transient           = new stdClass();
		$update              = new stdClass();
		$update->new_version = '2.0.0';
		$transient->response = [ $pluginBasename => $update ];
		set_site_transient( 'update_plugins', $transient );

		$GLOBALS['wp_test_inline_scripts'] = [];
		$updater->enqueuePluginsScript( 'plugins.php' );

		// Script enqueuing must be bypassed because WP Core will render the notice row server-side.
		$this->assertEmpty( $GLOBALS['wp_test_inline_scripts'] );

		// Clean up
		delete_site_transient( 'update_plugins' );
	}

	public function test_enqueue_plugins_script_includes_deduplication_and_data_plugin_selectors(): void {
		$updater                           = new GitHubUpdater();
		$GLOBALS['wp_test_inline_scripts'] = [];

		$updater->enqueuePluginsScript( 'plugins.php' );

		$this->assertNotEmpty( $GLOBALS['wp_test_inline_scripts'] );
		$this->assertArrayHasKey( 'hdwcg-updater-live', $GLOBALS['wp_test_inline_scripts'] );
		$script = implode( "\n", $GLOBALS['wp_test_inline_scripts']['hdwcg-updater-live'] );

		// Verify DOM presence check and deduplication selectors
		$this->assertStringContainsString( 'hasUpdateNotice', $script );
		$this->assertStringContainsString( 'data-plugin', $script );
		$this->assertStringContainsString( 'hd-wc-gallery', $script );
		$this->assertStringContainsString( 'plugin-update-tr', $script );
		$this->assertStringContainsString( 'existing.remove()', $script );
		$this->assertStringContainsString( 'targetRow.classList.contains("update")', $script );
	}
}
