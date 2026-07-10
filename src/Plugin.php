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

		// Frontend hooks & setup
		Frontend\GallerySetup::register();
		( new Frontend\GalleryRenderer() )->register();

		// REST API & Integrations
		Integrations\PolylangIntegration::register();
		( new API\GalleryAPI() )->register();

		// Admin data entry fields
		if ( is_admin() ) {
			Admin\ProductVideoFields::register();
			( new Admin\VariationGalleryPicker() )->register();
			( new Admin\GalleryMediaFields() )->register();
		}
	}
}
