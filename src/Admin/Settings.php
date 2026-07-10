<?php
/**
 * Plugin Settings Manager.
 *
 * @package HDWCGallery\Admin
 */

declare(strict_types=1);

namespace HDWCGallery\Admin;

defined( 'ABSPATH' ) || exit;

final class Settings {
	public const OPTION_KEY = 'hd_wc_gallery_settings';

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'addSettingsPage' ] );
		add_action( 'admin_init', [ self::class, 'registerSettings' ] );
	}

	/**
	 * Add settings submenu under WooCommerce.
	 */
	public static function addSettingsPage(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Product Gallery Settings', 'hd-wc-gallery' ),
			__( 'Product Gallery', 'hd-wc-gallery' ),
			'manage_options',
			'hd-wc-gallery-settings',
			[ self::class, 'renderSettingsPage' ]
		);
	}

	/**
	 * Render settings page content.
	 */
	public static function renderSettingsPage(): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Product Gallery Settings', 'hd-wc-gallery' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_KEY );
				do_settings_sections( 'hd-wc-gallery-settings' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Register settings, sections, and fields.
	 */
	public static function registerSettings(): void {
		register_setting(
			self::OPTION_KEY,
			self::OPTION_KEY,
			[
				'type'              => 'array',
				'sanitize_callback' => [ self::class, 'sanitizeSettings' ],
				'default'           => self::getDefaults(),
			]
		);

		add_settings_section(
			'hd_wc_gallery_general',
			__( 'General Settings', 'hd-wc-gallery' ),
			'__return_empty_string',
			'hd-wc-gallery-settings'
		);

		$fields = self::getFieldsConfig();
		foreach ( $fields as $key => $field ) {
			add_settings_field(
				$key,
				$field['label'],
				[ self::class, 'renderField' ],
				'hd-wc-gallery-settings',
				'hd_wc_gallery_general',
				[
					'key'   => $key,
					'field' => $field,
				]
			);
		}
	}

	/**
	 * Sanitize fields before saving.
	 */
	public static function sanitizeSettings( array $input ): array {
		$output = [];
		$fields = self::getFieldsConfig();

		foreach ( $fields as $key => $field ) {
			if ( ! isset( $input[ $key ] ) ) {
				if ( 'toggle' === $field['type'] ) {
					$output[ $key ] = false;
				} else {
					$output[ $key ] = $field['default'] ?? '';
				}
				continue;
			}

			$val = $input[ $key ];
			if ( 'toggle' === $field['type'] ) {
				$output[ $key ] = (bool) $val;
			} elseif ( 'number' === $field['type'] ) {
				$output[ $key ] = is_numeric( $val ) ? (float) $val : ( $field['default'] ?? 0 );
			} else {
				$output[ $key ] = sanitize_text_field( (string) $val );
			}
		}

		return $output;
	}

	/**
	 * Render settings input fields.
	 */
	public static function renderField( array $args ): void {
		$key      = $args['key'];
		$field    = $args['field'];
		$options  = self::getOptions();
		$current  = $options[ $key ] ?? ( $field['default'] ?? '' );
		$nameAttr = self::OPTION_KEY . '[' . esc_attr( $key ) . ']';

		if ( 'toggle' === $field['type'] ) {
			printf(
				'<input type="checkbox" name="%s" value="1" %s />',
				esc_attr( $nameAttr ),
				checked( $current, true, false )
			);
		} elseif ( 'select' === $field['type'] ) {
			printf( '<select name="%s">', esc_attr( $nameAttr ) );
			foreach ( $field['options'] as $val => $label ) {
				printf(
					'<option value="%s" %s>%s</option>',
					esc_attr( $val ),
					selected( $current, $val, false ),
					esc_html( $label )
				);
			}
			echo '</select>';
		} elseif ( 'number' === $field['type'] ) {
			printf(
				'<input type="number" name="%s" value="%s" min="%s" max="%s" step="%s" class="small-text" />',
				esc_attr( $nameAttr ),
				esc_attr( (string) $current ),
				esc_attr( (string) ( $field['min'] ?? '' ) ),
				esc_attr( (string) ( $field['max'] ?? '' ) ),
				esc_attr( (string) ( $field['step'] ?? '' ) )
			);
		}

		if ( ! empty( $field['help'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $field['help'] ) );
		}
	}

	/**
	 * Retrieve all plugin options (cached or get_option).
	 */
	public static function getOptions(): array {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}

		$options = get_option( self::OPTION_KEY, [] );
		$cached  = array_merge( self::getDefaults(), is_array( $options ) ? $options : [] );

		return $cached;
	}

	/**
	 * Retrieve single option.
	 */
	public static function getOption( string $key, mixed $defaultValue = null ): mixed {
		$options = self::getOptions();
		return $options[ $key ] ?? $defaultValue;
	}

	/**
	 * Settings Fields definition.
	 */
	public static function getFieldsConfig(): array {
		return [
			'gallery_layout'            => [
				'type'    => 'select',
				'label'   => __( 'Gallery Layout', 'hd-wc-gallery' ),
				'options' => [
					'below'   => __( 'Slider — Thumbs Below', 'hd-wc-gallery' ),
					'above'   => __( 'Slider — Thumbs Above', 'hd-wc-gallery' ),
					'left'    => __( 'Slider — Thumbs Left', 'hd-wc-gallery' ),
					'right'   => __( 'Slider — Thumbs Right', 'hd-wc-gallery' ),
					'stacked' => __( 'Stacked (Grid, no slider)', 'hd-wc-gallery' ),
				],
				'default' => 'below',
			],
			'gallery_zoom'              => [
				'type'    => 'toggle',
				'label'   => __( 'Enable Zoom', 'hd-wc-gallery' ),
				'default' => true,
			],
			'gallery_zoom_scale'        => [
				'type'    => 'number',
				'label'   => __( 'Zoom Scale', 'hd-wc-gallery' ),
				'default' => 2,
				'min'     => 1.5,
				'max'     => 5,
				'step'    => 0.5,
				'help'    => __( 'Zoom magnification level', 'hd-wc-gallery' ),
			],
			'gallery_lens_size'         => [
				'type'    => 'number',
				'label'   => __( 'Lens Size', 'hd-wc-gallery' ),
				'default' => 150,
				'min'     => 80,
				'max'     => 400,
				'step'    => 10,
				'help'    => __( 'Lens diameter in px (circle mode only)', 'hd-wc-gallery' ),
			],
			'gallery_lens_mode'         => [
				'type'    => 'select',
				'label'   => __( 'Lens Mode', 'hd-wc-gallery' ),
				'options' => [
					'circle' => __( 'Circle (magnifying glass)', 'hd-wc-gallery' ),
					'full'   => __( 'Full (lens fills entire image)', 'hd-wc-gallery' ),
				],
				'default' => 'circle',
			],
			'gallery_variation_mode'    => [
				'type'    => 'select',
				'label'   => __( 'Variation Mode', 'hd-wc-gallery' ),
				'options' => [
					'replace' => __( 'Replace — show only variation images', 'hd-wc-gallery' ),
					'prepend' => __( 'Prepend — variation images first, then product gallery', 'hd-wc-gallery' ),
				],
				'default' => 'replace',
			],
			'gallery_product_video_pos' => [
				'type'    => 'select',
				'label'   => __( 'Product Video Position', 'hd-wc-gallery' ),
				'options' => [
					'first_slide' => __( 'First Slide', 'hd-wc-gallery' ),
					'last_slide'  => __( 'Last Slide', 'hd-wc-gallery' ),
					'overlay'     => __( 'Floating Overlay Button', 'hd-wc-gallery' ),
				],
				'default' => 'first_slide',
			],
			'gallery_object_fit'        => [
				'type'    => 'select',
				'label'   => __( 'Object Fit', 'hd-wc-gallery' ),
				'options' => [
					'contain' => __( 'Contain (keep full image, may show background)', 'hd-wc-gallery' ),
					'cover'   => __( 'Cover (fill frame, may crop edges)', 'hd-wc-gallery' ),
				],
				'default' => 'contain',
			],
			'gallery_thumbs_mobile'     => [
				'type'    => 'number',
				'label'   => __( 'Thumbnail Count - Mobile', 'hd-wc-gallery' ),
				'default' => 3,
				'min'     => 0,
				'max'     => 6,
				'help'    => __( '0 = auto (CSS-based sizing)', 'hd-wc-gallery' ),
			],
			'gallery_thumbs_tablet'     => [
				'type'    => 'number',
				'label'   => __( 'Thumbnail Count - Tablet', 'hd-wc-gallery' ),
				'default' => 4,
				'min'     => 0,
				'max'     => 8,
				'help'    => __( '0 = auto (CSS-based sizing)', 'hd-wc-gallery' ),
			],
			'gallery_thumbs_desktop'    => [
				'type'    => 'number',
				'label'   => __( 'Thumbnail Count - Desktop', 'hd-wc-gallery' ),
				'default' => 5,
				'min'     => 0,
				'max'     => 10,
				'help'    => __( '0 = auto (CSS-based sizing)', 'hd-wc-gallery' ),
			],
			'gallery_nav_arrows'        => [
				'type'    => 'toggle',
				'label'   => __( 'Show Navigation Arrows', 'hd-wc-gallery' ),
				'default' => true,
				'help'    => __( 'Show prev/next navigation arrows on slider', 'hd-wc-gallery' ),
			],
		];
	}

	/**
	 * Get default settings values.
	 */
	public static function getDefaults(): array {
		$defaults = [];
		foreach ( self::getFieldsConfig() as $key => $field ) {
			$defaults[ $key ] = $field['default'] ?? '';
		}
		return $defaults;
	}
}
