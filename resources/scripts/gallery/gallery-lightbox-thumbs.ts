/**
 * PhotoSwipe v5 UI Plugin — Bottom Thumbnail Strip.
 *
 * Adds an interactive, touch-scrollable thumbnail strip at the bottom
 * of the PhotoSwipe fullscreen lightbox overlay.
 *
 * @package HDWCGallery\Gallery
 */

import PhotoSwipeLightbox from 'photoswipe/lightbox';
import PhotoSwipe from 'photoswipe';

const STRIP_HEIGHT = 72;
const CLASS_THUMB_FIGURE = 'pswp__thumb-figure';
const CLASS_VIDEO = 'is-video';
const CLASS_ACTIVE = 'is-active';

/**
 * Resolves thumbnail URL for a slide at given index.
 */
function resolveThumbSrc(pswp: PhotoSwipe, index: number): string | null {
	const ds = pswp.options.dataSource;

	if (ds && typeof ds === 'object' && 'gallery' in ds && 'items' in ds) {
		const items = (ds as any).items;
		const item = items?.[index];
		if (!item) return null;

		if (item instanceof Element) {
			const img = item.querySelector('img');
			if (img?.src) return img.src;
			return (item as HTMLAnchorElement).href || null;
		}

		return item?.msrc || item?.src || null;
	}

	if (Array.isArray(ds)) {
		const item = ds[index];
		return item?.msrc || item?.src || null;
	}

	return null;
}

/**
 * Registers bottom thumbnail strip on a PhotoSwipeLightbox instance.
 */
export function registerThumbsStrip(lightbox: PhotoSwipeLightbox): void {
	lightbox.on('uiRegister', () => {
		const pswp = lightbox.pswp;
		if (!pswp) return;

		const numItems = pswp.getNumItems();
		if (numItems <= 1) return;

		const padding = (typeof pswp.options.padding === 'object' && pswp.options.padding !== null ? pswp.options.padding : {}) as Record<string, number | undefined>;

		pswp.options.padding = {
			top: padding.top ?? 0,
			bottom: Math.max(padding.bottom ?? 0, STRIP_HEIGHT),
			left: padding.left ?? 0,
			right: padding.right ?? 0,
		};

		pswp.ui?.registerElement({
			name: 'thumbs-strip',
			appendTo: 'root',
			isButton: false,
			onInit: (el: HTMLElement, instance: PhotoSwipe) => {
				for (let i = 0; i < numItems; i++) {
					const src = resolveThumbSrc(instance, i);
					if (!src) continue;

					const ds = instance.options.dataSource as any;
					const domItem = ds && 'gallery' in ds && ds.items ? ds.items[i] : null;
					const isVideo = domItem instanceof Element && !!domItem.getAttribute('data-pswp-video-url');

					const figure = document.createElement('figure');
					figure.className = CLASS_THUMB_FIGURE + (isVideo ? ` ${CLASS_VIDEO}` : '');
					figure.dataset.index = String(i);
					figure.setAttribute('tabindex', '0');
					figure.setAttribute('role', 'button');
					figure.setAttribute('aria-label', `Go to slide ${i + 1}`);

					const img = document.createElement('img');
					img.src = src;
					img.alt = '';
					img.draggable = false;
					figure.appendChild(img);

					if (isVideo) {
						const badge = document.createElement('span');
						badge.className = 'pswp__thumb-play-icon';
						badge.innerHTML = '<svg viewBox="0 0 24 24" fill="currentColor" width="12" height="12"><path d="M8 5v14l11-7z"/></svg>';
						figure.appendChild(badge);
					}

					figure.addEventListener('click', () => {
						instance.goTo(i);
					});

					figure.addEventListener('keydown', (e) => {
						if (e.key === 'Enter' || e.key === ' ') {
							e.preventDefault();
							instance.goTo(i);
						}
					});

					el.appendChild(figure);
				}

				const syncActive = () => {
					const currIndex = instance.currIndex;
					const figures = el.querySelectorAll<HTMLElement>(`.${CLASS_THUMB_FIGURE}`);
					figures.forEach((fig) => {
						const idx = Number(fig.dataset.index);
						const isActive = idx === currIndex;
						fig.classList.toggle(CLASS_ACTIVE, isActive);
						if (isActive) {
							fig.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
						}
					});
				};

				instance.on('change', syncActive);
				syncActive();
			},
		});
	});
}
