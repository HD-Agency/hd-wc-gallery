/**
 * Swiper v14 Slider Controller for HD WC Gallery.
 *
 * Coordinates main and thumbnail Swiper instances with dual-axis layout support,
 * keyboard controls, touch gestures, and hover zoom integration.
 *
 * @package HDWCGallery\Gallery
 */

import Swiper from 'swiper';
import { Navigation, Thumbs, FreeMode, Pagination, Keyboard, Manipulation } from 'swiper/modules';
import 'swiper/css';
import 'swiper/css/navigation';
import 'swiper/css/pagination';
import 'swiper/css/thumbs';
import 'swiper/css/free-mode';

import { initSlideZoom, ZoomController } from './gallery-zoom';

export interface GallerySliderOptions {
	layout: 'below' | 'left' | 'right' | 'stacked';
	zoom: boolean;
	zoomScale: number;
	lensMode: 'inner' | 'circle';
}

export interface GallerySliderInstance {
	mainSwiper: Swiper | null;
	thumbsSwiper: Swiper | null;
	destroy: () => void;
	update: () => void;
	goToSlide: (index: number) => void;
}

/**
 * Initializes Swiper sliders on a gallery element.
 */
export function initGallerySliders(galleryEl: HTMLElement, options: GallerySliderOptions): GallerySliderInstance {
	const mainContainer = galleryEl.querySelector<HTMLElement>('.hd-gallery__main-slider');
	const thumbsContainer = galleryEl.querySelector<HTMLElement>('.hd-gallery__thumbs-slider');
	const zoomControllers: ZoomController[] = [];

	let thumbsSwiper: Swiper | null = null;
	let mainSwiper: Swiper | null = null;

	// 1. Initialize Thumbs Slider (if exists)
	if (thumbsContainer) {
		const isVertical = options.layout === 'left' || options.layout === 'right';

		thumbsSwiper = new Swiper(thumbsContainer, {
			modules: [FreeMode, Navigation, Manipulation],
			direction: isVertical ? 'vertical' : 'horizontal',
			slidesPerView: 'auto',
			spaceBetween: 8,
			freeMode: {
				enabled: true,
				sticky: false,
			},
			watchSlidesProgress: true,
			slideToClickedSlide: true,
			threshold: 4,
			breakpoints: isVertical
				? {
						0: {
							direction: 'horizontal',
							slidesPerView: 'auto',
							spaceBetween: 6,
						},
						768: {
							direction: 'vertical',
							slidesPerView: 'auto',
							spaceBetween: 8,
						},
				  }
				: undefined,
		});
	}

	// 2. Initialize Main Slider
	if (mainContainer) {
		const prevBtn = galleryEl.querySelector<HTMLElement>('.hd-gallery__nav--prev');
		const nextBtn = galleryEl.querySelector<HTMLElement>('.hd-gallery__nav--next');
		const paginationEl = galleryEl.querySelector<HTMLElement>('.hd-gallery__pagination');

		mainSwiper = new Swiper(mainContainer, {
			modules: [Navigation, Thumbs, Pagination, Keyboard, Manipulation],
			slidesPerView: 1,
			spaceBetween: 12,
			speed: 350,
			grabCursor: true,
			keyboard: {
				enabled: true,
				onlyInViewport: true,
			},
			navigation: prevBtn && nextBtn ? { prevEl: prevBtn, nextEl: nextBtn } : false,
			pagination: paginationEl ? { el: paginationEl, clickable: true } : false,
			thumbs: thumbsSwiper ? { swiper: thumbsSwiper } : undefined,
			on: {
				slideChange: () => {
					// Synchronize active thumbnail class
					if (thumbsContainer) {
						const activeIndex = mainSwiper?.activeIndex || 0;
						const thumbSlides = thumbsContainer.querySelectorAll<HTMLElement>('.hd-gallery__thumb');
						thumbSlides.forEach((slide, idx) => {
							slide.classList.toggle('swiper-slide-thumb-active', idx === activeIndex);
						});
					}
				},
			},
		});
	}

	// 3. Initialize Zoom on main slides
	if (options.zoom) {
		const figures = galleryEl.querySelectorAll<HTMLElement>('.hd-gallery__figure');
		figures.forEach((fig) => {
			const ctrl = initSlideZoom(fig, {
				scale: options.zoomScale || 2.0,
				lensMode: options.lensMode || 'inner',
			});
			if (ctrl) {
				zoomControllers.push(ctrl);
			}
		});
	}

	return {
		mainSwiper,
		thumbsSwiper,
		destroy: () => {
			zoomControllers.forEach((c) => c.destroy());
			if (mainSwiper && !mainSwiper.destroyed) {
				mainSwiper.destroy(true, true);
			}
			if (thumbsSwiper && !thumbsSwiper.destroyed) {
				thumbsSwiper.destroy(true, true);
			}
		},
		update: () => {
			mainSwiper?.update();
			thumbsSwiper?.update();
		},
		goToSlide: (index: number) => {
			mainSwiper?.slideTo(index, 300);
		},
	};
}
