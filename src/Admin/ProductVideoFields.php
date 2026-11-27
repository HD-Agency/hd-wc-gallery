<?php
/**
 * Product Video Fields — per-product video URL + poster meta fields.
 *
 * Renders video URL and poster URL fields in the WooCommerce General
 * product data tab. Saves to `_hd_product_video_url` and
 * `_hd_product_video_poster` post meta.
 *
 * @package HDWCGallery\Admin
 */

declare(strict_types=1);

namespace HDWCGallery\Admin;

use HDWCGallery\Frontend\GalleryDataProvider;

defined( 'ABSPATH' ) || exit;

final class ProductVideoFields {

	private const VIDEO_KEY  = GalleryDataProvider::PRODUCT_VIDEO_KEY;
	private const POSTER_KEY = GalleryDataProvider::PRODUCT_VIDEO_POSTER;

	/**
	 * Register admin hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_product_options_general_product_data', [ self::class, 'renderFields' ] );
		add_action( 'woocommerce_process_product_meta', [ self::class, 'saveFields' ] );
	}

	/**
	 * Render video URL + poster fields in the General product data tab.
	 */
	public static function renderFields(): void {
		echo '<div class="options_group">';
		woocommerce_wp_text_input(
			[
				'id'          => self::VIDEO_KEY,
				'label'       => __( 'Product Video URL', 'hd-wc-gallery' ),
				'desc_tip'    => true,
				'description' => __( 'YouTube, Vimeo, or MP4/WEBM URL. Displayed in gallery based on Video Position setting.', 'hd-wc-gallery' ),
				'type'        => 'text',
				'placeholder' => 'https://www.youtube.com/watch?v=...',
			]
		);
		woocommerce_wp_text_input(
			[
				'id'          => self::POSTER_KEY,
				'label'       => __( 'Video Poster URL', 'hd-wc-gallery' ),
				'desc_tip'    => true,
				'description' => __( 'Optional. Custom poster image for the video. Auto-extracted for YouTube if left empty.', 'hd-wc-gallery' ),
				'type'        => 'text',
				'placeholder' => 'https://...',
			]
		);
		echo '</div>';
	}

	/**
	 * Save per-product video URL + poster meta.
	 *
	 * @param int $postId Product post ID.
	 */
	public static function saveFields( int $postId ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WC verifies nonce before firing woocommerce_process_product_meta
		$videoUrl  = isset( $_POST[ self::VIDEO_KEY ] )
			? sanitize_url( wp_unslash( $_POST[ self::VIDEO_KEY ] ) )
			: '';
		$posterUrl = isset( $_POST[ self::POSTER_KEY ] )
			? sanitize_url( wp_unslash( $_POST[ self::POSTER_KEY ] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $videoUrl ) {
			update_post_meta( $postId, self::VIDEO_KEY, $videoUrl );
		} else {
			delete_post_meta( $postId, self::VIDEO_KEY );
		}

		if ( $posterUrl ) {
			update_post_meta( $postId, self::POSTER_KEY, $posterUrl );
		} else {
			delete_post_meta( $postId, self::POSTER_KEY );
		}
	}
}
