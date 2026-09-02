<?php
/**
 * Gallery Renderer — frontend product gallery output.
 *
 * Renders semantic HTML markup for 4 gallery layouts (below, left, right, stacked).
 *
 * @package HDWCGallery\Frontend
 */

declare(strict_types=1);

namespace HDWCGallery\Frontend;

use HDWCGallery\Admin\Settings;
use WC_Product;

defined( 'ABSPATH' ) || exit;

final class GalleryRenderer {

	/**
	 * Register frontend hooks.
	 */
	public function register(): void {
		remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images', 20 );
		add_action( 'woocommerce_before_single_product_summary', [ self::class, 'render' ], 20 );
	}

	/**
	 * Render the full product gallery.
	 */
	public static function render(): void {
		global $product;

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$settings = Settings::getOptions();
		$layout   = GalleryDataProvider::normalizeLayout( (string) ( $settings['gallery_layout'] ?? 'below' ) );

		$data   = GalleryDataProvider::getGalleryData( $product );
		$items  = $data['items'];
		$allIds = GalleryDataProvider::collectImageIds( $product );

		// Per-product video URL
		$productVideoUrl = (string) get_post_meta( $product->get_id(), GalleryDataProvider::PRODUCT_VIDEO_KEY, true );
		$videoPosition   = (string) ( $settings['gallery_product_video_pos'] ?? 'first_slide' );

		if ( empty( $allIds ) && empty( $productVideoUrl ) ) {
			echo wp_kses_post( wc_placeholder_img( 'woocommerce_single' ) );
			return;
		}

		$variationGalleries = GalleryDataProvider::getVariationGalleries( $product );
		$variationMode      = (string) ( $settings['gallery_variation_mode'] ?? 'replace' );

		// Resolve default variation
		$defaultVarId = GalleryDataProvider::resolveDefaultVariation( $product );
		if ( $defaultVarId ) {
			if ( isset( $variationGalleries[ $defaultVarId ] ) ) {
				$varImages = $variationGalleries[ $defaultVarId ];
				$items     = 'prepend' === $variationMode
					? GalleryDataProvider::mergeVariationImages( $varImages, $items )
					: $varImages;
			} else {
				$varThumbId = (int) get_post_thumbnail_id( $defaultVarId );
				if ( $varThumbId > 0 && ! empty( $items ) ) {
					$varImageData = GalleryDataProvider::buildImagesData( [ $varThumbId ], $product->get_id() );
					if ( ! empty( $varImageData ) ) {
						$items[0] = $varImageData[0];
					}
				}
			}
		}

		$configAttr = esc_attr(
			(string) wp_json_encode(
				[
					'layout'         => $layout,
					'objectFit'      => (string) ( $settings['gallery_object_fit'] ?? 'contain' ),
					'navArrows'      => (bool) ( $settings['gallery_nav_arrows'] ?? true ),
					'pagination'     => (bool) ( $settings['gallery_pagination'] ?? true ),
					'zoom'           => (bool) ( $settings['gallery_zoom'] ?? true ),
					'zoomScale'      => (float) ( $settings['gallery_zoom_scale'] ?? 2.0 ),
					'lensMode'       => (string) ( $settings['gallery_lens_mode'] ?? 'inner' ),
					'lightbox'       => (bool) ( $settings['gallery_lightbox'] ?? true ),
					'lightboxThumbs' => (bool) ( $settings['gallery_lightbox_thumbs'] ?? true ),
					'videoAutoplay'  => (bool) ( $settings['gallery_video_autoplay'] ?? true ),
					'videoPos'       => $videoPosition,
					'variationMode'  => $variationMode,
				]
			)
		);

		$wrapperClass = sprintf( 'hd-gallery hd-gallery--%s woocommerce-product-gallery', esc_attr( $layout ) );

		?>
		<div class="<?php echo esc_attr( $wrapperClass ); ?>" data-wc-gallery data-gallery-config="<?php echo $configAttr; ?>">
			<?php if ( 'stacked' === $layout ) : ?>
				<?php self::renderStackedLayout( $items, $productVideoUrl, $videoPosition ); ?>
			<?php else : ?>
				<?php self::renderSliderLayout( $items, $layout, $settings, $productVideoUrl, $videoPosition ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders slider layouts (below, left, right).
	 *
	 * @param list<array<string, mixed>> $items
	 * @param string                     $layout
	 * @param array<string, mixed>       $settings
	 * @param string                     $productVideoUrl
	 * @param string                     $videoPosition
	 */
	private static function renderSliderLayout(
		array $items,
		string $layout,
		array $settings,
		string $productVideoUrl,
		string $videoPosition
	): void {
		$numItems      = count( $items );
		$hasThumbs     = $numItems > 1;
		$hasNavArrows  = $hasThumbs && ! empty( $settings['gallery_nav_arrows'] );
		$hasPagination = $hasThumbs && ! empty( $settings['gallery_pagination'] );
		$thumbsBefore  = in_array( $layout, [ 'left' ], true );
		?>
		<?php if ( $hasThumbs && $thumbsBefore ) : ?>
			<?php self::renderThumbsSlider( $items, $layout ); ?>
		<?php endif; ?>

		<div class="hd-gallery__main">
			<div class="swiper hd-gallery__main-slider">
				<div class="swiper-wrapper">
					<?php foreach ( $items as $index => $item ) : ?>
						<?php self::renderMainSlide( $item, $index ); ?>
					<?php endforeach; ?>
				</div>

				<?php if ( $hasNavArrows ) : ?>
					<button type="button" class="hd-gallery__nav hd-gallery__nav--prev swiper-button-prev" aria-label="<?php esc_attr_e( 'Previous slide', 'hd-wc-gallery' ); ?>">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M15 19l-7-7 7-7"/></svg>
					</button>
					<button type="button" class="hd-gallery__nav hd-gallery__nav--next swiper-button-next" aria-label="<?php esc_attr_e( 'Next slide', 'hd-wc-gallery' ); ?>">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M9 5l7 7-7 7"/></svg>
					</button>
				<?php endif; ?>

				<?php if ( $hasPagination ) : ?>
					<div class="swiper-pagination hd-gallery__pagination"></div>
				<?php endif; ?>
			</div>

			<?php self::renderVideoOverlay( $productVideoUrl, $videoPosition ); ?>
		</div>

		<?php if ( $hasThumbs && ! $thumbsBefore ) : ?>
			<?php self::renderThumbsSlider( $items, $layout ); ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * Renders thumbnails slider.
	 *
	 * @param list<array<string, mixed>> $items
	 * @param string                     $layout
	 */
	private static function renderThumbsSlider( array $items, string $layout ): void {
		?>
		<div class="hd-gallery__thumbs">
			<div class="swiper hd-gallery__thumbs-slider" data-direction="<?php echo in_array( $layout, [ 'left', 'right' ], true ) ? 'vertical' : 'horizontal'; ?>">
				<div class="swiper-wrapper">
					<?php foreach ( $items as $index => $item ) : ?>
						<?php self::renderThumbSlide( $item, $index ); ?>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders stacked (2-column grid) layout.
	 *
	 * @param list<array<string, mixed>> $items
	 * @param string                     $productVideoUrl
	 * @param string                     $videoPosition
	 */
	private static function renderStackedLayout( array $items, string $productVideoUrl, string $videoPosition ): void {
		?>
		<div class="hd-gallery__stacked">
			<?php foreach ( $items as $index => $item ) : ?>
				<div class="hd-gallery__stacked-item" data-index="<?php echo esc_attr( (string) $index ); ?>">
					<?php self::renderSlideAnchor( $item, $index, true ); ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php self::renderVideoOverlay( $productVideoUrl, $videoPosition ); ?>
		<?php
	}

	/**
	 * Renders a single main slide.
	 *
	 * @param array<string, mixed> $item
	 * @param int                  $index
	 */
	private static function renderMainSlide( array $item, int $index ): void {
		?>
		<div class="swiper-slide hd-gallery__slide" data-index="<?php echo esc_attr( (string) $index ); ?>">
			<?php self::renderSlideAnchor( $item, $index, $index === 0 ); ?>
		</div>
		<?php
	}

	/**
	 * Renders slide anchor wrapping the image/video.
	 *
	 * @param array<string, mixed> $item
	 * @param int                  $index
	 * @param bool                 $isHero
	 */
	private static function renderSlideAnchor( array $item, int $index, bool $isHero ): void {
		$isVideo   = ! empty( $item['is_video'] );
		$videoUrl  = $isVideo ? (string) ( $item['video_url'] ?? '' ) : '';
		$videoType = $isVideo ? (string) ( $item['video_type'] ?? 'youtube' ) : '';

		$lightboxAttr = sprintf(
			'data-lightbox="hd-gallery" data-pswp-width="%d" data-pswp-height="%d"',
			(int) ( $item['width'] ?? 1000 ),
			(int) ( $item['height'] ?? 1000 )
		);

		if ( $isVideo && ! empty( $videoUrl ) ) {
			$lightboxAttr .= sprintf( ' data-pswp-video-url="%s" data-pswp-video-type="%s"', esc_url( $videoUrl ), esc_attr( $videoType ) );
		}
		?>
		<a href="<?php echo esc_url( (string) ( $item['large_src'] ?? $item['src'] ?? '' ) ); ?>" class="hd-gallery__link" <?php echo $lightboxAttr; ?>>
			<figure class="hd-gallery__figure">
				<img
					src="<?php echo esc_url( (string) ( $item['src'] ?? '' ) ); ?>"
					<?php if ( ! empty( $item['srcset'] ) ) : ?>
						srcset="<?php echo esc_attr( (string) $item['srcset'] ); ?>"
					<?php endif; ?>
					<?php if ( ! empty( $item['sizes'] ) ) : ?>
						sizes="<?php echo esc_attr( (string) $item['sizes'] ); ?>"
					<?php endif; ?>
					alt="<?php echo esc_attr( (string) ( $item['alt'] ?? '' ) ); ?>"
					title="<?php echo esc_attr( (string) ( $item['title'] ?? '' ) ); ?>"
					width="<?php echo esc_attr( (string) ( $item['width'] ?? 800 ) ); ?>"
					height="<?php echo esc_attr( (string) ( $item['height'] ?? 800 ) ); ?>"
					loading="<?php echo $isHero ? 'eager' : 'lazy'; ?>"
					<?php if ( $isHero ) : ?>
						fetchpriority="high"
					<?php endif; ?>
					class="hd-gallery__image"
					data-zoom-src="<?php echo esc_url( (string) ( $item['large_src'] ?? $item['src'] ?? '' ) ); ?>"
				/>

				<?php if ( $isVideo ) : ?>
					<span class="hd-gallery__play-badge" aria-label="<?php esc_attr_e( 'Watch video', 'hd-wc-gallery' ); ?>">
						<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M8 5v14l11-7z"/></svg>
					</span>
				<?php endif; ?>
			</figure>
		</a>
		<?php
	}

	/**
	 * Renders a thumbnail slide.
	 *
	 * @param array<string, mixed> $item
	 * @param int                  $index
	 */
	private static function renderThumbSlide( array $item, int $index ): void {
		$isVideo = ! empty( $item['is_video'] );
		?>
		<div class="swiper-slide hd-gallery__thumb" data-index="<?php echo esc_attr( (string) $index ); ?>" tabindex="0" role="button" aria-label="<?php echo esc_attr( sprintf( __( 'View image %d', 'hd-wc-gallery' ), $index + 1 ) ); ?>">
			<figure class="hd-gallery__thumb-figure">
				<img
					src="<?php echo esc_url( (string) ( $item['thumb_src'] ?? $item['thumb'] ?? $item['src'] ?? '' ) ); ?>"
					alt="<?php echo esc_attr( (string) ( $item['alt'] ?? '' ) ); ?>"
					loading="lazy"
					class="hd-gallery__thumb-image"
				/>
				<?php if ( $isVideo ) : ?>
					<span class="hd-gallery__thumb-play-badge">
						<svg viewBox="0 0 24 24" fill="currentColor" width="12" height="12"><path d="M8 5v14l11-7z"/></svg>
					</span>
				<?php endif; ?>
			</figure>
		</div>
		<?php
	}

	/**
	 * Render floating video overlay button (when position is overlay).
	 */
	private static function renderVideoOverlay( string $videoUrl, string $position ): void {
		if ( empty( $videoUrl ) || 'overlay' !== $position ) {
			return;
		}

		$videoType = VideoHelper::resolveVideoType( $videoUrl ) ?? 'youtube';
		?>
		<a href="<?php echo esc_url( $videoUrl ); ?>"
			class="hd-gallery__video-overlay"
			data-lightbox="hd-gallery"
			data-pswp-video-url="<?php echo esc_url( $videoUrl ); ?>"
			data-pswp-video-type="<?php echo esc_attr( $videoType ); ?>"
			aria-label="<?php esc_attr_e( 'Play product video', 'hd-wc-gallery' ); ?>">
			<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M8 5v14l11-7z"/></svg>
		</a>
		<?php
	}
}
