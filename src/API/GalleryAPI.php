<?php
/**
 * Gallery REST API — expose variation gallery images in WC REST responses.
 *
 * @package HDWCGallery\API
 */

declare(strict_types=1);

namespace HDWCGallery\API;

use HDWCGallery\Frontend\GalleryDataProvider;
use WC_Product_Variation;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class GalleryAPI {

	/**
	 * Register REST API hooks.
	 */
	public function register(): void {
		add_filter(
			'woocommerce_rest_prepare_product_variation_object',
			[ self::class, 'addGalleryToResponse' ],
			10,
			2
		);
	}

	/**
	 * Add variation gallery images to REST API response.
	 */
	public static function addGalleryToResponse(
		WP_REST_Response $response,
		WC_Product_Variation $variation
	): WP_REST_Response {
		$galleryIds = get_post_meta( $variation->get_id(), GalleryDataProvider::VARIATION_META_KEY, true );
		$galleryIds = ! empty( $galleryIds ) ? array_filter( array_map( 'absint', (array) $galleryIds ) ) : [];

		if ( $galleryIds ) {
			update_meta_cache( 'post', $galleryIds );
		}

		$galleryImages = array_map(
			static function ( int $id ): array {
				$src = wp_get_attachment_image_src( $id, 'woocommerce_single' );

				return [
					'id'  => $id,
					'src' => $src ? $src[0] : '',
					'alt' => get_post_meta( $id, '_wp_attachment_image_alt', true ) ?: '',
				];
			},
			$galleryIds
		);

		$data                      = $response->get_data();
		$data['hd_gallery_images'] = array_values( $galleryImages );
		$response->set_data( $data );

		return $response;
	}
}
