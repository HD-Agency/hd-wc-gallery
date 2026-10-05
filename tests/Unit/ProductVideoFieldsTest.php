<?php

declare(strict_types=1);

namespace HDWCGallery\Tests\Unit;

use HDWCGallery\Admin\ProductVideoFields;
use PHPUnit\Framework\TestCase;

final class ProductVideoFieldsTest extends TestCase {

	public function testSaveFields(): void {
		$_POST['_hd_product_video_url']    = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
		$_POST['_hd_product_video_poster'] = 'https://example.com/poster.jpg';

		ProductVideoFields::saveFields( 999 );

		$this->assertTrue( true );

		unset( $_POST['_hd_product_video_url'], $_POST['_hd_product_video_poster'] );
		ProductVideoFields::saveFields( 999 );
	}
}
