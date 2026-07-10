<?php
/**
 * Video Helper — video detection, thumbnail extraction, and slide building.
 *
 * @package HDWCGallery\Frontend
 */

declare(strict_types=1);

namespace HDWCGallery\Frontend;

use HDWCGallery\Core\Helper;

defined( 'ABSPATH' ) || exit;

final class VideoHelper {

	/**
	 * Detect video type from URL.
	 */
	public static function detectType( string $url ): string {
		if ( preg_match( '/youtube|youtu\.be/i', $url ) ) {
			return 'youtube';
		}

		if ( preg_match( '/vimeo/i', $url ) ) {
			return 'vimeo';
		}

		if ( preg_match( '/\.(mp4|webm)(\?|$)/i', $url ) ) {
			return 'mp4';
		}

		return 'iframe';
	}

	/**
	 * Get video thumbnail URL (auto-extract for YouTube, admin field fallback).
	 */
	public static function getThumbnailUrl( string $videoUrl, int $productId ): string {
		// 1. Admin-provided poster takes priority
		$poster = get_post_meta( $productId, GalleryDataProvider::PRODUCT_VIDEO_POSTER, true );
		if ( $poster ) {
			return $poster;
		}

		// 2. Auto-extract for YouTube (hqdefault = resolution key 1)
		$thumbnail = Helper::youtubeImage( $videoUrl, 1 );
		if ( $thumbnail && Helper::pixelImg() !== $thumbnail ) {
			return $thumbnail;
		}

		return '';
	}

	/**
	 * Build a video slide data array for per-product video.
	 */
	public static function buildSlide( string $videoUrl, array $images, int $productId ): array {
		$posterUrl = self::getThumbnailUrl( $videoUrl, $productId );
		$usePoster = ! empty( $posterUrl );

		return [
			'src'              => $usePoster ? $posterUrl : ( $images[0]['src'] ?? '' ),
			'width'            => $usePoster ? 0 : ( $images[0]['width'] ?? 0 ),
			'height'           => $usePoster ? 0 : ( $images[0]['height'] ?? 0 ),
			'thumb'            => $usePoster ? $posterUrl : ( $images[0]['thumb'] ?? '' ),
			'full'             => $usePoster ? $posterUrl : ( $images[0]['full'] ?? '' ),
			'srcset'           => '',
			'sizes'            => '',
			'alt'              => get_the_title( $productId ),
			'video'            => $videoUrl,
			'video_type'       => self::detectType( $videoUrl ),
			'is_product_video' => true,
		];
	}

	/**
	 * Inject per-product video slide into images array.
	 */
	public static function injectVideo( array &$images, string $videoUrl, string $position, int $productId ): void {
		if ( 'overlay' === $position ) {
			return; // Overlay handled in HTML, not as a slide
		}

		$videoSlide = self::buildSlide( $videoUrl, $images, $productId );

		match ( $position ) {
			'first_slide' => array_unshift( $images, $videoSlide ),
			'last_slide'  => $images[] = $videoSlide,
			default       => null,
		};
	}
}
