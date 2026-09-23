<?php
/**
 * Gallery Settings — Auto-Update Section.
 *
 * Renders the Auto-Update settings tab inside the standard channel block container.
 *
 * @package HDWCGallery\Admin\Settings
 */

declare(strict_types=1);

namespace HDWCGallery\Admin\Settings;

use HDWCGallery\Updater\GitHubUpdater;

defined( 'ABSPATH' ) || exit;

final class AutoUpdateSection {

	/**
	 * Render the Auto-Update settings tab.
	 */
	public static function renderTab(): void {
		$hasToken    = GitHubUpdater::hasToken();
		$source      = GitHubUpdater::tokenSource();
		$sourceLabel = 'constant' === $source ? __( 'Environment', 'hd-wc-gallery' ) : __( 'Encrypted Database', 'hd-wc-gallery' );

		?>
		<div class="hdwcg-tab-content" id="hdwcg-tab-update">
			<p><?php esc_html_e( 'Configure access credentials to enable automatic background plugin updates and seamless version upgrades.', 'hd-wc-gallery' ); ?></p>

			<div class="hdwcg-channel-block">
				<div class="hdwcg-vault-header">
					<div class="hdwcg-vault-header-left">
						<div class="hdwcg-vault-icon">
							<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>
						</div>
						<div>
							<h3 class="hdwcg-vault-title"><?php esc_html_e( 'Auto-Update Authentication', 'hd-wc-gallery' ); ?></h3>
							<p class="hdwcg-vault-subtitle"><?php esc_html_e( 'Enable automatic background plugin updates and seamless version upgrades.', 'hd-wc-gallery' ); ?></p>
						</div>
					</div>
					<div class="hdwcg-vault-header-right">
						<?php if ( $hasToken ) : ?>
							<div class="hdwcg-status-indicator active">
								<span class="hdwcg-status-dot"></span>
								<span class="hdwcg-status-text"><?php esc_html_e( 'Active', 'hd-wc-gallery' ); ?></span>
								<span class="hdwcg-status-source">(<?php echo esc_html( $sourceLabel ); ?>)</span>
							</div>
						<?php else : ?>
							<div class="hdwcg-status-indicator inactive">
								<span class="hdwcg-status-dot"></span>
								<span class="hdwcg-status-text"><?php esc_html_e( 'Not Configured', 'hd-wc-gallery' ); ?></span>
							</div>
						<?php endif; ?>
					</div>
				</div>

				<div class="hdwcg-vault-body">
					<?php if ( $hasToken ) : ?>
						<div class="hdwcg-token-active-view" id="hdwcg-token-active-view">
							<div class="hdwcg-token-key-display">
								<span class="hdwcg-key-label"><?php esc_html_e( 'ACCESS TOKEN', 'hd-wc-gallery' ); ?></span>
								<span class="hdwcg-key-hash">••••••••••••••••••••••••••••••••</span>
								<div class="hdwcg-key-specs">
									<span class="hdwcg-spec-item"><span class="dashicons dashicons-shield"></span> <?php esc_html_e( 'Sodium Encrypted', 'hd-wc-gallery' ); ?></span>
									<span class="hdwcg-spec-item"><span class="dashicons dashicons-admin-plugins"></span> <?php esc_html_e( 'Channel: Production Release', 'hd-wc-gallery' ); ?></span>
								</div>
							</div>
							<div class="hdwcg-token-active-actions">
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'plugins.php?puc_check_for_updates=1&puc_slug=hd-wc-gallery' ), 'puc_check_for_updates' ) ); ?>" class="btn-action-primary">
									<?php esc_html_e( 'Check for Updates', 'hd-wc-gallery' ); ?>
								</a>
								<button type="button" id="hdwcg-btn-edit-token" class="btn-action-neutral">
									<?php esc_html_e( 'Replace Token', 'hd-wc-gallery' ); ?>
								</button>
								<?php if ( 'db' === $source ) : ?>
									<button type="button" id="hdwcg-delete-token" class="btn-action-danger">
										<?php esc_html_e( 'Remove Token', 'hd-wc-gallery' ); ?>
									</button>
								<?php endif; ?>
							</div>
						</div>
					<?php endif; ?>

					<div class="hdwcg-token-edit-view" id="hdwcg-token-edit-view" style="<?php echo $hasToken ? 'display: none;' : ''; ?>">
						<?php if ( $hasToken ) : ?>
							<div class="hdwcg-edit-notice">
								<span class="dashicons dashicons-info"></span>
								<span><?php esc_html_e( 'Enter a new access token to replace the current active token.', 'hd-wc-gallery' ); ?></span>
							</div>
						<?php else : ?>
							<div class="hdwcg-setup-guide">
								<p><?php esc_html_e( 'Configure an access token to enable automatic background plugin updates and seamless version upgrades.', 'hd-wc-gallery' ); ?></p>
							</div>
						<?php endif; ?>

						<div class="hdwcg-input-action-card">
							<label for="hdwcg-github-token" class="hdwcg-input-label"><?php echo $hasToken ? esc_html__( 'New Access Token', 'hd-wc-gallery' ) : esc_html__( 'Access Token', 'hd-wc-gallery' ); ?></label>
							<div class="hdwcg-token-input-wrapper">
								<span class="hdwcg-input-icon dashicons dashicons-admin-network"></span>
								<input type="password" id="hdwcg-github-token" placeholder="••••••••••••••••••••••••••••••••" autocomplete="off" spellcheck="false">
							</div>

							<div class="hdwcg-input-actions-row">
								<button type="button" id="hdwcg-save-token" class="btn-action-submit">
									<?php echo $hasToken ? esc_html__( 'Update Token', 'hd-wc-gallery' ) : esc_html__( 'Save & Connect', 'hd-wc-gallery' ); ?>
								</button>
								<?php if ( $hasToken ) : ?>
									<button type="button" id="hdwcg-btn-cancel-edit" class="btn-action-cancel">
										<?php esc_html_e( 'Cancel', 'hd-wc-gallery' ); ?>
									</button>
								<?php endif; ?>
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'plugins.php?puc_check_for_updates=1&puc_slug=hd-wc-gallery' ), 'puc_check_for_updates' ) ); ?>" class="btn-action-primary" style="margin-left: auto;">
									<?php esc_html_e( 'Check for Updates', 'hd-wc-gallery' ); ?>
								</a>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
