/**
 * HD WC Gallery — Standalone Lightweight Loader.
 *
 * Checks for gallery presence in DOM and injects full gallery bundle on demand.
 *
 * @package HDWCGallery
 */

declare global {
	interface Window {
		hdWcGalleryConfig?: {
			jsUrl?: string;
			cssUrl?: string;
		};
	}
}

export {};

(function () {
	const selector = '[data-wc-gallery], .woocommerce-product-gallery';

	function loadGallery() {
		const target = document.querySelector(selector);
		if (!target) return;

		const config = window.hdWcGalleryConfig;
		if (!config?.jsUrl) return;

		// Load CSS if needed
		if (config.cssUrl && !document.querySelector(`link[href="${config.cssUrl}"]`)) {
			const link = document.createElement('link');
			link.rel = 'stylesheet';
			link.href = config.cssUrl;
			document.head.appendChild(link);
		}

		// Load JS bundle
		if (!document.querySelector(`script[src="${config.jsUrl}"]`)) {
			const script = document.createElement('script');
			script.src = config.jsUrl;
			script.type = 'module';
			script.async = true;
			document.body.appendChild(script);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', loadGallery);
	} else {
		loadGallery();
	}

	// Dynamic triggers
	document.addEventListener('hd:scan', loadGallery);
	document.addEventListener('hde:scan', loadGallery);
	document.addEventListener('core:scan', loadGallery);
})();
