<?php
/**
 * Local Helper functions.
 *
 * @package HDWCGallery\Core
 */

declare(strict_types=1);

namespace HDWCGallery\Core;

defined( 'ABSPATH' ) || exit;

final class Helper {

	/**
	 * Get attachment image source URL.
	 */
	public static function attachmentImageSrc( mixed $attachmentId, string|array $size = 'thumbnail' ): string|false {
		return $attachmentId ? wp_get_attachment_image_url( (int) $attachmentId, $size ) : false;
	}

	/**
	 * Get fallback 1x1 transparent pixel image string.
	 */
	public static function pixelImg( string $img = '' ): string {
		return is_file( $img ) ? $img : 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==';
	}

	/**
	 * Extract YouTube ID from URL.
	 */
	public static function youtubeId( string $url ): ?string {
		if ( ! $url ) {
			return null;
		}

		$parsed = wp_parse_url( $url );
		$host   = strtolower( $parsed['host'] ?? '' );

		if ( ! preg_match( '/(?:^|\.)youtube\.com$|^youtu\.be$/', $host ) ) {
			return null;
		}

		if ( ! empty( $parsed['query'] ) ) {
			parse_str( $parsed['query'], $params );
			if ( ! empty( $params['v'] ) && preg_match( '/^[a-zA-Z0-9_-]{11}$/', $params['v'] ) ) {
				return $params['v'];
			}
		}

		if ( preg_match( '/(?:youtu\.be\/|\/(?:embed|shorts)\/|\/v\/)([a-zA-Z0-9_-]{11})/', $url, $m ) ) {
			return $m[1];
		}

		return null;
	}

	/**
	 * Get YouTube thumbnail image URL.
	 */
	public static function youtubeImage( string $url, int $resolutionKey = 0 ): string {
		$videoId = self::youtubeId( $url );
		if ( ! $videoId ) {
			return self::pixelImg();
		}

		$resolutions = [ 'sddefault', 'hqdefault', 'mqdefault', 'default', 'maxresdefault' ];
		$resKey      = $resolutions[ max( 0, min( $resolutionKey, count( $resolutions ) - 1 ) ) ];

		return 'https://img.youtube.com/vi/' . $videoId . '/' . $resKey . '.jpg';
	}

	/**
	 * Get mock aspect ratio object for theme independence.
	 */
	public static function getAspectRatio( string $postType = 'product', string $option = '', string $defaultValue = 'as-1-1' ): object {
		return (object) [
			'class' => $defaultValue,
			'style' => '',
		];
	}
}
