<?php

declare(strict_types=1);

namespace HDWCGallery\Tests\Unit;

use HDWCGallery\API\GalleryAPI;
use PHPUnit\Framework\TestCase;
use WC_Product_Variation;
use WP_REST_Response;

final class GalleryAPITest extends TestCase {

	public function testAddGalleryToResponse(): void {
		$response  = new WP_REST_Response(
			[
				'id'   => 456,
				'name' => 'Variation Red',
			]
		);
		$variation = new WC_Product_Variation( 456 );

		$result = GalleryAPI::addGalleryToResponse( $response, $variation );

		$data = $result->get_data();
		$this->assertArrayHasKey( 'hd_gallery_images', $data );
		$this->assertIsArray( $data['hd_gallery_images'] );
	}
}
