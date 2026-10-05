<?php

declare(strict_types=1);

namespace HDWCGallery\Tests\Unit;

use HDWCGallery\Admin\GalleryMediaFields;
use PHPUnit\Framework\TestCase;

final class GalleryMediaFieldsTest extends TestCase {

	public function testSaveFieldsWithValidUrl(): void {
		$post       = [ 'ID' => 123 ];
		$attachment = [ 'hd_media_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' ];

		$result = GalleryMediaFields::saveFields( $post, $attachment );

		$this->assertSame( $post, $result );
	}

	public function testSaveFieldsWithEmptyUrl(): void {
		$post       = [ 'ID' => 123 ];
		$attachment = [ 'hd_media_url' => '' ];

		$result = GalleryMediaFields::saveFields( $post, $attachment );

		$this->assertSame( $post, $result );
	}
}
