/**
 * Smooth Cursor-Tracking Hover Zoom Lens for Single Product Gallery.
 *
 * Implements GPU-accelerated, aspect-ratio preserving inner zoom (transform-origin based)
 * and circular magnifying glass overlay with buttery LERP interpolation, zero layout reflows,
 * and pure CSS Custom Properties.
 *
 * @package HDWCGallery\Gallery
 */

export interface ZoomConfig {
	scale: number;
	lensMode: 'inner' | 'circle';
}

export interface ZoomController {
	destroy: () => void;
}

const LENS_SIZE = 180; // Diameter of circular lens in px
const LERP_EASE = 0.2; // Smooth inertia damping factor

/**
 * Initializes zoom on a main slide figure element.
 */
export function initSlideZoom(figureEl: HTMLElement, config: ZoomConfig): ZoomController | null {
	// Skip video slides (preserve prominent play badge interaction)
	if (figureEl.querySelector('.hd-gallery__play-badge')) {
		return null;
	}

	const img = figureEl.querySelector<HTMLImageElement>('img');
	if (!img) return null;

	const scale = Math.max(1.2, Math.min(config.scale || 2.0, 4.0));
	const lensMode = config.lensMode === 'circle' ? 'circle' : 'inner';

	let isHovered = false;
	let rafId: number | null = null;
	let rect: DOMRect | null = null;
	let imgRect: DOMRect | null = null;

	let targetX = 0;
	let targetY = 0;
	let currentX = 0;
	let currentY = 0;

	// Circle lens element (created on-demand for circle mode)
	let lensEl: HTMLElement | null = null;
	if (lensMode === 'circle') {
		lensEl = figureEl.querySelector<HTMLElement>('.hd-gallery__lens');
		if (!lensEl) {
			lensEl = document.createElement('div');
			lensEl.className = 'hd-gallery__lens';
			lensEl.setAttribute('aria-hidden', 'true');
			figureEl.appendChild(lensEl);
		}
	}

	/* ---------- Animation Loop for Smooth LERP Tracking ---------- */

	const animateInnerZoom = () => {
		if (!isHovered) return;

		currentX += (targetX - currentX) * LERP_EASE;
		currentY += (targetY - currentY) * LERP_EASE;

		figureEl.style.setProperty('--hd-zoom-origin-x', `${currentX.toFixed(2)}%`);
		figureEl.style.setProperty('--hd-zoom-origin-y', `${currentY.toFixed(2)}%`);

		rafId = requestAnimationFrame(animateInnerZoom);
	};

	const animateCircleLens = () => {
		if (!isHovered || !rect || !imgRect || !lensEl) return;

		currentX += (targetX - currentX) * LERP_EASE;
		currentY += (targetY - currentY) * LERP_EASE;

		const imgW = imgRect.width;
		const imgH = imgRect.height;
		const imgLeft = imgRect.left - rect.left;
		const imgTop = imgRect.top - rect.top;

		const relX = currentX - imgLeft;
		const relY = currentY - imgTop;

		const bgX = -(relX * scale - LENS_SIZE / 2);
		const bgY = -(relY * scale - LENS_SIZE / 2);

		figureEl.style.setProperty('--hd-lens-x', `${currentX.toFixed(1)}px`);
		figureEl.style.setProperty('--hd-lens-y', `${currentY.toFixed(1)}px`);
		figureEl.style.setProperty('--hd-lens-bg-w', `${(imgW * scale).toFixed(1)}px`);
		figureEl.style.setProperty('--hd-lens-bg-h', `${(imgH * scale).toFixed(1)}px`);
		figureEl.style.setProperty('--hd-lens-bg-x', `${bgX.toFixed(1)}px`);
		figureEl.style.setProperty('--hd-lens-bg-y', `${bgY.toFixed(1)}px`);

		rafId = requestAnimationFrame(animateCircleLens);
	};

	/* ---------- Pointer Event Listeners ---------- */

	const onPointerEnter = (e: PointerEvent) => {
		if (e.pointerType === 'touch') return; // Bypass on touch devices

		rect = figureEl.getBoundingClientRect();
		imgRect = img.getBoundingClientRect();
		if (rect.width <= 0 || rect.height <= 0 || imgRect.width <= 0 || imgRect.height <= 0) return;

		isHovered = true;

		if (lensMode === 'inner') {
			// Calculate coordinates relative to actual rendered image bounds (respects object-fit letterboxing)
			const relX = e.clientX - imgRect.left;
			const relY = e.clientY - imgRect.top;
			targetX = currentX = Math.max(0, Math.min((relX / imgRect.width) * 100, 100));
			targetY = currentY = Math.max(0, Math.min((relY / imgRect.height) * 100, 100));

			figureEl.style.setProperty('--hd-zoom-origin-x', `${currentX.toFixed(2)}%`);
			figureEl.style.setProperty('--hd-zoom-origin-y', `${currentY.toFixed(2)}%`);
			figureEl.style.setProperty('--hd-zoom-scale', scale.toString());
			figureEl.classList.add('is-zooming', 'is-zooming--inner');

			if (rafId) cancelAnimationFrame(rafId);
			rafId = requestAnimationFrame(animateInnerZoom);
		} else if (lensMode === 'circle' && lensEl) {
			const zoomSrc = img.getAttribute('data-zoom-src') || img.currentSrc || img.src;
			lensEl.style.backgroundImage = `url("${zoomSrc}")`;

			const curX = e.clientX - rect.left;
			const curY = e.clientY - rect.top;
			targetX = currentX = curX;
			targetY = currentY = curY;

			figureEl.classList.add('is-zooming', 'is-zooming--circle');
			lensEl.classList.add('is-active');

			if (rafId) cancelAnimationFrame(rafId);
			rafId = requestAnimationFrame(animateCircleLens);
		}
	};

	const onPointerMove = (e: PointerEvent) => {
		if (!isHovered || e.pointerType === 'touch' || !rect || !imgRect) return;

		if (lensMode === 'inner') {
			const relX = e.clientX - imgRect.left;
			const relY = e.clientY - imgRect.top;
			targetX = Math.max(0, Math.min((relX / imgRect.width) * 100, 100));
			targetY = Math.max(0, Math.min((relY / imgRect.height) * 100, 100));
		} else if (lensMode === 'circle') {
			targetX = e.clientX - rect.left;
			targetY = e.clientY - rect.top;
		}
	};

	const onPointerLeave = () => {
		if (!isHovered) return;
		isHovered = false;

		if (rafId) {
			cancelAnimationFrame(rafId);
			rafId = null;
		}

		rect = null;
		imgRect = null;

		if (lensMode === 'inner') {
			figureEl.classList.remove('is-zooming', 'is-zooming--inner');
			figureEl.style.removeProperty('--hd-zoom-scale');
			// Retain origin coordinates during scale-down transition to prevent visual jump
		} else if (lensMode === 'circle') {
			figureEl.classList.remove('is-zooming', 'is-zooming--circle');
			lensEl?.classList.remove('is-active');
			figureEl.style.removeProperty('--hd-lens-x');
			figureEl.style.removeProperty('--hd-lens-y');
			figureEl.style.removeProperty('--hd-lens-bg-w');
			figureEl.style.removeProperty('--hd-lens-bg-h');
			figureEl.style.removeProperty('--hd-lens-bg-x');
			figureEl.style.removeProperty('--hd-lens-bg-y');
		}
	};

	figureEl.addEventListener('pointerenter', onPointerEnter);
	figureEl.addEventListener('pointermove', onPointerMove);
	figureEl.addEventListener('pointerleave', onPointerLeave);

	return {
		destroy: () => {
			figureEl.removeEventListener('pointerenter', onPointerEnter);
			figureEl.removeEventListener('pointermove', onPointerMove);
			figureEl.removeEventListener('pointerleave', onPointerLeave);

			if (rafId) cancelAnimationFrame(rafId);

			figureEl.classList.remove('is-zooming', 'is-zooming--inner', 'is-zooming--circle');
			figureEl.style.removeProperty('--hd-zoom-scale');
			figureEl.style.removeProperty('--hd-zoom-origin-x');
			figureEl.style.removeProperty('--hd-zoom-origin-y');
			figureEl.style.removeProperty('--hd-lens-x');
			figureEl.style.removeProperty('--hd-lens-y');
			figureEl.style.removeProperty('--hd-lens-bg-w');
			figureEl.style.removeProperty('--hd-lens-bg-h');
			figureEl.style.removeProperty('--hd-lens-bg-x');
			figureEl.style.removeProperty('--hd-lens-bg-y');

			if (lensEl && lensEl.parentNode === figureEl) {
				lensEl.remove();
			}
		},
	};
}
