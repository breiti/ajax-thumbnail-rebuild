<?php
/**
 * Plugin settings: one option, read through here.
 *
 * @package ajax-thumbnail-rebuild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATR_Settings {

	const OPTION = 'ajax_thumbnail_rebuild_settings';

	/** Settings API group and section. */
	const GROUP = 'ajax_thumbnail_rebuild';

	/**
	 * Everything the plugin can be configured with, and what it does without configuring.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'proxy_enabled' => false,
			'proxy_output'  => 'webp',
			'proxy_quality' => 82,
			'webp_enabled'  => false,
			'webp_quality'  => 82,

			'on_demand_enabled' => false,
			'on_demand_sizes'   => array(),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );

		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key     Setting name.
	 * @param mixed  $default Returned when the setting is unknown.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$settings = self::all();

		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * The output formats the proxy can be asked for.
	 *
	 * @return array<string,string> Value => label.
	 */
	public static function output_formats(): array {
		/* The proxy serves the file's own format unless asked otherwise - it does not
		   negotiate WebP from the Accept header - so WebP is a choice to make here. */
		return array(
			'webp'     => 'WebP',
			'jpg'      => 'JPEG',
			'png'      => 'PNG',
			'original' => __( 'Same format as the file', 'ajax-thumbnail-rebuild' ),
		);
	}

	public static function register(): void {
		register_setting( self::GROUP, self::OPTION, array(
			'type'              => 'object',
			'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			'default'           => self::defaults(),
			'show_in_rest'      => false,
		) );

		add_settings_section(
			'atr_proxy',
			__( 'Image proxy', 'ajax-thumbnail-rebuild' ),
			array( __CLASS__, 'render_proxy_intro' ),
			self::GROUP
		);

		add_settings_field(
			'proxy_enabled',
			__( 'Serve images through wsrv.nl', 'ajax-thumbnail-rebuild' ),
			array( __CLASS__, 'render_proxy_enabled' ),
			self::GROUP,
			'atr_proxy'
		);

		add_settings_field(
			'proxy_output',
			__( 'Format', 'ajax-thumbnail-rebuild' ),
			array( __CLASS__, 'render_proxy_output' ),
			self::GROUP,
			'atr_proxy',
			array( 'label_for' => 'atr-proxy-output' )
		);

		add_settings_field(
			'proxy_quality',
			__( 'Quality', 'ajax-thumbnail-rebuild' ),
			array( __CLASS__, 'render_proxy_quality' ),
			self::GROUP,
			'atr_proxy',
			array( 'label_for' => 'atr-proxy-quality' )
		);

		add_settings_section(
			'atr_on_demand',
			__( 'Sizes on demand', 'ajax-thumbnail-rebuild' ),
			array( __CLASS__, 'render_on_demand_intro' ),
			self::GROUP
		);

		add_settings_field(
			'on_demand_enabled',
			__( 'Generate on demand', 'ajax-thumbnail-rebuild' ),
			array( __CLASS__, 'render_on_demand_enabled' ),
			self::GROUP,
			'atr_on_demand'
		);

		add_settings_field(
			'on_demand_sizes',
			__( 'Sizes made only on demand', 'ajax-thumbnail-rebuild' ),
			array( __CLASS__, 'render_on_demand_sizes' ),
			self::GROUP,
			'atr_on_demand'
		);

		add_settings_section(
			'atr_webp',
			__( 'WebP copies', 'ajax-thumbnail-rebuild' ),
			array( __CLASS__, 'render_webp_intro' ),
			self::GROUP
		);

		add_settings_field(
			'webp_enabled',
			__( 'Make WebP copies', 'ajax-thumbnail-rebuild' ),
			array( __CLASS__, 'render_webp_enabled' ),
			self::GROUP,
			'atr_webp'
		);

		add_settings_field(
			'webp_quality',
			__( 'Quality', 'ajax-thumbnail-rebuild' ),
			array( __CLASS__, 'render_webp_quality' ),
			self::GROUP,
			'atr_webp',
			array( 'label_for' => 'atr-webp-quality' )
		);
	}

	/**
	 * @param mixed $input Raw input from the form.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();

		$output = sanitize_key( $input['proxy_output'] ?? $defaults['proxy_output'] );

		if ( ! array_key_exists( $output, self::output_formats() ) ) {
			$output = $defaults['proxy_output'];
		}

		return array(
			'proxy_enabled' => ! empty( $input['proxy_enabled'] ),
			'proxy_output'  => $output,
			'proxy_quality' => self::quality( $input['proxy_quality'] ?? null, $defaults['proxy_quality'] ),
			'webp_enabled'  => ! empty( $input['webp_enabled'] ),
			'webp_quality'  => self::quality( $input['webp_quality'] ?? null, $defaults['webp_quality'] ),

			'on_demand_enabled' => ! empty( $input['on_demand_enabled'] ),
			'on_demand_sizes'   => array_values( array_unique( array_filter(
				array_map( 'sanitize_key', (array) ( $input['on_demand_sizes'] ?? array() ) )
			) ) ),
		);
	}

	/**
	 * A quality value in the range the image libraries accept.
	 *
	 * @param mixed $value    Submitted value.
	 * @param int   $fallback Used when nothing was submitted.
	 */
	private static function quality( $value, int $fallback ): int {
		if ( null === $value || '' === $value ) {
			return $fallback;
		}

		return (int) min( 100, max( 1, (int) $value ) );
	}

	public static function render_proxy_intro(): void {
		?>
		<p class="description">
			<?php
			printf(
				/* translators: 1: opening link tag, 2: closing link tag. */
				esc_html__( 'Image URLs on the front end are rewritten to %1$swsrv.nl%2$s, a free image proxy that resizes and re-encodes on the fly and serves the result from a CDN. Your own files are untouched, and turning this off puts every URL back.', 'ajax-thumbnail-rebuild' ),
				'<a href="https://wsrv.nl/" target="_blank" rel="noopener">',
				'</a>'
			);
			?>
		</p>
		<p class="description">
			<?php esc_html_e( 'The proxy fetches each image from your site, so it only works on a site that is reachable from the internet. On a local or password protected site the images will not load.', 'ajax-thumbnail-rebuild' ); ?>
		</p>
		<?php
	}

	public static function render_proxy_enabled(): void {
		$settings = self::all();
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[proxy_enabled]" value="1" <?php checked( $settings['proxy_enabled'] ); ?> />
			<?php esc_html_e( 'Rewrite front end image URLs to the proxy', 'ajax-thumbnail-rebuild' ); ?>
		</label>
		<?php
	}

	public static function render_proxy_output(): void {
		$settings = self::all();
		?>
		<select name="<?php echo esc_attr( self::OPTION ); ?>[proxy_output]" id="atr-proxy-output">
			<?php foreach ( self::output_formats() as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['proxy_output'], $value ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php esc_html_e( 'WebP files are a good deal smaller than JPEG or PNG and every current browser reads them.', 'ajax-thumbnail-rebuild' ); ?></p>
		<?php
	}

	public static function render_on_demand_intro(): void {
		?>
		<p class="description">
			<?php esc_html_e( 'WordPress normally cuts every registered size the moment a file is uploaded, most of which no page ever asks for. With this on, an upload keeps only its own file and each size is cut the first time something requests it, then stays on disk like any other.', 'ajax-thumbnail-rebuild' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( 'Uploads get quicker and the uploads folder stays smaller. The first visitor to need a size waits for it to be cut, and until then an image has no other size to offer in its srcset.', 'ajax-thumbnail-rebuild' ); ?>
		</p>
		<?php
	}

	public static function render_on_demand_enabled(): void {
		$settings = self::all();
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[on_demand_enabled]" value="1" <?php checked( $settings['on_demand_enabled'] ); ?> />
			<?php esc_html_e( 'Cut each size when it is first requested, not on upload', 'ajax-thumbnail-rebuild' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Rebuilding from the Rebuild tab still generates whatever you select there.', 'ajax-thumbnail-rebuild' ); ?></p>
		<?php
	}

	public static function render_on_demand_sizes(): void {
		$settings = self::all();
		$marked   = (array) $settings['on_demand_sizes'];
		$sizes    = ATR_Image_Sizes::all();
		?>
		<fieldset>
			<legend class="screen-reader-text"><?php esc_html_e( 'Sizes made only on demand', 'ajax-thumbnail-rebuild' ); ?></legend>
			<?php if ( ! $sizes ) : ?>
				<p class="description"><?php esc_html_e( 'This site has no image sizes registered.', 'ajax-thumbnail-rebuild' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $sizes as $size ) : ?>
				<label style="display:block; margin-bottom:4px;">
					<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[on_demand_sizes][]" value="<?php echo esc_attr( $size['name'] ); ?>" <?php checked( in_array( $size['name'], $marked, true ) ); ?> />
					<code><?php echo esc_html( $size['name'] ); ?></code>
					<span class="description"><?php echo esc_html( ATR_Image_Sizes::dimensions_label( $size ) ); ?></span>
				</label>
			<?php endforeach; ?>
		</fieldset>
		<p class="description">
			<?php esc_html_e( 'A size ticked here is never written on upload and is skipped by a rebuild, whatever the Rebuild tab has selected. It is cut the first time a page asks for it. Useful for the big sizes only a handful of pages ever use.', 'ajax-thumbnail-rebuild' ); ?>
		</p>
		<?php
	}

	public static function render_webp_intro(): void {
		?>
		<p class="description">
			<?php esc_html_e( 'A WebP copy is written next to every image WordPress generates, and the front end serves it through a picture element with the original as the fallback. Your JPEG and PNG files stay where they are, and a copy that would be larger than the original is thrown away.', 'ajax-thumbnail-rebuild' ); ?>
		</p>
		<p class="description">
			<?php
			printf(
				/* translators: 1: opening link tag to the Rebuild tab, 2: closing link tag. */
				esc_html__( 'New uploads are converted as they arrive. For the images already in the library, %1$srun a rebuild%2$s once after turning this on.', 'ajax-thumbnail-rebuild' ),
				'<a href="' . esc_url( ATR_Admin_Page::url( 'rebuild' ) ) . '">',
				'</a>'
			);
			?>
		</p>
		<?php if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) : ?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'The image library on this server cannot write WebP, so no copies will be made. Ask your host for GD or Imagick with WebP support.', 'ajax-thumbnail-rebuild' ); ?></p>
			</div>
		<?php endif; ?>
		<?php if ( ATR_Image_Proxy::is_enabled() ) : ?>
			<div class="notice notice-info inline">
				<p><?php esc_html_e( 'This works on its own, but the proxy above is on and already delivers WebP from its CDN for the images it rewrites. The copies made here are what gets served whenever the proxy is off or does not handle an image.', 'ajax-thumbnail-rebuild' ); ?></p>
			</div>
		<?php endif; ?>
		<?php
	}

	public static function render_webp_enabled(): void {
		$settings = self::all();
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[webp_enabled]" value="1" <?php checked( $settings['webp_enabled'] ); ?> />
			<?php esc_html_e( 'Convert generated images to WebP and serve the copies', 'ajax-thumbnail-rebuild' ); ?>
		</label>
		<?php
	}

	public static function render_webp_quality(): void {
		$settings = self::all();
		?>
		<input type="number" name="<?php echo esc_attr( self::OPTION ); ?>[webp_quality]" id="atr-webp-quality" class="small-text" min="1" max="100" step="1" value="<?php echo esc_attr( (string) $settings['webp_quality'] ); ?>" />
		<p class="description"><?php esc_html_e( '1 to 100, used when writing the WebP copies.', 'ajax-thumbnail-rebuild' ); ?></p>
		<?php
	}

	public static function render_proxy_quality(): void {
		$settings = self::all();
		?>
		<input type="number" name="<?php echo esc_attr( self::OPTION ); ?>[proxy_quality]" id="atr-proxy-quality" class="small-text" min="1" max="100" step="1" value="<?php echo esc_attr( (string) $settings['proxy_quality'] ); ?>" />
		<p class="description"><?php esc_html_e( '1 to 100. Around 80 is the usual compromise between file size and artefacts.', 'ajax-thumbnail-rebuild' ); ?></p>
		<?php
	}
}
