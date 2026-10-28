/**
 * WooCommerce Variation Image Swapper for HD WC Gallery.
 *
 * Listens to variation selection events (found_variation, reset_data)
 * and dynamically swaps the hero slide and thumbnail images.
 *
 * @package HDWCGallery\Gallery
 */

import { GallerySliderInstance } from './gallery-slider';

export interface VariationImageData {
	image?: {
		src?: string;
		full_src?: string;
		thumb_src?: string;
		srcset?: string;
		sizes?: string;
		alt?: string;
		title?: string;
		full_src_w?: number;
		full_src_h?: number;
	};
}

export interface VariationController {
	destroy: () => void;
}

/**
 * Initializes variation image swapping on a gallery element.
 */
export function initVariationSwap(
	galleryEl: HTMLElement,
	sliderInstance: GallerySliderInstance
): VariationController | null {
	const form = document.querySelector<HTMLFormElement>('.variations_form');
	if (!form) return null;

	const firstMainSlide = galleryEl.querySelector<HTMLElement>('.hd-gallery__main .hd-gallery__slide:first-child');
	const firstThumbSlide = galleryEl.querySelector<HTMLElement>('.hd-gallery__thumbs .hd-gallery__thumb:first-child');

	const mainImg = firstMainSlide?.querySelector<HTMLImageElement>('img');
	const mainLink = firstMainSlide?.querySelector<HTMLAnchorElement>('a');
	const thumbImg = firstThumbSlide?.querySelector<HTMLImageElement>('img');

	if (!mainImg || !mainLink) return null;

	// Backup initial state
	const initial = {
		src: mainImg.src,
		srcset: mainImg.getAttribute('srcset') || '',
		sizes: mainImg.getAttribute('sizes') || '',
		alt: mainImg.alt,
		title: mainImg.title,
		largeSrc: mainLink.href,
		pswpWidth: mainLink.getAttribute('data-pswp-width') || '1000',
		pswpHeight: mainLink.getAttribute('data-pswp-height') || '1000',
		thumbSrc: thumbImg?.src || '',
	};

	const onFoundVariation = (e: Event) => {
		const detail = (e as CustomEvent).detail as VariationImageData | undefined;
		const variation = detail?.image || (e as any).variation?.image;
		if (!variation || !variation.src) return;

		// Swap Main Slide Image
		mainImg.src = variation.src;
		if (variation.srcset) {
			mainImg.setAttribute('srcset', variation.srcset);
		} else {
			mainImg.removeAttribute('srcset');
		}
		if (variation.sizes) {
			mainImg.setAttribute('sizes', variation.sizes);
		}

		if (variation.alt) mainImg.alt = variation.alt;
		if (variation.title) mainImg.title = variation.title;

		// Swap PhotoSwipe Fullscreen Link
		const fullSrc = variation.full_src || variation.src;
		mainLink.href = fullSrc;
		if (variation.full_src_w) mainLink.setAttribute('data-pswp-width', String(variation.full_src_w));
		if (variation.full_src_h) mainLink.setAttribute('data-pswp-height', String(variation.full_src_h));

		// Swap Thumbnail Image
		if (thumbImg) {
			thumbImg.src = variation.thumb_src || variation.src;
		}

		// Slide to first slide
		sliderInstance.goToSlide(0);
	};

	const onResetData = () => {
		mainImg.src = initial.src;
		if (initial.srcset) {
			mainImg.setAttribute('srcset', initial.srcset);
		} else {
			mainImg.removeAttribute('srcset');
		}
		if (initial.sizes) {
			mainImg.setAttribute('sizes', initial.sizes);
		}
		mainImg.alt = initial.alt;
		mainImg.title = initial.title;

		mainLink.href = initial.largeSrc;
		mainLink.setAttribute('data-pswp-width', initial.pswpWidth);
		mainLink.setAttribute('data-pswp-height', initial.pswpHeight);

		if (thumbImg && initial.thumbSrc) {
			thumbImg.src = initial.thumbSrc;
		}

		sliderInstance.goToSlide(0);
	};

	// Bind native & jQuery event listeners
	form.addEventListener('found_variation', onFoundVariation as EventListener);
	form.addEventListener('reset_data', onResetData as EventListener);

	// jQuery bridge if WooCommerce uses jQuery trigger
	if (typeof (window as any).jQuery !== 'undefined') {
		const $form = (window as any).jQuery(form);
		$form.on('found_variation', (_: any, variation: any) => onFoundVariation({ detail: { image: variation?.image } } as any));
		$form.on('reset_data', onResetData);
	}

	return {
		destroy: () => {
			form.removeEventListener('found_variation', onFoundVariation as EventListener);
			form.removeEventListener('reset_data', onResetData as EventListener);
			if (typeof (window as any).jQuery !== 'undefined') {
				const $form = (window as any).jQuery(form);
				$form.off('found_variation');
				$form.off('reset_data');
			}
		},
	};
}
