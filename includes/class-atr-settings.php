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
			'proxy_quality' => (int) min( 100, max( 1, (int) ( $input['proxy_quality'] ?? $defaults['proxy_quality'] ) ) ),
		);
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

	public static function render_proxy_quality(): void {
		$settings = self::all();
		?>
		<input type="number" name="<?php echo esc_attr( self::OPTION ); ?>[proxy_quality]" id="atr-proxy-quality" class="small-text" min="1" max="100" step="1" value="<?php echo esc_attr( (string) $settings['proxy_quality'] ); ?>" />
		<p class="description"><?php esc_html_e( '1 to 100. Around 80 is the usual compromise between file size and artefacts.', 'ajax-thumbnail-rebuild' ); ?></p>
		<?php
	}
}
