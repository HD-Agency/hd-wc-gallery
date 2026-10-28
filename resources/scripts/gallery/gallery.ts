/**
 * Single Product Gallery & Lightbox — Main Module Entry.
 *
 * Scans and initializes all [data-wc-gallery] and .woocommerce-product-gallery
 * instances in the DOM.
 *
 * @package HDWCGallery\Gallery
 */

import '../../styles/gallery.scss';

import { createWeakStore } from '../shared/weak';
import { parseJSON } from '../shared/helpers';
import { initGallerySliders, GallerySliderInstance, GallerySliderOptions } from './gallery-slider';
import { initGalleryLightbox, LightboxInstance } from './gallery-lightbox';
import { initVariationSwap, VariationController } from './gallery-variation';
import { initSlideZoom, ZoomController } from './gallery-zoom';

interface GalleryInstance {
	slider: GallerySliderInstance | null;
	lightbox: LightboxInstance | null;
	variation: VariationController | null;
	destroy: () => void;
}

const instances = createWeakStore<HTMLElement, GalleryInstance>();

/**
 * Default fallback options.
 */
const DEFAULT_OPTIONS: GallerySliderOptions = {
	layout: 'below',
	zoom: true,
	zoomScale: 2.0,
	lensMode: 'inner',
};

/**
 * Initializes a single gallery element.
 */
export function init(galleryEl: HTMLElement): GalleryInstance | null {
	if (instances.has(galleryEl)) {
		return instances.get(galleryEl) || null;
	}

	const rawConfig = galleryEl.getAttribute('data-gallery-config') || galleryEl.getAttribute('data-hde-gallery-config');
	const config = parseJSON<Partial<GallerySliderOptions>>(rawConfig, {});
	const options: GallerySliderOptions = {
		...DEFAULT_OPTIONS,
		...config,
	};

	let slider: GallerySliderInstance | null = null;
	let lightbox: LightboxInstance | null = null;
	let variation: VariationController | null = null;
	const stackedZoomControllers: ZoomController[] = [];

	// 1. Initialize Sliders (unless stacked mode without slider)
	if (options.layout !== 'stacked') {
		slider = initGallerySliders(galleryEl, options);
	} else if (options.zoom) {
		// Initialize zoom for stacked grid items
		const figures = galleryEl.querySelectorAll<HTMLElement>('.hd-gallery__figure');
		figures.forEach((fig) => {
			const ctrl = initSlideZoom(fig, {
				scale: options.zoomScale || 2.0,
				lensMode: options.lensMode || 'inner',
			});
			if (ctrl) {
				stackedZoomControllers.push(ctrl);
			}
		});
	}

	// 2. Initialize PhotoSwipe Lightbox
	lightbox = initGalleryLightbox(galleryEl);

	// 3. Initialize WooCommerce variation swap listener
	if (slider) {
		variation = initVariationSwap(galleryEl, slider);
	}

	const instance: GalleryInstance = {
		slider,
		lightbox,
		variation,
		destroy: () => {
			stackedZoomControllers.forEach((c) => c.destroy());
			variation?.destroy();
			lightbox?.destroy();
			slider?.destroy();
			instances.delete(galleryEl);
		},
	};

	instances.set(galleryEl, instance);
	return instance;
}

/**
 * Destroys a gallery instance on an element.
 */
export function destroy(galleryEl: HTMLElement): void {
	const instance = instances.get(galleryEl);
	if (instance) {
		instance.destroy();
	}
}

/**
 * Queries and initializes all product galleries within a root scope.
 */
export function initAll(root: Document | Element = document): void {
	const selector = '[data-wc-gallery], .woocommerce-product-gallery';
	const matchedRoots = root.nodeType === 1 && (root as Element).matches?.(selector) ? [root as HTMLElement] : [];
	const children = Array.from(root.querySelectorAll<HTMLElement>(selector));
	const targets = [...matchedRoots, ...children];

	targets.forEach((el) => {
		try {
			init(el);
		} catch (err) {
			console.error('[HD WC Gallery] Failed to initialize gallery:', err);
		}
	});
}

/**
 * Destroys all product galleries within a root scope.
 */
export function destroyAll(root: Document | Element = document): void {
	const selector = '[data-wc-gallery], .woocommerce-product-gallery';
	const matchedRoots = root.nodeType === 1 && (root as Element).matches?.(selector) ? [root as HTMLElement] : [];
	const children = Array.from(root.querySelectorAll<HTMLElement>(selector));
	const targets = [...matchedRoots, ...children];

	targets.forEach((el) => {
		destroy(el);
	});
}

// Auto-run on DOMContentLoaded if not loaded as lazy module
if (typeof document !== 'undefined') {
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', () => initAll(document));
	} else {
		initAll(document);
	}

	// Listen for dynamic scans (QuickView, AJAX tabs)
	const handleScan = (e: Event) => {
		const root = (e as CustomEvent)?.detail?.root || document;
		initAll(root);
	};

	document.addEventListener('hd:scan', handleScan);
	document.addEventListener('hde:scan', handleScan);
	document.addEventListener('core:scan', handleScan);
}

export default {
	initAll,
	destroyAll,
	init,
	destroy,
};
