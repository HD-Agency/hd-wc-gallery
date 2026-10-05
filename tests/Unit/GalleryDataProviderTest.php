<?php

declare(strict_types=1);

namespace HDWCGallery\Tests\Unit;

use HDWCGallery\Frontend\GalleryDataProvider;
use PHPUnit\Framework\TestCase;

final class GalleryDataProviderTest extends TestCase {

	public function testNormalizeLayout(): void {
		$this->assertSame( 'below', GalleryDataProvider::normalizeLayout( 'below' ) );
		$this->assertSame( 'left', GalleryDataProvider::normalizeLayout( 'left' ) );
		$this->assertSame( 'right', GalleryDataProvider::normalizeLayout( 'right' ) );
		$this->assertSame( 'stacked', GalleryDataProvider::normalizeLayout( 'stacked' ) );

		// Legacy aliases
		$this->assertSame( 'below', GalleryDataProvider::normalizeLayout( 'horizontal' ) );
		$this->assertSame( 'left', GalleryDataProvider::normalizeLayout( 'vertical' ) );
		$this->assertSame( 'below', GalleryDataProvider::normalizeLayout( 'above' ) );

		// Invalid values fallback to 'below'
		$this->assertSame( 'below', GalleryDataProvider::normalizeLayout( 'invalid_xyz' ) );
		$this->assertSame( 'below', GalleryDataProvider::normalizeLayout( '' ) );
	}

	public function testMergeVariationImagesPrependMode(): void {
		$varImages = [
			[
				'src' => 'https://example.com/red-shirt-1.jpg',
				'alt' => 'Red 1',
			],
			[
				'src' => 'https://example.com/red-shirt-2.jpg',
				'alt' => 'Red 2',
			],
		];

		$defaultImages = [
			[
				'src' => 'https://example.com/default-1.jpg',
				'alt' => 'Default 1',
			],
			[
				'src' => 'https://example.com/red-shirt-1.jpg',
				'alt' => 'Red 1 Duplicate',
			],
			[
				'src' => 'https://example.com/default-2.jpg',
				'alt' => 'Default 2',
			],
		];

		$merged = GalleryDataProvider::mergeVariationImages( $varImages, $defaultImages );

		$this->assertCount( 4, $merged );
		$this->assertSame( 'https://example.com/red-shirt-1.jpg', $merged[0]['src'] );
		$this->assertSame( 'https://example.com/red-shirt-2.jpg', $merged[1]['src'] );
		$this->assertSame( 'https://example.com/default-1.jpg', $merged[2]['src'] );
		$this->assertSame( 'https://example.com/default-2.jpg', $merged[3]['src'] );
	}

	public function testConstants(): void {
		$this->assertSame( '_hd_variation_gallery', GalleryDataProvider::VARIATION_META_KEY );
		$this->assertSame( '_hd_product_video_url', GalleryDataProvider::PRODUCT_VIDEO_KEY );
		$this->assertSame( '_hd_product_video_poster', GalleryDataProvider::PRODUCT_VIDEO_POSTER );
		$this->assertSame( '_hd_media_url', GalleryDataProvider::MEDIA_URL_KEY );
	}
}
