// modules/woocommerce/gallery/gallery-thumbs.js
// Swiper gallery + thumbnail strip + magnifying glass zoom + variation swap

import Swiper from 'swiper';
import { Navigation, Thumbs, FreeMode, Pagination, Keyboard, Zoom, Manipulation } from 'swiper/modules';
import PhotoSwipe from 'photoswipe';
import 'photoswipe/style.css';

// Local DOM helpers (inlined for self-contained plugin execution)
const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));
const queryScope = (selector, root = document) => {
	const matches = root.nodeType === 1 && root.matches?.(selector) ? [root] : [];
	return [...matches, ...root.querySelectorAll(selector)];
};
const splitEvents = (ev) => ev.split(' ').filter(Boolean);
const isCollection = (el) => el instanceof NodeList || el instanceof HTMLCollection || Array.isArray(el);
const on = (el, ev, handler, opts) => {
	if (!el) return;
	const events = splitEvents(ev);
	const bind = (target) => events.forEach((e) => target.addEventListener(e, handler, opts));
	isCollection(el) ? el.forEach(bind) : bind(el);
};
const off = (el, ev, handler, opts) => {
	if (!el) return;
	const events = splitEvents(ev);
	const unbind = (target) => events.forEach((e) => target.removeEventListener(e, handler, opts));
	isCollection(el) ? el.forEach(unbind) : unbind(el);
};
const splitClasses = (cls) => cls.split(' ').filter(Boolean);
const hasClass = (el, cls) => !!(el && splitClasses(cls).every((c) => el.classList.contains(c)));
const addClass = (el, cls) => {
	if (!el) return;
	const classes = splitClasses(cls);
	const apply = (target) => target.classList.add(...classes);
	isCollection(el) ? el.forEach(apply) : apply(el);
};
const removeClass = (el, cls) => {
	if (!el) return;
	const classes = splitClasses(cls);
	const apply = (target) => target.classList.remove(...classes);
	isCollection(el) ? el.forEach(apply) : apply(el);
};
const css = (el, styles = {}) => {
	if (!el) return;
	Object.entries(styles).forEach(([k, v]) => {
		if (k === '__proto__' || k === 'constructor' || k === 'prototype') return;
		if (k.startsWith('--')) el.style.setProperty(k, String(v));
		else Reflect.set(el.style, k, v);
	});
};
const create = (tag, attrs = {}) => {
	const el = document.createElement(tag);
	Object.entries(attrs).forEach(([k, v]) => {
		if (k === 'class') el.className = v;
		else if (k === 'html') el.innerHTML = v;
		else if (k === 'text') el.textContent = v;
		else el.setAttribute(k, String(v));
	});
	return el;
};

import './gallery-thumbs.scss';
import tracking from './gallery-tracking.js';

/** @type {WeakMap<HTMLElement, {main: Swiper, thumbs: Swiper|null, resizeHandler: Function|null}>} */
const instances = new WeakMap();

/** Active PhotoSwipe instance (singleton guard) */
let activePswp = null;

/** YouTube/Vimeo URL pattern for video detection */
const VIDEO_URL_RE = /\.(mp4|webm)(\?|$)|youtu\.?be|vimeo\.com/i;

/**
 * Escape string for safe HTML attribute interpolation.
 * @param {string} str
 * @returns {string}
 */
const escapeAttr = (str) =>
	String(str ?? '')
		.replace(/&/g, '&amp;')
		.replace(/"/g, '&quot;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;');

/**
 * Build PhotoSwipe-compatible HTML string for a video URL.
 * Single source of truth for video embed rendering in lightbox.
 *
 * @param {string} url - Video URL (mp4/webm, YouTube, Vimeo, or generic iframe)
 * @param {string} [videoType] - Explicit video type hint (e.g. 'mp4')
 * @returns {{ html: string }}
 */
function buildVideoSlideHtml(url, videoType) {
	const safeUrl = escapeAttr(url);

	if (videoType === 'mp4' || url.match(/\.(mp4|webm)(\?|$)/i)) {
		return { html: `<div class="hd-gallery-video-fs"><video src="${safeUrl}" controls autoplay playsinline style="width:100%;max-height:90vh"></video></div>` };
	}

	const ytId = extractYouTubeId(url);
	if (ytId) {
		return {
			html: `<div class="hd-gallery-video-fs"><iframe src="https://www.youtube.com/embed/${ytId}?autoplay=1&rel=0" allow="autoplay; fullscreen" allowfullscreen frameborder="0" style="width:100%;height:80vh"></iframe></div>`,
		};
	}

	const vimeoId = extractVimeoId(url);
	if (vimeoId) {
		return {
			html: `<div class="hd-gallery-video-fs"><iframe src="https://player.vimeo.com/video/${vimeoId}?autoplay=1" allow="autoplay; fullscreen" allowfullscreen frameborder="0" style="width:100%;height:80vh"></iframe></div>`,
		};
	}

	return { html: `<div class="hd-gallery-video-fs"><iframe src="${safeUrl}" allow="autoplay; fullscreen" allowfullscreen frameborder="0" style="width:100%;height:80vh"></iframe></div>` };
}

/**
 * Build PhotoSwipe dataSource from current gallery DOM.
 * Reads fresh state on every call — safe for variation swaps.
 *
 * @param {HTMLElement} gallery - Gallery container
 * @returns {Array<{src: string, w: number, h: number, alt?: string, html?: string}>}
 */
function buildGalleryDataSource(gallery) {
	const links = $$('a[data-lightbox="hd-gallery"]', gallery).filter((link) => !link.closest('.swiper-slide-duplicate'));
	return links.map((link) => {
		const img = $('img', link);
		const msrc = img?.src || link.href || '';

		// Video slide
		const videoType = link.dataset.videoType;
		if (videoType || VIDEO_URL_RE.test(link.href)) {
			const videoSlide = buildVideoSlideHtml(link.href, videoType);
			videoSlide.msrc = msrc;
			return videoSlide;
		}

		// Image slide — resolve dimensions
		const w = Number(img?.dataset.large_image_width || img?.naturalWidth || 0);
		const h = Number(img?.dataset.large_image_height || img?.naturalHeight || 0);
		const src = img?.dataset.large_image || link.href;

		return {
			src,
			msrc,
			w,
			h,
			alt: link.dataset.caption || img?.alt || '',
		};
	});
}

/**
 * Open PhotoSwipe with programmatic dataSource from current gallery DOM.
 * Singleton guard — prevents duplicate opens.
 *
 * @param {HTMLElement} gallery - Gallery container
 * @param {Swiper|null} swiper - Main Swiper instance (null for stacked layout)
 * @param {Object} trackingConfig - Tracking configuration
 * @param {string} productId - Product ID for tracking
 * @param {number|null} [clickedIndex=null] - Override starting index (for stacked layout)
 */
function openPhotoSwipe(gallery, swiper, trackingConfig, productId, clickedIndex = null) {
	// Singleton guard
	if (activePswp) return;

	const dataSource = buildGalleryDataSource(gallery);
	if (!dataSource.length) return;

	// Calculate starting index: explicit override > Swiper activeIndex > 0
	const index = clickedIndex ?? (swiper ? (swiper.realIndex ?? swiper.activeIndex ?? 0) : 0);

	// If gallery is inside a <dialog> (e.g. QuickView popup), append PhotoSwipe
	// to the dialog so it renders in the same top-layer stacking context.
	// Temporarily remove overflow clipping so the fixed-position lightbox
	// can render fullscreen beyond the dialog's bounds.
	const dialog = gallery.closest('dialog[open]');
	const modalEl = dialog?.querySelector('.hd-modal__inner') ?? dialog;
	if (dialog) {
		dialog.style.overflow = 'visible';
		if (modalEl !== dialog) modalEl.style.overflow = 'visible';
	}

	const pswp = new PhotoSwipe({
		dataSource,
		index,
		showHideAnimationType: 'fade',
		bgOpacity: 0.9,
		loop: dataSource.length > 1,
		...(dialog ? { appendToEl: dialog } : {}),
	});

	// Register bottom thumbnail strip with dynamic aspect ratio class
	const aspectRatioClass = gallery.dataset.aspectRatio || 'as-1-1';
	pswp.on('uiRegister', () => {
		const numItems = pswp.getNumItems();
		if (numItems <= 1) return;

		// Reserve bottom viewport space so the main image does not overlap the strip
		const existingPadding = pswp.options.padding || {};
		pswp.options.padding = {
			top: existingPadding.top || 0,
			bottom: Math.max(existingPadding.bottom || 0, 72),
			left: existingPadding.left || 0,
			right: existingPadding.right || 0,
		};

		pswp.ui.registerElement({
			name: 'thumbs-strip',
			appendTo: 'root',
			isButton: false,
			onInit: (el, pswpInstance) => {
				// Build thumbnail images
				for (let i = 0; i < numItems; i++) {
					const item = pswpInstance.options.dataSource[i];
					const src = item?.msrc || item?.src || null;
					if (!src) continue;

					const img = document.createElement('img');
					img.src = src;
					img.alt = '';
					img.draggable = false;
					img.dataset.index = String(i);
					img.setAttribute('aria-label', `Go to slide ${i + 1}`);
					if (aspectRatioClass) {
						img.classList.add(aspectRatioClass);
					}
					el.appendChild(img);
				}

				// Prevent PhotoSwipe gesture system from capturing pointer events on the strip
				el.addEventListener('pointerdown', (e) => e.stopPropagation());
				el.addEventListener('touchstart', (e) => e.stopPropagation(), { passive: true });

				// Click → navigate to slide
				el.addEventListener('click', (e) => {
					e.stopPropagation();
					const img = e.target.closest('img[data-index]');
					if (!img) return;
					const idx = parseInt(img.dataset.index, 10);
					if (!Number.isNaN(idx)) pswpInstance.goTo(idx);
				});

				// Sync active state
				const updateActive = () => {
					const current = pswpInstance.currIndex;
					const thumbs = el.children;
					for (let i = 0; i < thumbs.length; i++) {
						const thumb = thumbs[i];
						const idx = parseInt(thumb.dataset?.index, 10);
						thumb.classList.toggle('is-active', idx === current);
					}

					// Auto-scroll active thumb into view
					const activeThumb = el.querySelector('.is-active');
					if (activeThumb) {
						activeThumb.scrollIntoView({
							behavior: 'smooth',
							block: 'nearest',
							inline: 'center',
						});
					}
				};

				pswpInstance.on('change', updateActive);
				updateActive();
			},
		});
	});

	activePswp = pswp;

	// Live sync: keep background Swiper in sync while navigating PhotoSwipe
	if (swiper) {
		pswp.on('change', () => {
			if (swiper.params?.loop) {
				swiper.slideToLoop(pswp.currIndex, 0);
			} else {
				swiper.slideTo(pswp.currIndex, 0);
			}
		});
	}

	// Tracking
	pswp.on('openingAnimationEnd', () => {
		dispatchTrackingEvent('lightbox_open', trackingConfig, productId, index);
	});

	// Cleanup on destroy — restore overflow on dialog
	pswp.on('destroy', () => {
		activePswp = null;
		if (dialog) {
			dialog.style.overflow = '';
			if (modalEl !== dialog) modalEl.style.overflow = '';
		}
	});

	pswp.init();
}

/**
 * Open PhotoSwipe with a single video slide (for overlay button).
 * Reuses the same singleton guard and dialog stacking context as openPhotoSwipe.
 *
 * @param {HTMLAnchorElement} link - The overlay <a> element with href = video URL
 * @param {Object} trackingConfig - Tracking configuration
 * @param {string} productId - Product ID for tracking
 * @param {HTMLElement} gallery - Gallery container (for dialog detection)
 */
function openVideoLightbox(link, trackingConfig, productId, gallery) {
	if (activePswp) return;

	const href = link.href;
	if (!href) return;

	const dataSource = [buildVideoSlideHtml(href, link.dataset.videoType)];

	const dialog = gallery.closest('dialog[open]');
	const modalEl = dialog?.querySelector('.hd-modal__inner') ?? dialog;
	if (dialog) {
		dialog.style.overflow = 'visible';
		if (modalEl !== dialog) modalEl.style.overflow = 'visible';
	}

	const pswp = new PhotoSwipe({
		dataSource,
		index: 0,
		showHideAnimationType: 'fade',
		bgOpacity: 0.9,
		loop: false,
		...(dialog ? { appendToEl: dialog } : {}),
	});

	activePswp = pswp;

	pswp.on('openingAnimationEnd', () => {
		dispatchTrackingEvent('video_lightbox_open', trackingConfig, productId, 0);
	});

	pswp.on('destroy', () => {
		activePswp = null;
		if (dialog) {
			dialog.style.overflow = '';
			if (modalEl !== dialog) modalEl.style.overflow = '';
		}
	});

	pswp.init();
}

/**
 * Initialize gallery within a root element.
 * @param {HTMLElement} root
 */
function initGallery(root) {
	const galleries = queryScope('[data-wc-gallery]', root);
	if (!galleries.length) return;

	galleries.forEach((gallery) => {
		if (instances.has(gallery)) return; // Already initialized

		const thumbsEl = $('.hd-gallery__thumbs-slider', gallery);
		const mainEl = $('.hd-gallery__slider', gallery);
		const isStacked = hasClass(gallery, 'hd-gallery--stacked');

		// Stacked layout has no Swiper — handle separately
		if (isStacked) {
			initStackedGallery(gallery);
			return;
		}

		if (!mainEl) return;

		// F4: Detect vertical thumbs (left/right positions).
		const isVerticalThumbs = hasClass(gallery, 'hd-gallery--left') || hasClass(gallery, 'hd-gallery--right');

		// N6: Responsive thumb counts from data attrs (0 or 1 = auto/CSS-based)
		const thumbsMobile = parseInt(gallery.dataset.thumbsMobile, 10) || 0;
		const thumbsTablet = parseInt(gallery.dataset.thumbsTablet, 10) || 0;
		const thumbsDesktop = parseInt(gallery.dataset.thumbsDesktop, 10) || 0;
		const useAutoThumbs = thumbsMobile <= 1 || thumbsTablet <= 1 || thumbsDesktop <= 1;

		// Init thumbs slider first (Swiper needs it before main)
		let thumbsSwiper = null;
		if (thumbsEl) {
			const thumbsPrev = $('.hd-gallery__thumbs-nav--prev', gallery);
			const thumbsNext = $('.hd-gallery__thumbs-nav--next', gallery);

			thumbsSwiper = new Swiper(thumbsEl, {
				modules: [FreeMode, Manipulation, ...(thumbsPrev && thumbsNext ? [Navigation] : [])],
				spaceBetween: 8,

				// Vertical thumbs always auto; horizontal uses config or auto
				slidesPerView: isVerticalThumbs || useAutoThumbs ? 'auto' : thumbsMobile,
				freeMode: true,
				watchSlidesProgress: true,
				direction: isVerticalThumbs ? 'vertical' : 'horizontal',
				...(thumbsPrev && thumbsNext ? { navigation: { prevEl: thumbsPrev, nextEl: thumbsNext } } : {}),

				// No responsive breakpoints for vertical or auto-sized thumbs
				breakpoints:
					isVerticalThumbs || useAutoThumbs
						? undefined
						: {
								640: { slidesPerView: thumbsTablet },
								1024: { slidesPerView: thumbsDesktop },
							},
			});
		}

		// Init main slider
		const mainSwiper = new Swiper(mainEl, {
			modules: [Navigation, Thumbs, Keyboard, Zoom, Manipulation],
			spaceBetween: 0,
			slidesPerView: 1,
			thumbs: thumbsSwiper ? { swiper: thumbsSwiper } : undefined,
			navigation: {
				nextEl: $('.hd-gallery__nav--next', gallery),
				prevEl: $('.hd-gallery__nav--prev', gallery),
			},

			// N5: Keyboard navigation (arrow keys)
			keyboard: {
				enabled: true,
				onlyInViewport: true,
			},

			// N1: Pinch-to-zoom on mobile
			zoom: {
				maxRatio: 3,
				minRatio: 1,
			},
			on: {
				slideChange(swiper) {
					// Re-bind zoom for active slide
					initZoomForSlide(swiper.slides.at(swiper.activeIndex));
				},
				slideChangeTransitionEnd(swiper) {
					// Pause videos only after slide is fully out of view
					pauseInactiveVideos(swiper);
				},
			},
		});

		// Swiper may init before the first slide's image loads (two-level async import).
		// When that happens, dimensions are wrong and slides appear broken until interaction.
		// Fix: wait for the first image to load, then recalculate.
		const firstImg = $('img', mainSwiper.slides?.[0]);
		if (firstImg && !firstImg.complete) {
			firstImg.addEventListener(
				'load',
				() => {
					mainSwiper.update();
					thumbsSwiper?.update();
				},
				{ once: true },
			);
		}

		// F4: Sync vertical thumbs height via ResizeObserver.
		// Handles: initial render (images not yet loaded), window resize,
		// and popup context where mainEl height becomes available asynchronously.
		let resizeHandler = null;
		if (isVerticalThumbs && thumbsSwiper) {
			const MOBILE_BP = 768;
			let isMobile = window.innerWidth < MOBILE_BP;
			let syncFrameId = null;

			const syncHeight = () => {
				if (syncFrameId) {
					cancelAnimationFrame(syncFrameId);
				}

				syncFrameId = requestAnimationFrame(() => {
					const nowMobile = window.innerWidth < MOBILE_BP;

					// Switch Swiper direction when breakpoint crosses
					if (nowMobile !== isMobile) {
						isMobile = nowMobile;
						thumbsSwiper.changeDirection(isMobile ? 'horizontal' : 'vertical', true);
						thumbsEl.style.height = '';
					}

					// Only sync height in vertical mode
					if (!isMobile) {
						const mainH = mainEl.offsetHeight;
						if (mainH > 0) {
							thumbsEl.style.height = `${mainH}px`;
							thumbsSwiper.update();
						}
					}
				});
				gallery._hdSyncFrameId = syncFrameId;
			};

			// ResizeObserver: fires when mainEl dimensions change (image load, layout shift, etc.)
			const ro = new ResizeObserver(syncHeight);
			ro.observe(mainEl);

			// Also handle window resize for breakpoint detection
			resizeHandler = () => syncHeight();
			window.addEventListener('resize', resizeHandler, { passive: true });

			// Store observer for cleanup
			gallery._hdResizeObserver = ro;
		}

		instances.set(gallery, { main: mainSwiper, thumbs: thumbsSwiper, resizeHandler, lightboxClick: null });

		// N3: Remove skeleton loading state
		removeClass(gallery, 'hd-gallery--skeleton');

		// Init zoom for first slide
		initZoomForSlide(mainSwiper.slides.at(mainSwiper.activeIndex));

		// N4: Parse tracking config from data-tracking attr
		const trackingConfig = parseTrackingConfig(gallery);
		const productId = gallery.closest('[data-product_id]')?.dataset.product_id || '';

		// Bind PhotoSwipe to gallery image links + video overlay via delegated click
		const handleLightboxClick = (e) => {
			const link = e.target.closest('a[data-lightbox="hd-gallery"]');
			if (link) {
				e.preventDefault();
				openPhotoSwipe(gallery, mainSwiper, trackingConfig, productId);
				return;
			}

			// Video overlay button — opens standalone video in PhotoSwipe
			const videoLink = e.target.closest('a[data-lightbox="hd-gallery-video"]');
			if (videoLink) {
				e.preventDefault();
				openVideoLightbox(videoLink, trackingConfig, productId, gallery);
			}
		};
		on(gallery, 'click', handleLightboxClick);

		// Store handler reference for cleanup
		const inst = instances.get(gallery);
		if (inst) inst.lightboxClick = handleLightboxClick;

		// Variation gallery swap
		initVariationSwap(gallery, mainSwiper, thumbsSwiper);
	});
}

/**
 * Initialize magnifying glass zoom for a single slide.
 * @param {HTMLElement|undefined} slide
 */
function initZoomForSlide(slide) {
	if (!slide) return;

	const container = $('.hd-gallery-zoom', slide);
	if (!container || container.dataset.zoomInit) return;

	const img = $('.hd-gallery-zoom__img', container);
	const lens = $('.hd-gallery-zoom__lens', container);
	if (!img || !lens) return;

	// Mobile: no zoom (touch doesn't support hover)
	if ('ontouchstart' in window) return;

	const scale = parseFloat(container.dataset.zoomScale) || 2.5;
	const lensMode = container.dataset.lensMode || 'circle';
	const isFullMode = lensMode === 'full';
	const lensSize = parseInt(container.dataset.lensSize, 10) || 150;
	const lensHalf = lensSize / 2;

	if (isFullMode) {
		addClass(container, 'hd-gallery-zoom--full');
	} else {
		lens.style.width = `${lensSize}px`;
		lens.style.height = `${lensSize}px`;
	}

	let cachedRect;

	on(container, 'mouseenter', () => {
		const fullSrc = img.dataset.zoomSrc || img.src;
		lens.style.backgroundImage = `url(${fullSrc})`;
		lens.style.backgroundSize = `${img.offsetWidth * scale}px ${img.offsetHeight * scale}px`;
		cachedRect = img.getBoundingClientRect();
		addClass(lens, 'is-active');
		addClass(container, 'is-zooming');
	});

	on(container, 'mousemove', (e) => {
		if (!cachedRect) return;
		const x = Math.max(0, Math.min(e.clientX - cachedRect.left, cachedRect.width));
		const y = Math.max(0, Math.min(e.clientY - cachedRect.top, cachedRect.height));

		if (isFullMode) {
			// Full mode: lens is fixed at inset:0, only move background
			const bgX = (x / cachedRect.width) * (cachedRect.width * scale - cachedRect.width);
			const bgY = (y / cachedRect.height) * (cachedRect.height * scale - cachedRect.height);
			lens.style.backgroundPosition = `-${bgX}px -${bgY}px`;
		} else {
			lens.style.left = `${x - lensHalf}px`;
			lens.style.top = `${y - lensHalf}px`;

			const bgX = (x / cachedRect.width) * (cachedRect.width * scale - lensSize);
			const bgY = (y / cachedRect.height) * (cachedRect.height * scale - lensSize);
			lens.style.backgroundPosition = `-${bgX}px -${bgY}px`;
		}
	});

	on(container, 'mouseleave', () => {
		cachedRect = null;
		removeClass(lens, 'is-active');
		removeClass(container, 'is-zooming');
	});

	container.dataset.zoomInit = '1';
}

// ── Stacked Layout Handler ────────────────────────────────────────

/**
 * Initialize stacked layout gallery — no Swiper, but variation swap + PhotoSwipe.
 * @param {HTMLElement} gallery
 */
function initStackedGallery(gallery) {
	if (instances.has(gallery)) return;

	// N4: Tracking
	const trackingConfig = parseTrackingConfig(gallery);
	const productId = gallery.closest('[data-product_id]')?.dataset.product_id || '';

	// Bind PhotoSwipe for stacked images via delegated click — resolve clicked index from DOM
	const handleLightboxClick = (e) => {
		const link = e.target.closest('a[data-lightbox="hd-gallery"]');
		if (link) {
			e.preventDefault();
			const allLinks = $$('a[data-lightbox="hd-gallery"]', gallery);
			const clickedIndex = allLinks.indexOf(link);
			openPhotoSwipe(gallery, null, trackingConfig, productId, clickedIndex >= 0 ? clickedIndex : 0);
			return;
		}

		// Video overlay button
		const videoLink = e.target.closest('a[data-lightbox="hd-gallery-video"]');
		if (videoLink) {
			e.preventDefault();
			openVideoLightbox(videoLink, trackingConfig, productId, gallery);
		}
	};
	on(gallery, 'click', handleLightboxClick);

	instances.set(gallery, { main: null, thumbs: null, resizeHandler: null, lightboxClick: handleLightboxClick });

	// Variation swap for stacked
	initStackedVariationSwap(gallery);
}

/**
 * Parse variation swap data from gallery and form data attributes.
 * @param {HTMLElement} gallery
 * @param {HTMLElement} form
 * @returns {{ defaultImages: Array, variationGalleries: Object, variationMode: string }}
 */
function parseVariationData(gallery, form) {
	// Cache parsed data on the gallery element to avoid re-parsing on every variation change
	if (gallery._hdVarData) return gallery._hdVarData;

	const safeParse = (json, fallback) => {
		try {
			return JSON.parse(json) || fallback;
		} catch {
			return fallback;
		}
	};

	const data = {
		defaultImages: safeParse(gallery.dataset.defaultImages, []),
		variationGalleries: safeParse(gallery.dataset.variationGalleries, {}),
		variationMode: gallery.dataset.variationMode || 'replace',
	};

	gallery._hdVarData = data;
	return data;
}

/**
 * Handle variation swap for stacked layout — rebuild DOM items.
 * @param {HTMLElement} gallery
 */
function initStackedVariationSwap(gallery) {
	const form = gallery.closest('.product')?.querySelector('.variations_form');
	if (!form) return;

	const $form = jQuery?.(form);
	if (!$form?.length) return;

	const { defaultImages, variationGalleries, variationMode } = parseVariationData(gallery, form);

	const rebuildStacked = (images) => {
		const container = $('.hd-gallery__stacked', gallery);
		if (!container) return;

		// Read aspect class from existing item
		const existingZoom = $('.hd-gallery-zoom', container);
		const aspectClass = existingZoom ? [...existingZoom.classList].find((c) => c.startsWith('as-')) || 'as-1-1' : 'as-1-1';

		container.innerHTML = '';

		images.forEach((img) => {
			const item = create('div', { class: 'hd-gallery__stacked-item' });

			if (img.video) {
				// Video item — lightbox link
				const videoTypeAttr = img.video_type === 'mp4' ? 'mp4' : '';
				const videoDiv = create('div', { class: `hd-gallery-video ${aspectClass}` });
				const link = create('a', {
					href: img.video,
					'data-lightbox': 'hd-gallery',
					'data-caption': img.alt || '',
					...(videoTypeAttr ? { 'data-video-type': videoTypeAttr } : {}),
				});
				const poster = create('img', {
					class: 'hd-gallery-video__poster',
					src: img.src,
					alt: img.alt || '',
					loading: 'eager',
				});
				link.appendChild(poster);
				link.insertAdjacentHTML(
					'beforeend',
					'<span class="hd-gallery-video__play" aria-hidden="true"><svg viewBox="0 0 24 24" fill="currentColor"><polygon points="5,3 19,12 5,21"/></svg></span>',
				);
				videoDiv.appendChild(link);
				item.appendChild(videoDiv);
			} else {
				// Image item
				const zoomDiv = create('div', { class: `hd-gallery-zoom ${aspectClass}` });
				const link = create('a', {
					href: img.full,
					'data-lightbox': 'hd-gallery',
					'data-caption': img.alt || '',
				});
				const imgEl = create('img', {
					class: 'hd-gallery-zoom__img',
					src: img.src,
					alt: img.alt || '',
					loading: 'eager',
					...(img.srcset ? { srcset: img.srcset, sizes: img.sizes } : {}),
					...(img.full ? { 'data-zoom-src': img.full } : {}),
				});
				link.appendChild(imgEl);
				zoomDiv.appendChild(link);
				item.appendChild(zoomDiv);
			}

			container.appendChild(item);
		});

		// No rebind needed — PhotoSwipe reads fresh DOM on each click
	};

	// Shared rebuild logic — apply variationMode to a matched variation
	const applyVariation = (variation) => {
		const varId = String(variation.variation_id);
		const varImages = varId !== '__proto__' && varId !== 'constructor' && varId !== 'prototype' ? Reflect.get(variationGalleries, varId) : null;

		switch (variationMode) {
			case 'prepend':
				if (varImages?.length) {
					const seen = new Set(varImages.map((i) => i.src));
					rebuildStacked([...varImages, ...defaultImages.filter((i) => !seen.has(i.src))]);
				} else {
					rebuildStacked(buildVariationFallback(defaultImages, variation));
				}
				break;
			default: // 'replace'
				rebuildStacked(varImages?.length ? varImages : buildVariationFallback(defaultImages, variation));
				break;
		}
	};

	$form.on('found_variation.hdGallery', (_e, variation) => {
		applyVariation(variation);
	});

	$form.on('reset_data.hdGallery', () => {
		if (defaultImages.length) rebuildStacked(defaultImages);
	});
}

/**
 * Handle variation gallery swap via found_variation / reset_data events
 * AND partial-match preview on single attribute selection.
 * @param {HTMLElement} gallery
 * @param {Swiper} mainSwiper
 * @param {Swiper|null} thumbsSwiper
 */
function initVariationSwap(gallery, mainSwiper, thumbsSwiper) {
	const form = gallery.closest('.product')?.querySelector('.variations_form');
	if (!form) return;

	const $form = jQuery?.(form);
	if (!$form?.length) return;

	const { defaultImages, variationGalleries, variationMode } = parseVariationData(gallery, form);

	// Shared rebuild logic — apply variationMode to a matched variation
	const applyVariation = (variation) => {
		const varId = String(variation.variation_id);
		const varImages = varId !== '__proto__' && varId !== 'constructor' && varId !== 'prototype' ? Reflect.get(variationGalleries, varId) : null;

		switch (variationMode) {
			case 'prepend':
				if (varImages?.length) {
					const seen = new Set(varImages.map((i) => i.src));
					rebuildSlides(gallery, mainSwiper, thumbsSwiper, [...varImages, ...defaultImages.filter((i) => !seen.has(i.src))]);
				} else {
					rebuildSlides(gallery, mainSwiper, thumbsSwiper, buildVariationFallback(defaultImages, variation));
				}
				break;
			default: // 'replace'
				rebuildSlides(gallery, mainSwiper, thumbsSwiper, varImages?.length ? varImages : buildVariationFallback(defaultImages, variation));
				break;
		}
	};

	// Full match — WC fires when all attributes are selected
	$form.on('found_variation.hdGallery', (_e, variation) => {
		applyVariation(variation);
	});

	$form.on('reset_data.hdGallery', () => {
		if (defaultImages.length) {
			rebuildSlides(gallery, mainSwiper, thumbsSwiper, defaultImages);
		}
	});
}

/**
 * Build fallback images for variation without custom gallery.
 * Swaps first image with variation's featured image (WC core behavior).
 * @param {Array} defaultImages
 * @param {Object} variation — WC variation data (has .image)
 * @returns {Array}
 */
function buildVariationFallback(defaultImages, variation) {
	if (!variation?.image?.src || !defaultImages.length) return defaultImages;

	const varImage = {
		src: variation.image.src || '',
		width: variation.image.src_w || 0,
		height: variation.image.src_h || 0,
		thumb: variation.image.gallery_thumbnail_src || variation.image.thumb_src || '',
		full: variation.image.full_src || '',
		srcset: variation.image.srcset || '',
		sizes: variation.image.sizes || '',
		alt: variation.image.alt || '',
	};

	return [varImage, ...defaultImages.slice(1)];
}

/**
 * Rebuild Swiper slides with new image data.
 * @param {HTMLElement} gallery
 * @param {Swiper} mainSwiper
 * @param {Swiper|null} thumbsSwiper
 * @param {Array} images
 */
function rebuildSlides(gallery, mainSwiper, thumbsSwiper, images) {
	if (!mainSwiper?.removeAllSlides) return;

	const zoomContainer = $('.hd-gallery-zoom', gallery);
	const hasZoom = zoomContainer?.dataset.zoomScale;
	const zoomAttrs = hasZoom
		? ` data-zoom-scale="${zoomContainer.dataset.zoomScale}" data-lens-size="${zoomContainer.dataset.lensSize}" data-lens-mode="${zoomContainer.dataset.lensMode || 'circle'}"`
		: '';

	const existingFrame = $('.hd-gallery__thumb-frame', gallery);
	const aspectClass = existingFrame?.classList[1] || 'as-1-1';

	// Build all slide HTML strings first, then batch-append
	const mainSlides = images.map((img) => {
		const safeAlt = escapeAttr(img.alt);
		const dimAttrs = img.width && img.height ? ` width="${img.width}" height="${img.height}"` : '';

		if (img.video) {
			const safeUrl = escapeAttr(img.video);
			if (img.is_product_video) {
				return `<div class="swiper-slide"><div class="hd-gallery-video ${aspectClass}" data-fx-video data-fx-video-url="${safeUrl}" data-fx-video-type="${escapeAttr(img.video_type)}"><img class="hd-gallery-video__poster" src="${escapeAttr(img.src)}" alt="${safeAlt}"${dimAttrs} loading="eager" /><span class="hd-gallery-video__play" aria-label="Play video"><svg viewBox="0 0 24 24" fill="currentColor"><polygon points="5,3 19,12 5,21"/></svg></span></div></div>`;
			}
			const typeAttr = img.video_type === 'mp4' ? ' data-video-type="mp4"' : '';
			return `<div class="swiper-slide"><div class="hd-gallery-video ${aspectClass}"><a href="${safeUrl}" data-lightbox="hd-gallery"${typeAttr} data-caption="${safeAlt}"><img class="hd-gallery-video__poster" src="${escapeAttr(img.src)}" alt="${safeAlt}"${dimAttrs} loading="eager" /><span class="hd-gallery-video__play" aria-label="Play video"><svg viewBox="0 0 24 24" fill="currentColor"><polygon points="5,3 19,12 5,21"/></svg></span></a></div></div>`;
		}

		const srcsetAttr = img.srcset ? ` srcset="${escapeAttr(img.srcset)}" sizes="${escapeAttr(img.sizes)}"` : '';
		const lensHtml = hasZoom ? '<div class="hd-gallery-zoom__lens" aria-hidden="true"></div>' : '';
		const largeImgAttrs = img.width && img.height ? ` data-large_image="${escapeAttr(img.full)}" data-large_image_width="${img.width}" data-large_image_height="${img.height}"` : '';
		return `<div class="swiper-slide"><div class="hd-gallery-zoom ${aspectClass}"${zoomAttrs}><a href="${escapeAttr(img.full)}" data-lightbox="hd-gallery" data-caption="${safeAlt}"><img class="hd-gallery-zoom__img" src="${escapeAttr(img.src)}"${srcsetAttr} data-zoom-src="${escapeAttr(img.full)}" alt="${safeAlt}"${dimAttrs}${largeImgAttrs} /></a>${lensHtml}</div></div>`;
	});

	mainSwiper.removeAllSlides();
	mainSwiper.appendSlide(mainSlides);

	// Batch thumbs rebuild
	if (thumbsSwiper) {
		const thumbSlides = images.map((img) => {
			const videoClass = img.video ? ' hd-gallery__thumb--video' : '';
			const playIcon = img.video ? '<span class="hd-gallery__thumb-play" aria-hidden="true"><svg viewBox="0 0 24 24" fill="currentColor"><polygon points="5,3 19,12 5,21"/></svg></span>' : '';
			return `<div class="swiper-slide${videoClass}"><span class="hd-gallery__thumb-frame ${aspectClass}"><img src="${escapeAttr(img.thumb)}" alt="${escapeAttr(img.alt)}" loading="lazy" /></span>${playIcon}</div>`;
		});

		thumbsSwiper.removeAllSlides();
		thumbsSwiper.appendSlide(thumbSlides);
		thumbsSwiper.update();
	}

	mainSwiper.slideTo(0, 0);
	mainSwiper.update();
	initZoomForSlide(mainSwiper.slides[0]);
}

/**
 * Initialize mini gallery (Quick View) — Swiper with pagination dots only.
 * @param {HTMLElement} root
 */
function initMiniGallery(root) {
	const minis = queryScope('[data-wc-gallery-mini]:not(.swiper-initialized)', root);
	if (!minis.length) return;

	minis.forEach((el) => {
		if (instances.has(el)) return;

		const slides = $$('.swiper-slide', el);
		const hasPagination = slides.length > 1;

		const swiper = new Swiper(el, {
			modules: hasPagination ? [Pagination] : [],
			spaceBetween: 0,
			slidesPerView: 1,
			pagination: hasPagination
				? {
						el: $('.swiper-pagination', el),
						clickable: true,
					}
				: false,
		});

		instances.set(el, { main: swiper, thumbs: null });
	});
}

/**
 * Clean up gallery instances.
 * @param {HTMLElement} root
 */
function destroyGallery(root) {
	const galleries = queryScope('[data-wc-gallery]', root);
	galleries.forEach((gallery) => {
		const inst = instances.get(gallery);
		if (inst) {
			// Remove resize listener to prevent memory leak
			if (inst.resizeHandler) {
				window.removeEventListener('resize', inst.resizeHandler);
			}
			// Disconnect ResizeObserver
			if (gallery._hdResizeObserver) {
				gallery._hdResizeObserver.disconnect();
				delete gallery._hdResizeObserver;
			}
			if (gallery._hdSyncFrameId) {
				cancelAnimationFrame(gallery._hdSyncFrameId);
				delete gallery._hdSyncFrameId;
			}
			// Remove lightbox click handler
			if (inst.lightboxClick) {
				off(gallery, 'click', inst.lightboxClick);
			}
			inst.main?.destroy(true, true);
			inst.thumbs?.destroy(true, true);
			instances.delete(gallery);
		}

		// Unbind namespaced jQuery variation events to prevent handler stacking on reinit
		const form = gallery.closest('.product')?.querySelector('.variations_form');
		if (form && window.jQuery) {
			jQuery(form).off('.hdGallery');
		}

		delete gallery._hdVarData;
		// Close any open PhotoSwipe (destroy event auto-nulls activePswp)
		if (activePswp) {
			activePswp.close();
		}
	});

	// Also destroy mini galleries
	const minis = queryScope('[data-wc-gallery-mini]', root);
	minis.forEach((el) => {
		const inst = instances.get(el);
		if (inst) {
			inst.main?.destroy(true, true);
			instances.delete(el);
		}
	});
}

// ── Inline Video Helpers ────────────────────────────────────────

/**
 * YouTube video ID extraction (mirrors fx-video-embed pattern).
 * @param {string} url
 * @returns {string|null}
 */
function extractYouTubeId(url) {
	if (!url) return null;
	try {
		const u = new URL(url);
		// Standard watch URL: ?v=ID (even with other params like ?reload=9&v=ID)
		if (u.searchParams.has('v')) return u.searchParams.get('v');
		// youtu.be short URL
		if (u.hostname === 'youtu.be') return u.pathname.slice(1).split(/[?&/]/)[0] || null;
		// /embed/ID, /shorts/ID, /v/ID
		const match = u.pathname.match(/\/(?:embed|shorts|v)\/([a-zA-Z0-9_-]{11})/);
		if (match) return match[1];
	} catch {
		// Fallback regex for malformed URLs
		const fallback = url.match(/(?:v=|embed\/|shorts\/|youtu\.be\/)([a-zA-Z0-9_-]{11})/);
		return fallback ? fallback[1] : null;
	}
	return null;
}

/**
 * Extract Vimeo video ID from URL.
 * @param {string} url
 * @returns {string|null}
 */
function extractVimeoId(url) {
	if (!url) return null;
	try {
		const u = new URL(url);
		if (u.hostname.includes('vimeo.com')) {
			// vimeo.com/123456789 or player.vimeo.com/video/123456789
			const match = u.pathname.match(/(\d{6,})/);
			return match ? match[1] : null;
		}
	} catch {
		const fallback = url.match(/vimeo\.com\/(?:video\/)?(\d{6,})/);
		return fallback ? fallback[1] : null;
	}
	return null;
}

/**
 * Activate inline product video — lazy load iframe/video element.
 * Follows fx-video-embed pattern: poster → click → embed overlay → fade in.
 * @param {HTMLElement} container
 */
function activateInlineVideo(container) {
	if (hasClass(container, 'is-video-active')) return;

	const type = container.dataset.fxVideoType;
	const url = container.dataset.fxVideoUrl;
	if (!type || !url) return;

	let el;
	if (type === 'youtube') {
		const videoId = extractYouTubeId(url);
		if (!videoId) return;
		el = create('iframe', {
			class: 'hd-gallery-video__embed',
			src: `https://www.youtube.com/embed/${videoId}?autoplay=1&rel=0&enablejsapi=1&origin=${window.location.origin}`,
			allow: 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture',
			allowfullscreen: '',
			frameborder: '0',
		});
	} else if (type === 'vimeo') {
		const vimeoId = extractVimeoId(url);
		if (!vimeoId) return;
		el = create('iframe', {
			class: 'hd-gallery-video__embed',
			src: `https://player.vimeo.com/video/${vimeoId}?autoplay=1`,
			allow: 'autoplay; fullscreen; picture-in-picture',
			allowfullscreen: '',
			frameborder: '0',
		});
	} else if (type === 'iframe') {
		el = create('iframe', {
			class: 'hd-gallery-video__embed',
			src: url,
			allow: 'autoplay; fullscreen',
			allowfullscreen: '',
			frameborder: '0',
		});
	} else {
		// Native <video> for mp4/webm
		el = create('video', {
			class: 'hd-gallery-video__embed',
			src: url,
			controls: '',
			autoplay: '',
			playsinline: '',
		});
	}

	addClass(container, 'is-video-active');

	// Overlay embed on top of poster (invisible until loaded)
	css(el, { position: 'absolute', inset: '0', zIndex: '10', opacity: '0' });
	container.appendChild(el);

	const onReady = () => {
		el.style.transition = 'opacity 0.3s ease';
		el.style.opacity = '1';

		// After fade-in, remove poster + play button, reset styles
		setTimeout(() => {
			const poster = $('.hd-gallery-video__poster', container);
			const playBtn = $('.hd-gallery-video__play', container);
			if (poster) poster.style.display = 'none';
			if (playBtn) playBtn.style.display = 'none';

			el.style.position = '';
			el.style.inset = '';
			el.style.zIndex = '';
			el.style.transition = '';
			el.style.opacity = '';
		}, 320);
	};

	if (el.tagName === 'IFRAME') {
		on(el, 'load', onReady, { once: true });
	} else {
		on(el, 'loadeddata', onReady, { once: true });
	}
}

/**
 * Pause inline videos in non-active Swiper slides.
 * For iframes (YouTube), uses postMessage; for <video>, calls .pause().
 * @param {Swiper} swiper
 */
function pauseInactiveVideos(swiper) {
	swiper.slides.forEach((slide, i) => {
		if (i === swiper.activeIndex) return;

		// Pause native <video>
		const video = $('video', slide);
		if (video && !video.paused) {
			video.pause();
		}

		// Stop YouTube iframe — swap src to halt playback
		const iframe = $('iframe', slide);
		if (iframe && iframe.src && iframe.src.includes('youtube.com/embed')) {
			if (iframe.src !== 'about:blank') iframe.dataset.pausedSrc = iframe.src;
			iframe.src = 'about:blank';
		}
	});

	// Restore paused iframe in the now-active slide (without autoplay)
	const activeSlide = swiper.slides.at(swiper.activeIndex);
	if (activeSlide) {
		const iframe = $('iframe', activeSlide);
		if (iframe?.dataset.pausedSrc) {
			iframe.src = iframe.dataset.pausedSrc.replace('autoplay=1', 'autoplay=0');
			delete iframe.dataset.pausedSrc;
		}
	}
}

// ── N4: Tracking Helpers ────────────────────────────────────────

/**
 * Parse tracking config from data-tracking attribute.
 * @param {HTMLElement} gallery
 * @returns {Object|null} Tracking config or null
 */
function parseTrackingConfig(gallery) {
	try {
		return JSON.parse(gallery.dataset.tracking || 'null');
	} catch {
		return null;
	}
}

/**
 * Dispatch tracking event via CustomEvent for gallery-tracking.js dispatcher.
 * @param {string} action — Action name (e.g. 'lightbox_open', 'slide_change')
 * @param {Object|null} tracking — Parsed data-tracking config
 * @param {string} productId — Product ID
 * @param {number} imageIndex — Current image index
 */
function dispatchTrackingEvent(action, tracking, productId, imageIndex) {
	if (!tracking) return;

	document.dispatchEvent(
		new CustomEvent('hd:gallery:interact', {
			detail: { tracking, action, productId, imageIndex },
		}),
	);
}

/**
 * Handle inline product video click — delegate from root.
 * Extracted to named function for proper cleanup in destroyAll.
 * @param {Event} e
 */
function handleVideoClick(e) {
	const container = e.target.closest('[data-wc-gallery] [data-fx-video]');
	if (container) {
		e.preventDefault();
		activateInlineVideo(container);
	}
}

const galleryApi = {
	initAll(root = document) {
		tracking.initAll();
		initGallery(root);
		initMiniGallery(root);

		on(root, 'click', handleVideoClick);
	},

	destroyAll(root = document) {
		tracking.destroyAll();
		destroyGallery(root);
		off(root, 'click', handleVideoClick);
	},
};

if (typeof window !== 'undefined') {
	window.hdWcGallery = galleryApi;
	// Auto boot if document is ready and elements exist
	if (document.readyState !== 'loading') {
		galleryApi.initAll(document);
	} else {
		document.addEventListener('DOMContentLoaded', () => {
			galleryApi.initAll(document);
		});
	}
}

export default galleryApi;
