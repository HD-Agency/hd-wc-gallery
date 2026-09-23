<?php

declare(strict_types=1);

namespace HDWCGallery\Tests\Unit;

use HDWCGallery\Admin\VariationGalleryPicker;
use PHPUnit\Framework\TestCase;

final class VariationGalleryPickerTest extends TestCase {

	public function testSaveVariationGallery(): void {
		$_POST['hd_variation_gallery'] = [
			0 => '101,102,103',
		];

		VariationGalleryPicker::saveVariationGallery( 501, 0 );

		$this->assertTrue( true );

		$_POST['hd_variation_gallery'][0] = '';
		VariationGalleryPicker::saveVariationGallery( 501, 0 );

		unset( $_POST['hd_variation_gallery'] );
	}
}
