<?php
/**
 * Settings REST API Controller for HD WC Gallery.
 *
 * Exposes endpoints for managing auto-update credentials and token vault.
 *
 * @package HDWCGallery\API
 */

declare(strict_types=1);

namespace HDWCGallery\API;

use HDWCGallery\Support\Crypto;
use HDWCGallery\Updater\GitHubUpdater;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class SettingsController {

	public const REST_NAMESPACE = 'hd-wc-gallery/v1';

	public static function register(): void {
		$instance = new self();
		add_action( 'rest_api_init', [ $instance, 'registerRoutes' ] );
	}

	public function registerRoutes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/settings/github-token',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'getTokenStatus' ],
					'permission_callback' => [ $this, 'checkPermissions' ],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'saveToken' ],
					'permission_callback' => [ $this, 'checkPermissions' ],
					'args'                => [
						'token' => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'deleteToken' ],
					'permission_callback' => [ $this, 'checkPermissions' ],
				],
			]
		);
	}

	public function checkPermissions(): bool {
		return current_user_can( 'manage_options' );
	}

	public function getTokenStatus(): WP_REST_Response {
		return new WP_REST_Response(
			[
				'has_token' => GitHubUpdater::hasToken(),
				'source'    => GitHubUpdater::tokenSource(),
			]
		);
	}

	public function saveToken( WP_REST_Request $request ): WP_REST_Response {
		$token = trim( (string) $request->get_param( 'token' ) );

		if ( '' === $token ) {
			return new WP_REST_Response( [ 'error' => 'Token cannot be empty' ], 400 );
		}

		$encrypted = Crypto::encrypt( $token );
		if ( '' === $encrypted ) {
			return new WP_REST_Response( [ 'error' => 'Failed to encrypt token with Sodium' ], 500 );
		}

		update_option( GitHubUpdater::TOKEN_OPTION, $encrypted, false );

		return new WP_REST_Response(
			[
				'ok'        => true,
				'has_token' => true,
				'source'    => 'db',
			]
		);
	}

	public function deleteToken(): WP_REST_Response {
		delete_option( GitHubUpdater::TOKEN_OPTION );

		return new WP_REST_Response(
			[
				'ok'        => true,
				'has_token' => GitHubUpdater::hasToken(),
				'source'    => GitHubUpdater::tokenSource(),
			]
		);
	}
}
