<?php

declare(strict_types=1);

namespace HDWCGallery\Tests\Unit;

use HDWCGallery\Frontend\GalleryDataProvider;
use HDWCGallery\Integrations\PolylangIntegration;
use PHPUnit\Framework\TestCase;

final class PolylangIntegrationTest extends TestCase {

	public function testAddPllMetasIncludesAllGalleryKeys(): void {
		$metas = PolylangIntegration::addPllMetas( [ '_custom_field' ] );

		$this->assertContains( '_custom_field', $metas );
		$this->assertContains( GalleryDataProvider::PRODUCT_VIDEO_KEY, $metas );
		$this->assertContains( GalleryDataProvider::PRODUCT_VIDEO_POSTER, $metas );
		$this->assertContains( GalleryDataProvider::VARIATION_META_KEY, $metas );
		$this->assertContains( GalleryDataProvider::MEDIA_URL_KEY, $metas );
	}
}
