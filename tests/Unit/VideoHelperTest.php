<?php

declare(strict_types=1);

namespace HDWCGallery\Tests\Unit;

use HDWCGallery\Frontend\VideoHelper;
use PHPUnit\Framework\TestCase;

final class VideoHelperTest extends TestCase {

	public function testExtractYouTubeIdFromVariousUrls(): void {
		$urls = [
			'https://www.youtube.com/watch?v=dQw4w9WgXcQ' => 'dQw4w9WgXcQ',
			'https://youtu.be/dQw4w9WgXcQ'                => 'dQw4w9WgXcQ',
			'https://www.youtube.com/embed/dQw4w9WgXcQ'   => 'dQw4w9WgXcQ',
			'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ' => 'dQw4w9WgXcQ',
			'https://youtube.com/shorts/dQw4w9WgXcQ?feature=share' => 'dQw4w9WgXcQ',
		];

		foreach ( $urls as $url => $expectedId ) {
			$this->assertSame( $expectedId, VideoHelper::extractYouTubeId( $url ), "Failed for URL: {$url}" );
			$this->assertSame( 'youtube', VideoHelper::resolveVideoType( $url ) );
			$this->assertTrue( VideoHelper::isVideoUrl( $url ) );
		}

		$this->assertNull( VideoHelper::extractYouTubeId( '' ) );
		$this->assertNull( VideoHelper::extractYouTubeId( 'https://example.com/not-a-video' ) );
	}

	public function testExtractVimeoIdFromVariousUrls(): void {
		$urls = [
			'https://vimeo.com/76979871'                  => '76979871',
			'https://player.vimeo.com/video/76979871'     => '76979871',
			'https://vimeo.com/video/76979871?autoplay=1' => '76979871',
		];

		foreach ( $urls as $url => $expectedId ) {
			$this->assertSame( $expectedId, VideoHelper::extractVimeoId( $url ), "Failed for URL: {$url}" );
			$this->assertSame( 'vimeo', VideoHelper::resolveVideoType( $url ) );
			$this->assertTrue( VideoHelper::isVideoUrl( $url ) );
		}

		$this->assertNull( VideoHelper::extractVimeoId( '' ) );
		$this->assertNull( VideoHelper::extractVimeoId( 'https://vimeo.com/notanumber' ) );
	}

	public function testHtml5VideoDetection(): void {
		$mp4Url  = 'https://example.com/assets/intro.mp4';
		$webmUrl = 'https://example.com/media/demo.webm?v=2';

		$this->assertTrue( VideoHelper::isVideoUrl( $mp4Url ) );
		$this->assertSame( 'html5', VideoHelper::resolveVideoType( $mp4Url ) );

		$this->assertTrue( VideoHelper::isVideoUrl( $webmUrl ) );
		$this->assertSame( 'html5', VideoHelper::resolveVideoType( $webmUrl ) );

		$this->assertFalse( VideoHelper::isVideoUrl( 'https://example.com/image.jpg' ) );
		$this->assertNull( VideoHelper::resolveVideoType( 'https://example.com/image.jpg' ) );
	}

	public function testBuildEmbedUrl(): void {
		$ytUrl = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
		$embed = VideoHelper::buildEmbedUrl( $ytUrl, true );
		$this->assertStringContainsString( 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', (string) $embed );
		$this->assertStringContainsString( 'autoplay=1', (string) $embed );

		$vimeoUrl   = 'https://vimeo.com/76979871';
		$vimeoEmbed = VideoHelper::buildEmbedUrl( $vimeoUrl, false );
		$this->assertStringContainsString( 'https://player.vimeo.com/video/76979871', (string) $vimeoEmbed );
		$this->assertStringContainsString( 'autoplay=0', (string) $vimeoEmbed );

		$this->assertNull( VideoHelper::buildEmbedUrl( 'https://example.com/not-supported' ) );
	}

	public function testDetectType(): void {
		$this->assertSame( 'youtube', VideoHelper::detectType( 'https://youtu.be/dQw4w9WgXcQ' ) );
		$this->assertSame( 'vimeo', VideoHelper::detectType( 'https://vimeo.com/76979871' ) );
		$this->assertSame( 'html5', VideoHelper::detectType( 'https://example.com/video.mp4' ) );
		$this->assertSame( 'iframe', VideoHelper::detectType( 'https://example.com/custom' ) );
	}

	public function testBuildSlide(): void {
		$videoUrl = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
		$slide    = VideoHelper::buildSlide( $videoUrl, [], 100 );

		$this->assertTrue( $slide['is_video'] );
		$this->assertTrue( $slide['is_product_video'] );
		$this->assertSame( $videoUrl, $slide['video_url'] );
		$this->assertSame( 'youtube', $slide['video_type'] );
	}

	public function testInjectVideoPositions(): void {
		$images = [
			[
				'src'      => 'https://example.com/img1.jpg',
				'is_video' => false,
			],
			[
				'src'      => 'https://example.com/img2.jpg',
				'is_video' => false,
			],
		];

		// 1. First slide
		$firstImages = $images;
		VideoHelper::injectVideo( $firstImages, 'https://youtu.be/dQw4w9WgXcQ', 'first_slide', 10 );
		$this->assertCount( 3, $firstImages );
		$this->assertTrue( $firstImages[0]['is_video'] );

		// 2. Last slide
		$lastImages = $images;
		VideoHelper::injectVideo( $lastImages, 'https://youtu.be/dQw4w9WgXcQ', 'last_slide', 10 );
		$this->assertCount( 3, $lastImages );
		$this->assertTrue( $lastImages[2]['is_video'] );

		// 3. Overlay mode (does not mutate slides array)
		$overlayImages = $images;
		VideoHelper::injectVideo( $overlayImages, 'https://youtu.be/dQw4w9WgXcQ', 'overlay', 10 );
		$this->assertCount( 2, $overlayImages );
	}
}
