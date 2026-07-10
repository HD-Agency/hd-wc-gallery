<?php
/**
 * Plugin Name: HD WC Gallery
 * Plugin URI: https://webhd.vn
 * Version: 1.0.0
 * Requires PHP: 8.1
 * Author: HD
 * Author URI: https://webhd.vn
 * Description: Standalone, independent WooCommerce Gallery plugin with Swiper, PhotoSwipe lightbox, zoom, and product video support.
 * License: MIT
 */

use HDWCGallery\Plugin;

defined( 'ABSPATH' ) || exit;

// Prevent double loading.
if ( defined( 'HD_WC_GALLERY_VERSION' ) ) {
	return;
}

// ── Constants ───────────────────────────────────

const HD_WC_GALLERY_VERSION = '1.0.0';

define( 'HD_WC_GALLERY_PATH', untrailingslashit( plugin_dir_path( __FILE__ ) ) . DIRECTORY_SEPARATOR );
define( 'HD_WC_GALLERY_URL', untrailingslashit( plugin_dir_url( __FILE__ ) ) . '/' );
define( 'HD_WC_GALLERY_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// ── Guards ──────────────────────────────────────

// PHP version guard.
if ( PHP_VERSION_ID < 80100 ) {
	add_action(
		'admin_notices',
		static fn() => printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( 'HD WC Gallery requires PHP 8.1 or newer. Please upgrade your PHP version.' )
		)
	);
	return;
}

// Autoload guard.
$hd_wc_gallery_autoload = __DIR__ . '/vendor/autoload.php';
if ( is_file( $hd_wc_gallery_autoload ) ) {
	require_once $hd_wc_gallery_autoload;
}

if ( ! class_exists( Plugin::class ) ) {
	add_action(
		'admin_notices',
		static fn() => printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( 'HD WC Gallery: missing vendor directory. Please run composer install.' )
		)
	);
	return;
}

// ── Bootstrap ───────────────────────────────────

add_action( 'plugins_loaded', [ Plugin::class, 'boot' ], 15 );
