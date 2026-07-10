// resources/gallery-loader.js
// Tiny dynamic loader script for HD WC Gallery plugin

(function () {
	let loaded = false;

	function checkAndLoad() {
		if (loaded) return true;
		if (document.querySelector('[data-wc-gallery], [data-wc-gallery-mini]')) {
			loaded = true;
			loadAssets();
			return true;
		}
		return false;
	}

	function loadAssets() {
		if (typeof hdWcGalleryConfig === 'undefined') {
			console.error('[HD WC Gallery] Configuration is missing.');
			return;
		}

		// 1. Inject CSS stylesheet
		const link = document.createElement('link');
		link.rel = 'stylesheet';
		link.href = hdWcGalleryConfig.cssUrl;
		document.head.appendChild(link);

		// 2. Inject JS script
		const script = document.createElement('script');
		script.src = hdWcGalleryConfig.jsUrl;
		script.type = 'module';
		script.onload = () => {
			if (window.hdWcGallery && typeof window.hdWcGallery.initAll === 'function') {
				window.hdWcGallery.initAll(document);
			}
		};
		document.head.appendChild(script);
	}

	// Run initial scan
	if (!checkAndLoad()) {
		// Listen for dynamically added gallery elements (e.g. Quick View popups)
		const observer = new MutationObserver((mutations, obs) => {
			if (checkAndLoad()) {
				obs.disconnect();
			}
		});
		observer.observe(document.body, { childList: true, subtree: true });
	}
})();
