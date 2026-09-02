<?php

declare(strict_types=1);

namespace HDWCGallery\Tests\Unit;

use HDWCGallery\API\SettingsController;
use HDWCGallery\Support\Crypto;
use HDWCGallery\Updater\GitHubUpdater;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

final class GitHubUpdaterTest extends TestCase {

	protected function tearDown(): void {
		delete_option( GitHubUpdater::TOKEN_OPTION );
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
}
