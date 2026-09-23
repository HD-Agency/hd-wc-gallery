<?php
/**
 * Plugin Main Orchestrator.
 *
 * @package HDWCGallery
 */

declare(strict_types=1);

namespace HDWCGallery;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/**
	 * Boot the plugin.
	 */
	public static function boot(): void {
		// Load textdomain
		add_action( 'init', static fn() => load_plugin_textdomain( 'hd-wc-gallery', false, dirname( HD_WC_GALLERY_PLUGIN_BASENAME ) . '/languages' ) );

		// Settings
		Admin\Settings::register();

		// Auto-update via GitHub — admin, cron, CLI contexts, and REST API.
		if ( is_admin() || ( defined( 'DOING_CRON' ) && DOING_CRON ) || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || self::isRestRequest() ) {
			Updater\GitHubUpdater::init();
		} else {
			add_action(
				'rest_api_init',
				static function (): void {
					Updater\GitHubUpdater::init();
				}
			);
		}

		// Frontend hooks & setup
		Frontend\GallerySetup::register();
		( new Frontend\GalleryRenderer() )->register();

		// REST API & Integrations
		Integrations\PolylangIntegration::register();
		( new API\GalleryAPI() )->register();
		API\SettingsController::register();

		// Admin data entry fields
		if ( is_admin() ) {
			Admin\ProductVideoFields::register();
			( new Admin\VariationGalleryPicker() )->register();
			( new Admin\GalleryMediaFields() )->register();
		}
	}

	/**
	 * Determine whether the current request is a WordPress REST API request.
	 */
	public static function isRestRequest(): bool {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		if ( ! empty( $_GET['rest_route'] ) ) {
			return true;
		}

		$uri = $_SERVER['REQUEST_URI'] ?? '';
		if ( empty( $uri ) ) {
			return false;
		}

		$restPrefix = function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json';

		return false !== strpos( (string) $uri, '/' . $restPrefix );
	}
}
