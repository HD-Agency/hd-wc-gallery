=== HD WC Gallery ===
Contributors: hd-agency
Tags: woocommerce, gallery, swiper, photoswipe, product-gallery
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.2
Stable tag: 1.1.1
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

= 1.1.1 =
* Updater Hotfix: `shouldCheckNow()` no longer blocks explicit `force-check` and active upgrade triggers inside PUC's cooldown window, requires the `update_plugins` capability plus `wp_verify_nonce( '_wpnonce', 'puc_check_for_updates' )` for manual checks, and honors `COOLDOWN_TRANSIENT` on WP-Cron.

= 1.1.0 =
* GitHub Updater Standardization: Split the 1,684-LOC monolithic `GitHubUpdater` into `GitHubUpdater`, `GitHubTokenResolver`, and `GitHubArchiveVerifier` on PUC 6.0, adding strict HTTPS + repo allowlist, redirect/auth-header stripping, and inline ZIP integrity and entrypoint guards.
* Passive Performance: Removed the plugins-page polling asset, inline row HTML builder, and the dead `/updater/check` route; live GitHub checks now run only on WP-Cron, explicit force checks, or an actual plugin upgrade.
* Uninstall: Added `uninstall.php` to purge `_hd_wc_gallery_github_token` and `external_updates-hd-wc-gallery` options and unschedule `puc_cron_check_updates-hd-wc-gallery`.

= 1.0.7 =
* Live GitHub Updater: Enhanced ZIP magic bytes verification with automatic GZIP decompression and leading byte stripping.

= 1.0.6 =
* Maintenance: Version alignment across configuration files, stability improvements.

= 1.0.5 =
* Live GitHub Updater: Standardized Requests 2.x before_redirect hook with exact 2 parameters (location, headers).
* Live GitHub Updater: Integrated validateDownloadArchive filter checking zip PK magic bytes with WP_Filesystem.
* Live GitHub Updater: Replaced direct PHP calls with WordPress Core alternatives (wp_parse_url, wp_delete_file, wp_is_writable).
* Live GitHub Updater: Eliminated error silencing (@) and enforced 300s TTL temporary archive cleanup.

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
