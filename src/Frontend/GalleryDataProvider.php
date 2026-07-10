<?php
/**
 * Gallery Data Provider — centralized data building for gallery rendering.
 *
 * @package HDWCGallery\Frontend
 */

declare(strict_types=1);

namespace HDWCGallery\Frontend;

use HDWCGallery\Core\Helper;
use WC_Product;
use WC_Product_Variable;

defined( 'ABSPATH' ) || exit;

final class GalleryDataProvider {

	public const VARIATION_META_KEY   = '_hd_variation_gallery';
	public const PRODUCT_VIDEO_KEY    = '_hd_product_video_url';
	public const PRODUCT_VIDEO_POSTER = '_hd_product_video_poster';
	public const MEDIA_URL_KEY        = '_hd_media_url';

	private const VALID_LAYOUTS = [ 'below', 'above', 'left', 'right', 'stacked' ];

	/**
	 * Normalize layout value — backwards compat for horizontal/vertical.
	 */
	public static function normalizeLayout( string $layout ): string {
		return match ( $layout ) {
			'horizontal' => 'below',
			'vertical'   => 'left',
			default      => in_array( $layout, self::VALID_LAYOUTS, true ) ? $layout : 'below',
		};
	}

	/**
	 * Collect unique, non-empty attachment IDs for a product gallery.
	 *
	 * @param WC_Product $product The product.
	 *
	 * @return int[] Attachment IDs (main image first).
	 */
	public static function collectImageIds( WC_Product $product ): array {
		$mainImageId = $product->get_image_id();
		$galleryIds  = $product->get_gallery_image_ids();

		return array_values(
			array_unique(
				array_filter(
					$mainImageId ? array_merge( [ $mainImageId ], $galleryIds ) : $galleryIds
				)
			)
		);
	}

	/**
	 * Build image data for a single attachment.
	 *
	 * @param int $attachmentId Attachment post ID.
	 * @param int $productId    Product post ID (used for alt fallback).
	 *
	 * @return array
	 */
	public static function getImageData( int $attachmentId, int $productId ): array {
		$srcData = wp_get_attachment_image_src( $attachmentId, 'woocommerce_single' );

		$data = [
			'src'    => $srcData ? $srcData[0] : '',
			'width'  => $srcData ? $srcData[1] : 0,
			'height' => $srcData ? $srcData[2] : 0,
			'thumb'  => Helper::attachmentImageSrc( $attachmentId, 'woocommerce_thumbnail' ) ?: '',
			'full'   => Helper::attachmentImageSrc( $attachmentId, 'full' ) ?: '',
			'srcset' => wp_get_attachment_image_srcset( $attachmentId, 'woocommerce_single' ) ?: '',
			'sizes'  => wp_get_attachment_image_sizes( $attachmentId, 'woocommerce_single' ) ?: '',
			'alt'    => get_post_meta( $attachmentId, '_wp_attachment_image_alt', true )
						?: get_the_title( $productId ),
		];

		// F5: Check for attached video URL
		$mediaUrl = get_post_meta( $attachmentId, self::MEDIA_URL_KEY, true );
		if ( $mediaUrl ) {
			$data['video']      = $mediaUrl;
			$data['video_type'] = VideoHelper::detectType( $mediaUrl );
		}

		return $data;
	}

	/**
	 * Build image data array for all product images.
	 *
	 * @param int[] $attachmentIds Attachment IDs.
	 * @param int   $productId    Product post ID.
	 *
	 * @return array[] Array of image data.
	 */
	public static function buildImagesData( array $attachmentIds, int $productId ): array {
		return array_map(
			static fn( int $id ) => self::getImageData( $id, $productId ),
			$attachmentIds
		);
	}

	/**
	 * Get variation galleries — ONLY for variations that have custom gallery.
	 *
	 * @param WC_Product $product The parent product.
	 *
	 * @return array
	 */
	public static function getVariationGalleries( WC_Product $product ): array {
		if ( ! $product instanceof WC_Product_Variable ) {
			return [];
		}

		$childrenIds = $product->get_children();
		if ( ! $childrenIds ) {
			return [];
		}

		// Batch preload variation meta
		update_meta_cache( 'post', $childrenIds );

		$variationGalleryMap = [];
		$allAttachmentIds    = [];

		foreach ( $childrenIds as $variationId ) {
			$galleryIds = get_post_meta( $variationId, self::VARIATION_META_KEY, true );

			if ( empty( $galleryIds ) ) {
				continue;
			}

			$galleryIds = array_filter( array_map( 'absint', (array) $galleryIds ) );
			if ( empty( $galleryIds ) ) {
				continue;
			}

			// Prepend variation image if exists
			$variationImageId = get_post_thumbnail_id( $variationId );
			if ( $variationImageId && ! in_array( $variationImageId, $galleryIds, true ) ) {
				array_unshift( $galleryIds, absint( $variationImageId ) );
			}

			$variationGalleryMap[ $variationId ] = $galleryIds;
			array_push( $allAttachmentIds, ...$galleryIds );
		}

		if ( ! $variationGalleryMap ) {
			return [];
		}

		$allAttachmentIds = array_values( array_unique( $allAttachmentIds ) );
		_prime_post_caches( $allAttachmentIds, false, true );

		$galleries = [];
		$productId = $product->get_id();

		foreach ( $variationGalleryMap as $variationId => $galleryIds ) {
			$galleries[ $variationId ] = self::buildImagesData( $galleryIds, $productId );
		}

		return $galleries;
	}

	/**
	 * Resolve default variation ID from request or product defaults.
	 *
	 * @param WC_Product $product The product.
	 *
	 * @return int|null Variation ID or null.
	 */
	public static function resolveDefaultVariation( WC_Product $product ): ?int {
		if ( ! $product instanceof WC_Product_Variable ) {
			return null;
		}

		$dataStore = \WC_Data_Store::load( 'product' );

		// 1. Check URL param
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$requestedId = absint( $_REQUEST['variation_id'] ?? 0 );
		if (
			$requestedId
			&& 'product_variation' === get_post_type( $requestedId )
			&& $product->get_id() === (int) wp_get_post_parent_id( $requestedId )
		) {
			return $requestedId;
		}

		// 2. Check attribute_* URL params
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$urlAttrs = [];
		foreach ( $_GET as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( str_starts_with( $key, 'attribute_' ) && '' !== $value ) {
				$urlAttrs[ sanitize_title( $key ) ] = sanitize_text_field( wp_unslash( $value ) );
			}
		}

		if ( ! empty( $urlAttrs ) ) {
			$variationId = $dataStore->find_matching_product_variation( $product, $urlAttrs );
			if ( $variationId ) {
				return $variationId;
			}
		}

		// 3. Fallback: product default attributes.
		$defaults = $product->get_default_attributes();
		if ( empty( $defaults ) ) {
			return null;
		}

		$attrs = [];
		foreach ( $defaults as $key => $value ) {
			if ( '' !== $value ) {
				$attrs[ "attribute_{$key}" ] = $value;
			}
		}

		if ( empty( $attrs ) ) {
			return null;
		}

		$variationId = $dataStore->find_matching_product_variation( $product, $attrs );

		return $variationId ?: null;
	}

	/**
	 * Merge variation images with default images (prepend mode).
	 */
	public static function mergeVariationImages( array $varImages, array $defaultImages ): array {
		$seen = [];
		foreach ( $varImages as $img ) {
			$seen[ $img['src'] ] = true;
		}

		$remaining = array_filter(
			$defaultImages,
			static fn( $img ) => ! isset( $seen[ $img['src'] ] )
		);

		return array_merge( $varImages, array_values( $remaining ) );
	}
}
