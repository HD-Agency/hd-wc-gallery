<?php
/**
 * Video Helper — parses and formats video URLs (YouTube, Vimeo, MP4) for product galleries.
 *
 * @package HDWCGallery\Frontend
 */

declare(strict_types=1);

namespace HDWCGallery\Frontend;

use HDWCGallery\Core\Helper;

defined( 'ABSPATH' ) || exit;

final class VideoHelper {

	/**
	 * Supported video URL patterns regex.
	 */
	private const VIDEO_REGEX = '/\.(mp4|webm)(\?|$)|youtu\.?be|vimeo\.com/i';

	/**
	 * Check if a URL points to a supported video source.
	 */
	public static function isVideoUrl( ?string $url ): bool {
		if ( empty( $url ) ) {
			return false;
		}

		return (bool) preg_match( self::VIDEO_REGEX, $url );
	}

	/**
	 * Extract 11-character YouTube video ID.
	 */
	public static function extractYouTubeId( ?string $url ): ?string {
		if ( empty( $url ) ) {
			return null;
		}

		$pattern = '/(?:youtube(?:-nocookie)?\.com\/(?:[^\/\n\s]+\/\S+\/|(?:v|e(?:mbed)?|shorts)\/|\S*?[?&]v=)|youtu\.be\/)([a-zA-Z0-9_-]{11})/i';
		if ( preg_match( $pattern, $url, $matches ) ) {
			return $matches[1];
		}

		return null;
	}

	/**
	 * Extract numeric Vimeo video ID.
	 */
	public static function extractVimeoId( ?string $url ): ?string {
		if ( empty( $url ) ) {
			return null;
		}

		$pattern = '/(?:vimeo\.com\/(?:video\/)?|player\.vimeo\.com\/video\/)(\d+)/i';
		if ( preg_match( $pattern, $url, $matches ) ) {
			return $matches[1];
		}

		return null;
	}

	/**
	 * Resolve video provider type: 'youtube' | 'vimeo' | 'html5' | null.
	 */
	public static function resolveVideoType( ?string $url ): ?string {
		if ( empty( $url ) ) {
			return null;
		}

		if ( self::extractYouTubeId( $url ) !== null ) {
			return 'youtube';
		}

		if ( self::extractVimeoId( $url ) !== null ) {
			return 'vimeo';
		}

		if ( preg_match( '/\.(mp4|webm)(\?|$)/i', $url ) ) {
			return 'html5';
		}

		return null;
	}

	/**
	 * Detect video type string (fallback method).
	 */
	public static function detectType( string $url ): string {
		return self::resolveVideoType( $url ) ?? 'iframe';
	}

	/**
	 * Build iframe embed URL for PhotoSwipe / gallery slides.
	 */
	public static function buildEmbedUrl( string $url, bool $autoplay = true ): ?string {
		$ytId = self::extractYouTubeId( $url );
		if ( $ytId !== null ) {
			return sprintf(
				'https://www.youtube-nocookie.com/embed/%s?autoplay=%d&rel=0&enablejsapi=1',
				$ytId,
				$autoplay ? 1 : 0
			);
		}

		$vimeoId = self::extractVimeoId( $url );
		if ( $vimeoId !== null ) {
			return sprintf(
				'https://player.vimeo.com/video/%s?autoplay=%d&dnt=1',
				$vimeoId,
				$autoplay ? 1 : 0
			);
		}

		return null;
	}

	/**
	 * Get video thumbnail URL (auto-extract for YouTube, admin poster fallback).
	 */
	public static function getThumbnailUrl( string $videoUrl, int $productId ): string {
		$poster = (string) get_post_meta( $productId, GalleryDataProvider::PRODUCT_VIDEO_POSTER, true );
		if ( $poster ) {
			return $poster;
		}

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
			'large_src'        => $usePoster ? $posterUrl : ( $images[0]['large_src'] ?? '' ),
			'thumb_src'        => $usePoster ? $posterUrl : ( $images[0]['thumb_src'] ?? '' ),
			'width'            => $usePoster ? 800 : ( $images[0]['width'] ?? 800 ),
			'height'           => $usePoster ? 800 : ( $images[0]['height'] ?? 800 ),
			'srcset'           => '',
			'sizes'            => '',
			'alt'              => get_the_title( $productId ),
			'title'            => get_the_title( $productId ),
			'is_video'         => true,
			'video_url'        => $videoUrl,
			'video_type'       => self::resolveVideoType( $videoUrl ) ?? 'youtube',
			'is_product_video' => true,
		];
	}

	/**
	 * Inject per-product video slide into images array based on position setting.
	 */
	public static function injectVideo( array &$images, string $videoUrl, string $position, int $productId ): void {
		if ( 'overlay' === $position ) {
			return;
		}

		$videoSlide = self::buildSlide( $videoUrl, $images, $productId );

		if ( 'last_slide' === $position ) {
			$images[] = $videoSlide;
		} else {
			array_unshift( $images, $videoSlide );
		}
	}
}
