<?php

declare(strict_types=1);

namespace HDWCGallery\Tests\Unit;

use HDWCGallery\Core\Helper;
use PHPUnit\Framework\TestCase;

final class HelperTest extends TestCase {

	public function testPixelImg(): void {
		$pixel = Helper::pixelImg();
		$this->assertStringStartsWith( 'data:image/gif;base64,', $pixel );
	}

	public function testYoutubeId(): void {
		$this->assertSame( 'dQw4w9WgXcQ', Helper::youtubeId( 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' ) );
		$this->assertSame( 'dQw4w9WgXcQ', Helper::youtubeId( 'https://youtu.be/dQw4w9WgXcQ' ) );
		$this->assertSame( 'dQw4w9WgXcQ', Helper::youtubeId( 'https://www.youtube.com/embed/dQw4w9WgXcQ' ) );
		$this->assertSame( 'dQw4w9WgXcQ', Helper::youtubeId( 'https://www.youtube.com/shorts/dQw4w9WgXcQ' ) );

		$this->assertNull( Helper::youtubeId( '' ) );
		$this->assertNull( Helper::youtubeId( 'https://vimeo.com/76979871' ) );
	}

	public function testYoutubeImage(): void {
		$img = Helper::youtubeImage( 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 0 );
		$this->assertSame( 'https://img.youtube.com/vi/dQw4w9WgXcQ/sddefault.jpg', $img );

		$invalid = Helper::youtubeImage( 'https://example.com/not-yt' );
		$this->assertStringStartsWith( 'data:image/gif;base64,', $invalid );
	}

	public function testGetAspectRatio(): void {
		$aspect = Helper::getAspectRatio( 'product', '', 'as-4-3' );
		$this->assertSame( 'as-4-3', $aspect->class );
	}
}
