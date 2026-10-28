/**
 * PhotoSwipe v5 Lightbox Coordinator for HD WC Gallery.
 *
 * Initializes PhotoSwipe with custom bottom thumbs strip, video plugins,
 * and fixes for HTML <dialog> modal top-layer clipping.
 *
 * @package HDWCGallery\Gallery
 */

import PhotoSwipeLightbox from 'photoswipe/lightbox';
import 'photoswipe/style.css';
import { registerThumbsStrip } from './gallery-lightbox-thumbs';
import { registerVideoPlugin } from './gallery-lightbox-video';

export interface LightboxInstance {
	init: () => void;
	destroy: () => void;
}

/**
 * Initializes PhotoSwipe v5 on a gallery element.
 */
export function initGalleryLightbox(galleryEl: HTMLElement): LightboxInstance | null {
	const links = galleryEl.querySelectorAll<HTMLAnchorElement>('a[data-lightbox="hd-gallery"]');
	if (!links.length) {
		return null;
	}

	const lightbox = new PhotoSwipeLightbox({
		gallery: galleryEl,
		children: 'a[data-lightbox="hd-gallery"]',
		pswpModule: () => import('photoswipe'),
		showHideAnimationType: 'zoom',
		bgOpacity: 0.88,
		padding: { top: 20, bottom: 20, left: 20, right: 20 },
		wheelToZoom: true,
	});

	// Register plugins
	registerThumbsStrip(lightbox);
	registerVideoPlugin(lightbox);

	// QuickView <dialog> clipping fix: append PhotoSwipe overlay to document.body
	lightbox.on('beforeOpen', () => {
		const dialog = galleryEl.closest('dialog');
		if (dialog) {
			dialog.setAttribute('data-pswp-active', 'true');
		}
	});

	lightbox.on('close', () => {
		const dialog = galleryEl.closest('dialog');
		if (dialog) {
			dialog.removeAttribute('data-pswp-active');
		}
	});

	lightbox.init();

	return {
		init: () => lightbox.init(),
		destroy: () => {
			try {
				lightbox.destroy();
			} catch (_) {}
		},
	};
}
