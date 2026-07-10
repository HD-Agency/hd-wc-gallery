<?php
/**
 * Gallery Setup — frontend initialization hooks.
 *
 * @package HDWCGallery\Frontend
 */

declare(strict_types=1);

namespace HDWCGallery\Frontend;

use WC_Product;

defined( 'ABSPATH' ) || exit;

final class GallerySetup {

	/**
	 * Register all frontend setup hooks.
	 */
	public static function register(): void {
		add_action( 'wp', [ self::class, 'disableBuiltinGallery' ], 99 );
		add_action( 'wp_head', [ self::class, 'preloadLcpImage' ], 1 );
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueueAssets' ] );
	}

	/**
	 * Enqueue the lightweight loader script.
	 */
	public static function enqueueAssets(): void {
		if ( ! is_woocommerce() && ! is_cart() && ! is_checkout() ) {
			return;
		}

		wp_enqueue_script(
			'hd-wc-gallery-loader',
			HD_WC_GALLERY_URL . 'assets/gallery-loader.js',
			[],
			HD_WC_GALLERY_VERSION,
			true
		);

		wp_localize_script(
			'hd-wc-gallery-loader',
			'hdWcGalleryConfig',
			[
				'jsUrl'  => HD_WC_GALLERY_URL . 'assets/gallery-thumbs.js',
				'cssUrl' => HD_WC_GALLERY_URL . 'assets/gallery-thumbs.css',
			]
		);
	}

	/**
	 * Disable WC built-in gallery features on single product.
	 */
	public static function disableBuiltinGallery(): void {
		if ( ! is_product() ) {
			return;
		}

		remove_theme_support( 'wc-product-gallery-zoom' );
		remove_theme_support( 'wc-product-gallery-slider' );
		remove_theme_support( 'wc-product-gallery-lightbox' );
	}

	/**
	 * Output preload hint for main gallery image on single product.
	 */
	public static function preloadLcpImage(): void {
		if ( ! is_product() ) {
			return;
		}

		$product = wc_get_product( get_queried_object_id() );

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$imageId = $product->get_image_id();

		// Account for default variation — preload the image actually displayed
		$defaultVarId = GalleryDataProvider::resolveDefaultVariation( $product );
		if ( $defaultVarId ) {
			$varThumbId = get_post_thumbnail_id( $defaultVarId );
			if ( $varThumbId ) {
				$imageId = $varThumbId;
			}
		}

		if ( ! $imageId ) {
			return;
		}

		$src    = wp_get_attachment_image_url( $imageId, 'woocommerce_single' );
		$srcset = wp_get_attachment_image_srcset( $imageId, 'woocommerce_single' );
		$sizes  = wp_get_attachment_image_sizes( $imageId, 'woocommerce_single' );

		if ( $src ) {
			printf(
				'<link rel="preload" as="image" href="%s"%s%s>' . "\n",
				esc_url( $src ),
				$srcset ? ' imagesrcset="' . esc_attr( $srcset ) . '"' : '',
				$sizes ? ' imagesizes="' . esc_attr( $sizes ) . '"' : ''
			);
		}
	}
}
