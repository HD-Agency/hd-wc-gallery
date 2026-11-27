<?php
/**
 * HD WC Gallery Uninstaller.
 *
 * Removes updater credentials, cached release state, transients, and scheduled PUC hooks.
 * Preserves gallery configuration and WooCommerce product/variation gallery metadata.
 *
 * @package HDWCGallery
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( '_hd_wc_gallery_github_token' );
delete_option( 'external_updates-hd-wc-gallery' );
delete_site_option( 'external_updates-hd-wc-gallery' );

delete_transient( '_hdwcg_github_update_cooldown' );
delete_transient( 'hdwcg_updater_force_throttle' );

wp_clear_scheduled_hook( 'puc_cron_check_updates-hd-wc-gallery' );
