<?php

declare(strict_types=1);

namespace HDWCGallery\Tests\Unit;

use HDWCGallery\Admin\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase {

	public function testGetDefaultsReturnsAllExpectedKeys(): void {
		$defaults = Settings::getDefaults();

		$this->assertArrayHasKey( 'gallery_layout', $defaults );
		$this->assertArrayHasKey( 'gallery_object_fit', $defaults );
		$this->assertArrayHasKey( 'gallery_nav_arrows', $defaults );
		$this->assertArrayHasKey( 'gallery_pagination', $defaults );
		$this->assertArrayHasKey( 'gallery_zoom', $defaults );
		$this->assertArrayHasKey( 'gallery_zoom_scale', $defaults );
		$this->assertArrayHasKey( 'gallery_lens_mode', $defaults );
		$this->assertArrayHasKey( 'gallery_lens_size', $defaults );
		$this->assertArrayHasKey( 'gallery_lightbox', $defaults );
		$this->assertArrayHasKey( 'gallery_lightbox_thumbs', $defaults );
		$this->assertArrayHasKey( 'gallery_video_autoplay', $defaults );
		$this->assertArrayHasKey( 'gallery_product_video_pos', $defaults );
		$this->assertArrayHasKey( 'gallery_variation_mode', $defaults );
		$this->assertArrayHasKey( 'gallery_thumbs_mobile', $defaults );
		$this->assertArrayHasKey( 'gallery_thumbs_tablet', $defaults );
		$this->assertArrayHasKey( 'gallery_thumbs_desktop', $defaults );

		$this->assertSame( 'below', $defaults['gallery_layout'] );
		$this->assertSame( 'contain', $defaults['gallery_object_fit'] );
		$this->assertTrue( $defaults['gallery_nav_arrows'] );
		$this->assertTrue( $defaults['gallery_zoom'] );
		$this->assertEquals( 2.0, $defaults['gallery_zoom_scale'] );
		$this->assertSame( 'inner', $defaults['gallery_lens_mode'] );
		$this->assertSame( 150, $defaults['gallery_lens_size'] );
		$this->assertTrue( $defaults['gallery_lightbox'] );
		$this->assertSame( 'replace', $defaults['gallery_variation_mode'] );
		$this->assertSame( 3, $defaults['gallery_thumbs_mobile'] );
		$this->assertSame( 4, $defaults['gallery_thumbs_tablet'] );
		$this->assertSame( 5, $defaults['gallery_thumbs_desktop'] );
	}

	public function testSanitizeSettingsValidInput(): void {
		$input = [
			'gallery_layout'            => 'stacked',
			'gallery_object_fit'        => 'cover',
			'gallery_nav_arrows'        => '1',
			'gallery_pagination'        => '1',
			'gallery_zoom'              => '1',
			'gallery_zoom_scale'        => '2.5',
			'gallery_lens_mode'         => 'circle',
			'gallery_lens_size'         => '180',
			'gallery_lightbox'          => '1',
			'gallery_lightbox_thumbs'   => '1',
			'gallery_video_autoplay'    => '1',
			'gallery_product_video_pos' => 'last_slide',
			'gallery_variation_mode'    => 'prepend',
			'gallery_thumbs_mobile'     => '4',
			'gallery_thumbs_tablet'     => '6',
			'gallery_thumbs_desktop'    => '8',
		];

		$sanitized = Settings::sanitizeSettings( $input );

		$this->assertSame( 'stacked', $sanitized['gallery_layout'] );
		$this->assertSame( 'cover', $sanitized['gallery_object_fit'] );
		$this->assertTrue( $sanitized['gallery_nav_arrows'] );
		$this->assertTrue( $sanitized['gallery_pagination'] );
		$this->assertTrue( $sanitized['gallery_zoom'] );
		$this->assertEquals( 2.5, $sanitized['gallery_zoom_scale'] );
		$this->assertSame( 'circle', $sanitized['gallery_lens_mode'] );
		$this->assertEquals( 180, $sanitized['gallery_lens_size'] );
		$this->assertTrue( $sanitized['gallery_lightbox'] );
		$this->assertTrue( $sanitized['gallery_lightbox_thumbs'] );
		$this->assertTrue( $sanitized['gallery_video_autoplay'] );
		$this->assertSame( 'last_slide', $sanitized['gallery_product_video_pos'] );
		$this->assertSame( 'prepend', $sanitized['gallery_variation_mode'] );
		$this->assertEquals( 4, $sanitized['gallery_thumbs_mobile'] );
		$this->assertEquals( 6, $sanitized['gallery_thumbs_tablet'] );
		$this->assertEquals( 8, $sanitized['gallery_thumbs_desktop'] );
	}

	public function testSanitizeSettingsHandlesMissingTogglesAsFalse(): void {
		$sanitized = Settings::sanitizeSettings( [] );

		$this->assertFalse( $sanitized['gallery_nav_arrows'] );
		$this->assertFalse( $sanitized['gallery_pagination'] );
		$this->assertFalse( $sanitized['gallery_zoom'] );
		$this->assertFalse( $sanitized['gallery_lightbox'] );
		$this->assertFalse( $sanitized['gallery_lightbox_thumbs'] );
		$this->assertFalse( $sanitized['gallery_video_autoplay'] );
	}

	public function testSanitizeSettingsInvalidSelectFallsBackToDefault(): void {
		$input = [
			'gallery_layout'     => 'non_existent_layout',
			'gallery_object_fit' => 'invalid_fit',
			'gallery_lens_mode'  => 'unknown_mode',
		];

		$sanitized = Settings::sanitizeSettings( $input );

		$this->assertSame( 'below', $sanitized['gallery_layout'] );
		$this->assertSame( 'contain', $sanitized['gallery_object_fit'] );
		$this->assertSame( 'inner', $sanitized['gallery_lens_mode'] );
	}

	public function testGetOptionReturnsFallbackWhenNotSet(): void {
		$val = Settings::getOption( 'non_existent_key_xyz', 'fallback_123' );
		$this->assertSame( 'fallback_123', $val );
	}

	public function testGetFieldsConfigAllHaveTypeAndLabel(): void {
		$fields = Settings::getFieldsConfig();
		foreach ( $fields as $key => $field ) {
			$this->assertIsString( $key );
			$this->assertArrayHasKey( 'type', $field );
			$this->assertArrayHasKey( 'label', $field );
			$this->assertContains( $field['type'], [ 'select', 'toggle', 'number' ] );
		}
	}
}
