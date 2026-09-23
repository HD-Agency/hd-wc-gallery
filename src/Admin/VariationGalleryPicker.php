<?php
/**
 * Variation Gallery Picker — per-variation gallery image picker.
 *
 * Adds a sortable image picker (via wp.media) to each product variation,
 * storing selected attachment IDs in `_hd_variation_gallery` post meta.
 *
 * @package HDWCGallery\Admin
 */

declare(strict_types=1);

namespace HDWCGallery\Admin;

use HDWCGallery\Core\Helper;
use HDWCGallery\Frontend\GalleryDataProvider;

defined( 'ABSPATH' ) || exit;

final class VariationGalleryPicker {

	private const META_KEY = GalleryDataProvider::VARIATION_META_KEY;

	private static bool $templateRendered = false;

	/**
	 * Register admin hooks for variation gallery.
	 */
	public function register(): void {
		add_action( 'woocommerce_variation_options_pricing', [ $this, 'renderVariationField' ], 10, 3 );
		add_action( 'woocommerce_save_product_variation', [ self::class, 'saveVariationGallery' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueueAdminScripts' ] );
	}

	/**
	 * Render the variation gallery field in the WC variation form.
	 *
	 * @param int      $loop           Variation loop index.
	 * @param array    $variationData  Variation data array.
	 * @param \WP_Post $variation      Variation post object.
	 */
	public function renderVariationField( int $loop, array $variationData, \WP_Post $variation ): void {
		$galleryIds = get_post_meta( $variation->ID, self::META_KEY, true );
		$galleryIds = ! empty( $galleryIds ) ? array_map( 'absint', (array) $galleryIds ) : [];

		?>
		<div class="form-row form-row-full hd-variation-gallery" data-loop="<?php echo esc_attr( (string) $loop ); ?>">
			<label><?php esc_html_e( 'Variation Gallery', 'hd-wc-gallery' ); ?></label>
			<div class="hd-variation-gallery__images">
				<?php

				foreach ( $galleryIds as $attachmentId ) :
					$thumb = Helper::attachmentImageSrc( $attachmentId, 'thumbnail' );
					if ( ! $thumb ) {
						continue;
					}
					?>
					<div class="hd-variation-gallery__item" data-id="<?php echo esc_attr( (string) $attachmentId ); ?>">
						<img src="<?php echo esc_url( $thumb ); ?>" width="60" height="60" alt="" />
						<button type="button" class="hd-variation-gallery__remove" aria-label="<?php esc_attr_e( 'Remove', 'hd-wc-gallery' ); ?>">&times;</button>
					</div>
				<?php endforeach; ?>
			</div>
			<input type="hidden"
				class="hd-variation-gallery__input"
				name="hd_variation_gallery[<?php echo esc_attr( (string) $loop ); ?>]"
				value="<?php echo esc_attr( implode( ',', $galleryIds ) ); ?>" />
			<button type="button" class="button hd-variation-gallery__add">
				<?php esc_html_e( 'Add Gallery Images', 'hd-wc-gallery' ); ?>
			</button>
		</div>
		<?php

		self::ensureTemplate();
	}

	/**
	 * Save variation gallery attachment IDs.
	 *
	 * @param int $variationId  The variation post ID.
	 * @param int $loop         The variation loop index.
	 */
	public static function saveVariationGallery( int $variationId, int $loop ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WC verifies nonce before firing woocommerce_save_product_variation
		$galleryRaw = isset( $_POST['hd_variation_gallery'][ $loop ] )
			? sanitize_text_field( wp_unslash( $_POST['hd_variation_gallery'][ $loop ] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $galleryRaw ) {
			delete_post_meta( $variationId, self::META_KEY );

			return;
		}

		$galleryIds = array_filter( array_map( 'absint', explode( ',', $galleryRaw ) ) );
		update_post_meta( $variationId, self::META_KEY, $galleryIds );
	}

	/**
	 * Enqueue media picker scripts on product edit screen.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueueAdminScripts( string $hook ): void {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_media();

		// Inline admin script for wp.media integration
		wp_add_inline_script( 'jquery', self::getAdminJs(), 'after' );
	}

	// ── Private Helpers ─────────────────────────────

	/**
	 * Render wp.template once via admin_footer — avoids duplicate IDs.
	 */
	private static function ensureTemplate(): void {
		if ( self::$templateRendered ) {
			return;
		}

		self::$templateRendered = true;

		add_action( 'admin_footer', [ self::class, 'renderGalleryTemplate' ] );
	}

	/**
	 * Render wp.template for variation gallery items.
	 * Hooked to `admin_footer`.
	 */
	public static function renderGalleryTemplate(): void {
		?>
		<script type="text/html" id="tmpl-hd-variation-gallery-item">
			<div class="hd-variation-gallery__item" data-id="{{ data.id }}">
				<img src="{{ data.url }}" width="60" height="60" alt="" />
				<button type="button" class="hd-variation-gallery__remove" aria-label="<?php esc_attr_e( 'Remove', 'hd-wc-gallery' ); ?>">&times;</button>
			</div>
		</script>
		<?php
	}

	/**
	 * Get inline JS for the variation gallery media picker.
	 */
	private static function getAdminJs(): string {
		return <<<'JS'
jQuery(function($){
	var tmpl=wp.template('hd-variation-gallery-item');
	$(document.body).on('click','.hd-variation-gallery__add',function(e){
		e.preventDefault();
		var $row=$(this).closest('.hd-variation-gallery'),
			$input=$row.find('.hd-variation-gallery__input'),
			$images=$row.find('.hd-variation-gallery__images'),
			frame=wp.media({title:'Select Gallery Images',button:{text:'Add to Gallery'},multiple:true,library:{type:'image'}});
		frame.on('select',function(){
			var selection=frame.state().get('selection'),
				ids=$input.val()?$input.val().split(',').map(Number):[];
			selection.each(function(att){
				var id=att.get('id'),url=att.get('sizes')?.thumbnail?.url||att.get('url');
				if(ids.indexOf(id)===-1){
					ids.push(id);
					$images.append(tmpl({id:id,url:url}));
				}
			});
			$input.val(ids.join(',')).trigger('change');
		});
		frame.open();
	});
	$(document.body).on('click','.hd-variation-gallery__remove',function(e){
		e.preventDefault();
		var $item=$(this).closest('.hd-variation-gallery__item'),
			$row=$item.closest('.hd-variation-gallery'),
			$input=$row.find('.hd-variation-gallery__input'),
			removeId=$item.data('id'),
			ids=($input.val()||'').split(',').map(Number).filter(function(id){return id!==removeId;});
		$item.remove();
		$input.val(ids.join(',')).trigger('change');
	});
	// Sortable via native WC drag
	$(document.body).on('woocommerce_variations_loaded',function(){
		$('.hd-variation-gallery__images').sortable({
			items:'.hd-variation-gallery__item',
			cursor:'move',
			tolerance:'pointer',
			update:function(){
				var $row=$(this).closest('.hd-variation-gallery'),
					$input=$row.find('.hd-variation-gallery__input'),
					ids=[];
				$(this).find('.hd-variation-gallery__item').each(function(){ids.push($(this).data('id'));});
				$input.val(ids.join(',')).trigger('change');
			}
		});
	});
});
JS;
	}
}
