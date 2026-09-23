=== HD WC Gallery ===
Contributors: hd-agency
Tags: woocommerce, gallery, swiper, photoswipe, product-gallery
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.0.3
License: MIT
License URI: https://opensource.org/licenses/MIT

Standalone, independent WooCommerce Product Gallery with Swiper slider, PhotoSwipe v5 lightbox, zoom, and video support.

== Description ==

HD WC Gallery is a modern, high-performance product gallery for WooCommerce that replaces default gallery templates with customizable Swiper slider layouts, PhotoSwipe v5 fullscreen lightbox, smooth cursor zoom, and product video support.

== Installation ==

1. Upload the `hd-wc-gallery` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Configure gallery options in **WooCommerce -> Product Gallery**.

== Changelog ==

= 1.0.3 =
* GitHub Auto-Updater: Filtered out PUC latest_release strategy to prevent HTTP 404 queries on branch-tracked repositories, saving ~760ms TTFB.
* Environment Token Resolution: Standardized 5-tier environment token lookup with GITHUB_TOKEN and HD_GITHUB_TOKEN fallbacks.
* Performance: Synchronized update check cooldown to 300s. Normalized path constants to forward slash.

= 1.0.2 =
* Updater: Hardened GitHub updater with package checksum/integrity verification and safe directory validation.
* Tests: Added comprehensive test coverage for updater admin actions, transient caches, and row notices.

= 1.0.1 =
* Auto-Update: Added GitHub-based background updater with Sodium-encrypted token vault and tabbed admin settings UI.
* Assets: Rebuilt optimized production JS and CSS bundles.

= 1.0.0 =
* Initial Release: Modern Swiper slider, PhotoSwipe v5 lightbox, zoom lens, product video integration, and variation gallery support.
