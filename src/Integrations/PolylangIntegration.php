<?php
/**
 * Polylang integration for WooCommerce Gallery.
 *
 * @package HDWCGallery\Integrations
 */

declare(strict_types=1);

namespace HDWCGallery\Integrations;

use HDWCGallery\Frontend\GalleryDataProvider;

defined( 'ABSPATH' ) || exit;

final class PolylangIntegration {

	/**
	 * Register Polylang hooks.
	 */
	public static function register(): void {
		add_filter( 'pll_copy_post_metas', [ self::class, 'addPllMetas' ] );
	}

	/**
	 * Register gallery meta keys for Polylang content duplication sync.
	 */
	public static function addPllMetas( array $metas ): array {
		$metas[] = GalleryDataProvider::PRODUCT_VIDEO_KEY;
		$metas[] = GalleryDataProvider::PRODUCT_VIDEO_POSTER;

		return array_unique( $metas );
	}
}
