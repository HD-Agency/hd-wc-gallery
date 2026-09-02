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

	public const VARIATION_META_KEY     = '_hd_variation_gallery';
	public const META_VARIATION_GALLERY = '_hd_variation_gallery';
	public const PRODUCT_VIDEO_KEY      = '_hd_product_video_url';
	public const META_VIDEO_URL         = '_hd_product_video_url';
	public const PRODUCT_VIDEO_POSTER   = '_hd_product_video_poster';
	public const META_VIDEO_POSTER      = '_hd_product_video_poster';
	public const MEDIA_URL_KEY          = '_hd_media_url';

	private const VALID_LAYOUTS = [ 'below', 'left', 'right', 'stacked' ];

	/**
	 * Normalize layout value.
	 */
	public static function normalizeLayout( string $layout ): string {
		return match ( $layout ) {
			'horizontal' => 'below',
			'vertical'   => 'left',
			'above'      => 'below',
			default      => in_array( $layout, self::VALID_LAYOUTS, true ) ? $layout : 'below',
		};
	}

	/**
	 * Collect unique, non-empty attachment IDs for a product gallery.
	 *
	 * @param WC_Product $product
	 * @return int[]
	 */
	public static function collectImageIds( WC_Product $product ): array {
		$mainImageId = (int) $product->get_image_id();
		$galleryIds  = (array) $product->get_gallery_image_ids();

		$ids = [];
		if ( $mainImageId > 0 ) {
			$ids[] = $mainImageId;
		}

		foreach ( $galleryIds as $id ) {
			$idInt = (int) $id;
			if ( $idInt > 0 && ! in_array( $idInt, $ids, true ) ) {
				$ids[] = $idInt;
			}
		}

		return $ids;
	}

	/**
	 * Build structured image data for a single attachment.
	 *
	 * @param int $attachmentId
	 * @param int $productId
	 * @return array<string, mixed>
	 */
	public static function getImageData( int $attachmentId, int $productId ): array {
		$largeSrc = (string) wp_get_attachment_image_url( $attachmentId, 'woocommerce_single' );
		$fullSrc  = (string) wp_get_attachment_image_url( $attachmentId, 'full' );
		$thumbSrc = (string) wp_get_attachment_image_url( $attachmentId, 'woocommerce_gallery_thumbnail' );

		if ( empty( $largeSrc ) ) {
			$largeSrc = $fullSrc;
		}
		if ( empty( $thumbSrc ) ) {
			$thumbSrc = $largeSrc;
		}

		$meta   = wp_get_attachment_metadata( $attachmentId );
		$width  = isset( $meta['width'] ) ? (int) $meta['width'] : 1000;
		$height = isset( $meta['height'] ) ? (int) $meta['height'] : 1000;

		$alt = (string) get_post_meta( $attachmentId, '_wp_attachment_image_alt', true );
		if ( empty( $alt ) ) {
			$alt = (string) get_the_title( $productId );
		}

		$srcset = (string) wp_get_attachment_image_srcset( $attachmentId, 'woocommerce_single' );
		$sizes  = (string) wp_get_attachment_image_sizes( $attachmentId, 'woocommerce_single' );

		$data = [
			'id'        => $attachmentId,
			'src'       => $largeSrc,
			'large_src' => $fullSrc,
			'thumb_src' => $thumbSrc,
			'thumb'     => $thumbSrc,
			'full'      => $fullSrc,
			'width'     => $width,
			'height'    => $height,
			'srcset'    => $srcset,
			'sizes'     => $sizes,
			'alt'       => $alt,
			'title'     => (string) get_the_title( $attachmentId ),
			'is_video'  => false,
		];

		// Check for attached media video URL
		$mediaUrl = (string) get_post_meta( $attachmentId, self::MEDIA_URL_KEY, true );
		if ( ! empty( $mediaUrl ) && VideoHelper::isVideoUrl( $mediaUrl ) ) {
			$data['is_video']   = true;
			$data['video_url']  = $mediaUrl;
			$data['video_type'] = (string) VideoHelper::resolveVideoType( $mediaUrl );
			$data['video']      = $mediaUrl;
		}

		return $data;
	}

	/**
	 * Build image data array for all product images.
	 *
	 * @param int[] $attachmentIds
	 * @param int   $productId
	 * @return list<array<string, mixed>>
	 */
	public static function buildImagesData( array $attachmentIds, int $productId ): array {
		if ( empty( $attachmentIds ) ) {
			return [];
		}

		return array_values(
			array_map(
				static fn( int $id ) => self::getImageData( $id, $productId ),
				$attachmentIds
			)
		);
	}

	/**
	 * Get structured gallery data array for a given WooCommerce product.
	 *
	 * @param WC_Product $product
	 * @return array{
	 *     product_id: int,
	 *     video: array{url: string, type: string, embed_url: ?string}|null,
	 *     items: list<array<string, mixed>>
	 * }
	 */
	public static function getGalleryData( WC_Product $product ): array {
		$productId     = $product->get_id();
		$attachmentIds = self::collectImageIds( $product );

		// Resolve video if configured on product level
		$videoUrl  = (string) get_post_meta( $productId, self::PRODUCT_VIDEO_KEY, true );
		$videoData = null;
		if ( ! empty( $videoUrl ) && VideoHelper::isVideoUrl( $videoUrl ) ) {
			$videoType = (string) VideoHelper::resolveVideoType( $videoUrl );
			$videoData = [
				'url'       => $videoUrl,
				'type'      => $videoType,
				'embed_url' => VideoHelper::buildEmbedUrl( $videoUrl, true ),
			];
		}

		// Fallback placeholder if no images exist
		if ( empty( $attachmentIds ) ) {
			$placeholderUrl = function_exists( 'wc_placeholder_img_src' )
				? wc_placeholder_img_src( 'woocommerce_single' )
				: '';

			$placeholderItem = [
				'id'        => 0,
				'src'       => $placeholderUrl,
				'large_src' => $placeholderUrl,
				'thumb_src' => $placeholderUrl,
				'thumb'     => $placeholderUrl,
				'full'      => $placeholderUrl,
				'srcset'    => '',
				'sizes'     => '',
				'alt'       => (string) $product->get_name(),
				'title'     => (string) $product->get_name(),
				'width'     => 800,
				'height'    => 800,
				'is_video'  => false,
			];

			if ( $videoData !== null ) {
				$placeholderItem['is_video']   = true;
				$placeholderItem['video_url']  = $videoData['url'];
				$placeholderItem['video_type'] = $videoData['type'];
			}

			return [
				'product_id' => $productId,
				'video'      => $videoData,
				'items'      => [ $placeholderItem ],
			];
		}

		update_meta_cache( 'post', $attachmentIds );
		$items = self::buildImagesData( $attachmentIds, $productId );

		if ( $videoData !== null && ! empty( $items ) ) {
			$items[0]['is_video']   = true;
			$items[0]['video_url']  = $videoData['url'];
			$items[0]['video_type'] = $videoData['type'];
		}

		return [
			'product_id' => $productId,
			'video'      => $videoData,
			'items'      => $items,
		];
	}

	/**
	 * Get variation galleries — ONLY for variations that have a custom gallery.
	 *
	 * @param WC_Product $product
	 * @return array<int, list<array<string, mixed>>>
	 */
	public static function getVariationGalleries( WC_Product $product ): array {
		if ( ! $product instanceof WC_Product_Variable ) {
			return [];
		}

		$childrenIds = $product->get_children();
		if ( empty( $childrenIds ) ) {
			return [];
		}

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

			$varImageId = (int) get_post_thumbnail_id( $variationId );
			if ( $varImageId > 0 && ! in_array( $varImageId, $galleryIds, true ) ) {
				array_unshift( $galleryIds, $varImageId );
			}

			$variationGalleryMap[ $variationId ] = $galleryIds;
			array_push( $allAttachmentIds, ...$galleryIds );
		}

		if ( empty( $variationGalleryMap ) ) {
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
	 * @param WC_Product $product
	 * @return int|null
	 */
	public static function resolveDefaultVariation( WC_Product $product ): ?int {
		if ( ! $product instanceof WC_Product_Variable ) {
			return null;
		}

		$dataStore = \WC_Data_Store::load( 'product' );

		// 1. Check variation_id request param
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$requestedId = absint( $_REQUEST['variation_id'] ?? 0 );
		if (
			$requestedId > 0
			&& 'product_variation' === get_post_type( $requestedId )
			&& $product->get_id() === (int) wp_get_post_parent_id( $requestedId )
		) {
			return $requestedId;
		}

		// 2. Check attribute_* URL params
		$urlAttrs = [];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		foreach ( $_GET as $key => $value ) {
			if ( str_starts_with( (string) $key, 'attribute_' ) && '' !== $value ) {
				$urlAttrs[ sanitize_title( (string) $key ) ] = sanitize_text_field( wp_unslash( (string) $value ) );
			}
		}

		if ( ! empty( $urlAttrs ) ) {
			$variationId = $dataStore->find_matching_product_variation( $product, $urlAttrs );
			if ( $variationId ) {
				return (int) $variationId;
			}
		}

		// 3. Fallback: product default attributes
		$defaults = $product->get_default_attributes();
		if ( empty( $defaults ) ) {
			return null;
		}

		$attrs = [];
		foreach ( $defaults as $key => $value ) {
			if ( '' !== $value ) {
				$attrs[ "attribute_{$key}" ] = (string) $value;
			}
		}

		if ( empty( $attrs ) ) {
			return null;
		}

		$variationId = $dataStore->find_matching_product_variation( $product, $attrs );

		return $variationId ? (int) $variationId : null;
	}

	/**
	 * Merge variation images with default images (prepend mode).
	 */
	public static function mergeVariationImages( array $varImages, array $defaultImages ): array {
		$seen = [];
		foreach ( $varImages as $img ) {
			if ( isset( $img['src'] ) ) {
				$seen[ $img['src'] ] = true;
			}
		}

		$remaining = array_filter(
			$defaultImages,
			static fn( $img ) => ! isset( $seen[ $img['src'] ?? '' ] )
		);

		return array_merge( $varImages, array_values( $remaining ) );
	}
}
