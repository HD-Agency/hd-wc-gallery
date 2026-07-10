<?php
/**
 * Gallery Media Fields — add video URL field to attachment edit modal.
 *
 * Allows attaching a video URL (YouTube/Vimeo/MP4/WEBM) to any image attachment.
 * When used in product gallery, the image becomes a video poster.
 *
 * @package HDWCGallery\Admin
 */

declare(strict_types=1);

namespace HDWCGallery\Admin;

defined( 'ABSPATH' ) || exit;

final class GalleryMediaFields {

	public const META_KEY = '_hd_media_url';

	/**
	 * Register attachment field hooks.
	 */
	public function register(): void {
		add_filter( 'attachment_fields_to_edit', [ self::class, 'addFields' ], 10, 2 );
		add_filter( 'attachment_fields_to_save', [ self::class, 'saveFields' ], 10, 2 );
	}

	/**
	 * Add "Media URL" field to attachment edit form.
	 *
	 * @param array    $fields     Existing fields.
	 * @param \WP_Post $attachment The attachment post.
	 *
	 * @return array Modified fields.
	 */
	public static function addFields( array $fields, \WP_Post $attachment ): array {
		if ( ! wp_attachment_is_image( $attachment->ID ) ) {
			return $fields;
		}

		$fields['hd_media_url'] = [
			'label' => __( 'Video URL', 'hd-wc-gallery' ),
			'input' => 'text',
			'value' => get_post_meta( $attachment->ID, self::META_KEY, true ) ?: '',
			'helps' => __( 'YouTube, Vimeo, or MP4/WEBM URL. Used as video in product gallery.', 'hd-wc-gallery' ),
		];

		return $fields;
	}

	/**
	 * Save "Media URL" field on attachment save.
	 *
	 * @param array $post       The attachment post data.
	 * @param array $attachment The attachment field values.
	 *
	 * @return array Unmodified post data.
	 */
	public static function saveFields( array $post, array $attachment ): array {
		$url = isset( $attachment['hd_media_url'] )
			? sanitize_url( $attachment['hd_media_url'] )
			: '';

		if ( $url ) {
			update_post_meta( $post['ID'], self::META_KEY, $url );
		} else {
			delete_post_meta( $post['ID'], self::META_KEY );
		}

		return $post;
	}
}
