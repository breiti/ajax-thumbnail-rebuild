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
			'upload_quality'       => ATR_Uploads::CORE_QUALITY,
			'max_upload_dimension' => ATR_Uploads::CORE_THRESHOLD,
			'optimize_enabled'     => false,
			'optimize_quality'     => ATR_Uploads::CORE_QUALITY,
			'optimize_lossy'       => false,
			'optimize_original'    => false,
			'optimize_service'     => 'none',
			'optimize_service_key' => '',

			'proxy_enabled' => false,
			'proxy_output'  => 'webp',
			'proxy_quality' => 82,
			'webp_enabled'  => false,
			'webp_quality'  => ATR_Webp::DEFAULT_QUALITY,
			'avif_enabled'  => false,
			'avif_quality'  => ATR_Avif::DEFAULT_QUALITY,

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
		   negotiate a modern format from the Accept header - so AVIF and WebP are a
		   choice to make here. */
		return array(
			'avif'     => 'AVIF',
			'webp'     => 'WebP',
			'jpg'      => 'JPEG',
			'png'      => 'PNG',
			'original' => __( 'Same format as the file', 'ajax-thumbnail-rebuild' ),
		);
	}

	/**
	 * The settings screens, in the order they are shown.
	 *
	 * Each one is a tab of its own: a single page of everything this plugin can
	 * be told to do is too long to read. `keys` is what that tab is responsible
	 * for, so saving one tab cannot wipe the settings of another.
	 *
	 * @return array<string,array{label:string,keys:array}>
	 */
	public static function pages(): array {
		return array(
			'uploads'   => array(
				'label' => __( 'Uploads', 'ajax-thumbnail-rebuild' ),
				'keys'  => array( 'upload_quality', 'max_upload_dimension' ),
			),
			'optimise'  => array(
				'label' => __( 'Optimising', 'ajax-thumbnail-rebuild' ),
				'keys'  => array( 'optimize_enabled', 'optimize_quality', 'optimize_lossy', 'optimize_original', 'optimize_service', 'optimize_service_key' ),
			),
			'serving'   => array(
				'label' => __( 'Serving', 'ajax-thumbnail-rebuild' ),
				'keys'  => array( 'webp_enabled', 'webp_quality', 'avif_enabled', 'avif_quality', 'proxy_enabled', 'proxy_output', 'proxy_quality' ),
			),
			'on-demand' => array(
				'label' => __( 'On demand', 'ajax-thumbnail-rebuild' ),
				'keys'  => array( 'on_demand_enabled', 'on_demand_sizes' ),
			),
		);
	}

	/**
	 * The Settings API page a tab's sections are registered under.
	 *
	 * @param string $page Tab key.
	 */
	public static function page_slug( string $page ): string {
		return 'atr_settings_' . $page;
	}

	public static function register(): void {
		register_setting( self::GROUP, self::OPTION, array(
			'type'              => 'object',
			'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			'default'           => self::defaults(),
			'show_in_rest'      => false,
		) );

		add_settings_section(
			'atr_uploads',
			'',
			array( __CLASS__, 'render_uploads_intro' ),
			self::page_slug( 'uploads' )
		);

		add_settings_field(
			'upload_quality',
			__( 'Compression', 'ajax-thumbnail-rebuild' ),
			array( __CLASS__, 'render_upload_quality' ),
			self::page_slug( 'uploads' ),
			'atr_uploads',
			array( 'label_for' => 'atr-upload-quality' )
		);

		add_settings_field(
			'max_upload_dimension',
			__( 'Largest an image may stay', 'ajax-thumbnail-rebuild' ),
			array( __CLASS__, 'render_max_upload_dimension' ),
			self::page_slug( 'uploads' ),
			'atr_uploads',
			array( 'label_for' => 'atr-max-upload-dimension' )
		);

		add_settings_section(
			'atr_optimize',
			'',
			array( __CLASS__, 'render_optimize_intro' ),
			self::page_slug( 'optimise' )
		);

		add_settings_field(
			'optimize_enabled',
			__( 'Optimise automatically', 'ajax-thumbnail-rebuild' ),
			array( __CLASS__, 'render_optimize_enabled' ),
			self::page_slug( 'optimise' ),
			'atr_optimize'
		);

		add_settings_field(
			'optimize_quality',
			__( 'Quality', 'ajax-thumbnail-rebuild' ),
			array( __CLASS__, 'render_optimize_quality' ),
			self::page_slug( 'optimise' ),
			'atr_optimize',
			array( 'label_for' => 'atr-optimize-quality' )
		);

		add_settings_field(
			'optimize_lossy',
			__( 'Lossy PNG', 'ajax-thumbnail-rebuild' ),
			array( __CLASS__, 'render_optimize_lossy' ),
			self::page_slug( 'optimise' ),
			'atr_optimize'
		);

		add_settings_field(
			'optimize_original',
			__( 'The untouched original', 'ajax-thumbnail-rebuild' ),
			array( __CLASS__, 'render_optimize_original' ),
			self::page_slug( 'optimise' ),
			'atr_optimize'
		);

		add_settings_field(
			'optimize_service',
			__( 'Optimisation service', 'ajax-thumbnail-rebuild' ),
			array( __CLASS__, 'render_optimize_service' ),
			self::page_slug( 'optimise' ),
			'atr_optimize',
			array( 'label_for' => 'atr-optimize-service' )
		);

		add_settings_section(
			'atr_webp',
			__( 'WebP copies', 'ajax-thumbnail-rebuild' ),
			array( __CLASS__, 'render_webp_intro' ),
			self::page_slug( 'serving' )
		);

		add_settings_field(
			'webp_enabled',
			__( 'Make WebP copies', 'ajax-thumbnail-rebuild' ),
			array( __CLASS__, 'render_webp_enabled' ),
			self::page_slug( 'serving' ),
			'atr_webp'
		);

		add_settings_field(
			'webp_quality',
			__( 'Quality', 'ajax-thumbnail-rebuild' ),
			array( __CLASS__, 'render_webp_quality' ),
			self::page_slug( 'serving' ),
			'atr_webp',
			array( 'label_for' => 'atr-webp-quality' )
		);

		add_settings_section(
			'atr_avif',
			__( 'AVIF copies', 'ajax-thumbnail-rebuild' ),
			array( __CLASS__, 'render_avif_intro' ),
			self::page_slug( 'serving' )
		);

		add_settings_field(
			'avif_enabled',
			__( 'Make AVIF copies', 'ajax-thumbnail-rebuild' ),
			array( __CLASS__, 'render_avif_enabled' ),
			self::page_slug( 'serving' ),
			'atr_avif'
		);

		add_settings_field(
			'avif_quality',
			__( 'Quality', 'ajax-thumbnail-rebuild' ),
			array( __CLASS__, 'render_avif_quality' ),
			self::page_slug( 'serving' ),
			'atr_avif',
			array( 'label_for' => 'atr-avif-quality' )
		);

		add_settings_section(
			'atr_proxy',
			__( 'Image proxy', 'ajax-thumbnail-rebuild' ),
			array( __CLASS__, 'render_proxy_intro' ),
			self::page_slug( 'serving' )
		);

		add_settings_field(
			'proxy_enabled',
			__( 'Serve images through wsrv.nl', 'ajax-thumbnail-rebuild' ),
			array( __CLASS__, 'render_proxy_enabled' ),
			self::page_slug( 'serving' ),
			'atr_proxy'
		);

		add_settings_field(
			'proxy_output',
			__( 'Format', 'ajax-thumbnail-rebuild' ),
			array( __CLASS__, 'render_proxy_output' ),
			self::page_slug( 'serving' ),
			'atr_proxy',
			array( 'label_for' => 'atr-proxy-output' )
		);

		add_settings_field(
			'proxy_quality',
			__( 'Quality', 'ajax-thumbnail-rebuild' ),
			array( __CLASS__, 'render_proxy_quality' ),
			self::page_slug( 'serving' ),
			'atr_proxy',
			array( 'label_for' => 'atr-proxy-quality' )
		);

		add_settings_section(
			'atr_on_demand',
			'',
			array( __CLASS__, 'render_on_demand_intro' ),
			self::page_slug( 'on-demand' )
		);

		add_settings_field(
			'on_demand_enabled',
			__( 'Generate on demand', 'ajax-thumbnail-rebuild' ),
			array( __CLASS__, 'render_on_demand_enabled' ),
			self::page_slug( 'on-demand' ),
			'atr_on_demand'
		);

		add_settings_field(
			'on_demand_sizes',
			__( 'Sizes made only on demand', 'ajax-thumbnail-rebuild' ),
			array( __CLASS__, 'render_on_demand_sizes' ),
			self::page_slug( 'on-demand' ),
			'atr_on_demand'
		);

	}

	/**
	 * @param mixed $input Raw input from the form.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		$input   = is_array( $input ) ? $input : array();
		$current = self::all();

		/* Each settings tab posts only its own fields and says which those are.
		   Without that, saving one tab would read every other tab's checkbox as
		   "unticked" and quietly turn it off. */
		$managed = array_filter( array_map( 'sanitize_key', explode( ',', (string) ( $input['_fields'] ?? '' ) ) ) );
		$managed = array_intersect( $managed, array_keys( self::defaults() ) );

		if ( ! $managed ) {
			$managed = array_keys( self::defaults() );
		}

		foreach ( $managed as $key ) {
			$current[ $key ] = self::sanitize_one( $key, $input, $current[ $key ] );
		}

		return $current;
	}

	/**
	 * One setting, by the rule that fits it.
	 *
	 * @param string $key      Setting name.
	 * @param array  $input    Everything that was posted.
	 * @param mixed  $fallback What it is now.
	 * @return mixed
	 */
	private static function sanitize_one( string $key, array $input, $fallback ) {
		$defaults = self::defaults();

		switch ( $key ) {
			// Checkboxes: absent means unticked, which is why $managed matters.
			case 'optimize_enabled':
			case 'optimize_lossy':
			case 'optimize_original':
			case 'webp_enabled':
			case 'avif_enabled':
			case 'proxy_enabled':
			case 'on_demand_enabled':
				return ! empty( $input[ $key ] );

			case 'upload_quality':
			case 'optimize_quality':
			case 'webp_quality':
			case 'avif_quality':
			case 'proxy_quality':
				return self::quality( $input[ $key ] ?? null, (int) $defaults[ $key ] );

			case 'max_upload_dimension':
				return max( 0, (int) ( $input[ $key ] ?? $defaults[ $key ] ) );

			case 'proxy_output':
				$output = sanitize_key( (string) ( $input[ $key ] ?? $defaults[ $key ] ) );

				return array_key_exists( $output, self::output_formats() ) ? $output : $defaults[ $key ];

			case 'optimize_service':
				return self::service( $input[ $key ] ?? $defaults[ $key ] );

			// A credential: unslashed and trimmed, never mangled.
			case 'optimize_service_key':
				return trim( (string) wp_unslash( $input[ $key ] ?? $fallback ) );

			case 'on_demand_sizes':
				return array_values( array_unique( array_filter(
					array_map( 'sanitize_key', (array) ( $input[ $key ] ?? array() ) )
				) ) );
		}

		return $fallback;
	}

	/**
	 * One of the services the plugin knows, or none at all.
	 *
	 * @param mixed $value Submitted value.
	 */
	private static function service( $value ): string {
		$value = sanitize_key( (string) $value );

		return isset( ATR_Service::services()[ $value ] ) ? $value : 'none';
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

	public static function render_uploads_intro(): void {
		$editor = _wp_image_editor_choose( array( 'mime_type' => 'image/jpeg' ) );
		?>
		<p class="description">
			<?php esc_html_e( 'What WordPress does with a file as it arrives. Both of these apply to new uploads and to anything you rebuild from here; images already on the site keep what they were given until then.', 'ajax-thumbnail-rebuild' ); ?>
			<?php if ( $editor ) : ?>
				<?php
				printf(
					/* translators: %s: name of the image library in use, e.g. Imagick. */
					esc_html__( 'This server resizes with %s.', 'ajax-thumbnail-rebuild' ),
					'<code>' . esc_html( str_replace( 'WP_Image_Editor_', '', (string) $editor ) ) . '</code>'
				);
				?>
			<?php endif; ?>
		</p>
		<?php
	}

	public static function render_upload_quality(): void {
		$settings = self::all();
		?>
		<input type="number" name="<?php echo esc_attr( self::OPTION ); ?>[upload_quality]" id="atr-upload-quality" class="small-text" min="1" max="100" step="1" value="<?php echo esc_attr( (string) $settings['upload_quality'] ); ?>" />
		<p class="description">
			<?php
			printf(
				/* translators: %s: the quality WordPress uses by default. */
				esc_html__( '1 to 100, used for every JPEG and PNG copy WordPress writes. WordPress uses %s when nothing says otherwise; higher means larger files.', 'ajax-thumbnail-rebuild' ),
				'<code>' . esc_html( (string) ATR_Uploads::CORE_QUALITY ) . '</code>'
			);
			?>
		</p>
		<?php
	}

	public static function render_max_upload_dimension(): void {
		$settings = self::all();
		?>
		<input type="number" name="<?php echo esc_attr( self::OPTION ); ?>[max_upload_dimension]" id="atr-max-upload-dimension" class="small-text" min="0" step="1" value="<?php echo esc_attr( (string) $settings['max_upload_dimension'] ); ?>" />
		<?php esc_html_e( 'pixels', 'ajax-thumbnail-rebuild' ); ?>
		<p class="description">
			<?php
			printf(
				/* translators: %s: the threshold WordPress uses by default. */
				esc_html__( 'An upload longer than this on its longest edge is scaled down to it, and the untouched original is kept beside it. WordPress uses %s. Set 0 to keep every upload at the size it arrived.', 'ajax-thumbnail-rebuild' ),
				'<code>' . esc_html( (string) ATR_Uploads::CORE_THRESHOLD ) . '</code>'
			);
			?>
		</p>
		<?php
	}

	public static function render_optimize_intro(): void {
		?>
		<p class="description">
			<?php esc_html_e( 'Optimising re-encodes a file that is already the right size, to get the same picture in fewer bytes. Nothing is resized and no file is renamed, the result is kept only when it is genuinely smaller, and the untouched original beside a scaled upload is never touched.', 'ajax-thumbnail-rebuild' ); ?>
		</p>
		<?php self::render_tool_status(); ?>
		<p class="description">
			<?php
			printf(
				/* translators: 1: opening link tag to the Rebuild tab, 2: closing link tag. */
				esc_html__( 'For the images already on the site, %1$srun it over the library%2$s, or optimise single images from the media library. Turning this on covers new uploads and anything you rebuild.', 'ajax-thumbnail-rebuild' ),
				'<a href="' . esc_url( ATR_Admin_Page::url( 'rebuild' ) ) . '">',
				'</a>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * What the server has to optimise with, said plainly.
	 *
	 * Without one of these programs all the plugin can do is write the image out
	 * again, which wins next to nothing on files WordPress has already written -
	 * so it is worth saying before somebody presses the button and sees "0 B".
	 */
	public static function render_tool_status(): void {
		$available = ATR_Tools::available();
		$known     = array();

		foreach ( ATR_Tools::definitions() as $programs ) {
			foreach ( array_keys( (array) $programs ) as $name ) {
				$known[ $name ] = isset( $available[ $name ] );
			}
		}

		if ( ! ATR_Tools::can_run() ) {
			?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'This server does not let PHP run other programs, so the image optimisers cannot be used at all. Optimising falls back to writing the image out again, which gains little on files WordPress has already written.', 'ajax-thumbnail-rebuild' ); ?></p>
			</div>
			<?php
			return;
		}
		?>
		<p class="description">
			<?php esc_html_e( 'Optimising uses the programs installed on the server. They re-pack a file rather than re-encode it, which is where the real saving is; without them all this can do is write the image out again, and WordPress wrote it at this quality already.', 'ajax-thumbnail-rebuild' ); ?>
		</p>

		<ul class="atr-tools">
			<?php foreach ( $known as $name => $installed ) : ?>
				<li>
					<span class="atr-badge <?php echo $installed ? 'atr-badge--yes' : ''; ?>">
						<?php echo $installed ? esc_html__( 'installed', 'ajax-thumbnail-rebuild' ) : esc_html__( 'missing', 'ajax-thumbnail-rebuild' ); ?>
					</span>
					<code><?php echo esc_html( $name ); ?></code>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php if ( ! $available ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<?php esc_html_e( 'None of them is installed, so optimising will save almost nothing. Ask the host to install jpegoptim and optipng - they are in every distribution and need no configuration.', 'ajax-thumbnail-rebuild' ); ?>
				</p>
			</div>
		<?php endif; ?>
		<?php
	}

	public static function render_optimize_lossy(): void {
		$settings = self::all();
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[optimize_lossy]" value="1" <?php checked( $settings['optimize_lossy'] ); ?> />
			<?php esc_html_e( 'Allow pngquant, which drops a PNG to fewer colours', 'ajax-thumbnail-rebuild' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Off by default because it changes the picture, not only the file. On screenshots, logos and flat graphics it typically halves the size with nothing visible to show for it; on photographs saved as PNG, look before you keep it.', 'ajax-thumbnail-rebuild' ); ?></p>
		<?php
	}

	public static function render_optimize_original(): void {
		$settings = self::all();
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[optimize_original]" value="1" <?php checked( $settings['optimize_original'] ); ?> />
			<?php esc_html_e( 'Also optimise the full size original kept beside a scaled upload', 'ajax-thumbnail-rebuild' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'That file is usually the largest on disk and nothing on the site ever serves it: WordPress keeps it so a size can be cut again later. It is treated as the archive copy it is - only the lossless programs touch it, never a re-encode, never a lossy pass, and it is never sent to a service.', 'ajax-thumbnail-rebuild' ); ?>
		</p>
		<?php
	}

	public static function render_optimize_service(): void {
		$settings = self::all();
		$usage    = ATR_Service::usage();
		?>
		<select name="<?php echo esc_attr( self::OPTION ); ?>[optimize_service]" id="atr-optimize-service">
			<option value="none" <?php selected( 'none', $settings['optimize_service'] ); ?>><?php esc_html_e( 'None - use what is on this server', 'ajax-thumbnail-rebuild' ); ?></option>
			<?php foreach ( ATR_Service::services() as $name => $service ) : ?>
				<option value="<?php echo esc_attr( $name ); ?>" <?php selected( $name, $settings['optimize_service'] ); ?>><?php echo esc_html( $service['label'] ); ?></option>
			<?php endforeach; ?>
		</select>

		<p>
			<label for="atr-optimize-service-key"><?php esc_html_e( 'API key', 'ajax-thumbnail-rebuild' ); ?></label><br />
			<input type="password" name="<?php echo esc_attr( self::OPTION ); ?>[optimize_service_key]" id="atr-optimize-service-key" class="regular-text" autocomplete="new-password" value="<?php echo esc_attr( $settings['optimize_service_key'] ); ?>" />
		</p>

		<p class="description">
			<?php esc_html_e( 'A service does the same job as the programs above, usually better, and it is the answer on a host that has none and will not let PHP run one. Every file is sent to the service and every file counts against the monthly allowance - a photograph with nine sizes is nine files.', 'ajax-thumbnail-rebuild' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( 'TinyPNG compresses one way only, which is lossy, so it is used only when lossy compression is allowed above. ShortPixel follows that setting. The untouched original is never sent either way.', 'ajax-thumbnail-rebuild' ); ?>
		</p>

		<?php if ( $usage && ATR_Service::usage_time() ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: 1: number of files, 2: how long ago. */
					esc_html__( 'The service last reported %1$s files used this month, %2$s ago.', 'ajax-thumbnail-rebuild' ),
					'<strong>' . esc_html( number_format_i18n( $usage ) ) . '</strong>',
					esc_html( human_time_diff( ATR_Service::usage_time() ) )
				);
				?>
			</p>
		<?php endif; ?>

		<?php $error = get_transient( 'atr_service_error' ); ?>
		<?php if ( $error ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<?php
					printf(
						/* translators: %s: what the service said. */
						esc_html__( 'The last time a file was sent, the service said: %s', 'ajax-thumbnail-rebuild' ),
						'<em>' . esc_html( $error ) . '</em>'
					);
					?>
				</p>
			</div>
		<?php endif; ?>
		<?php
	}

	public static function render_optimize_enabled(): void {
		$settings = self::all();
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[optimize_enabled]" value="1" <?php checked( $settings['optimize_enabled'] ); ?> />
			<?php esc_html_e( 'Optimise images as they are uploaded and after a rebuild', 'ajax-thumbnail-rebuild' ); ?>
		</label>
		<?php
	}

	public static function render_optimize_quality(): void {
		$settings = self::all();
		?>
		<input type="number" name="<?php echo esc_attr( self::OPTION ); ?>[optimize_quality]" id="atr-optimize-quality" class="small-text" min="1" max="100" step="1" value="<?php echo esc_attr( (string) $settings['optimize_quality'] ); ?>" />
		<p class="description"><?php esc_html_e( '1 to 100, used when re-encoding. Below the compression setting above there is something to gain; at or above it, most files will already be as small as they get.', 'ajax-thumbnail-rebuild' ); ?></p>
		<?php
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
			<?php esc_html_e( 'Every browser still in use reads WebP, so this is the safe copy to make. AVIF, below, is smaller again and is offered ahead of it where both are on.', 'ajax-thumbnail-rebuild' ); ?>
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
		<?php if ( ! ATR_Copies::supported( ATR_Webp::MIME ) ) : ?>
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

	public static function render_avif_intro(): void {
		?>
		<p class="description">
			<?php esc_html_e( 'The same thing again in AVIF, which is a good deal smaller than WebP at the same quality. Where both are on, the picture element offers AVIF first and the browser falls back to the WebP copy, and then to the original, when it cannot read it.', 'ajax-thumbnail-rebuild' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( 'Encoding AVIF costs several times the processing that WebP does, so uploads and rebuilds take noticeably longer with this on. On a small server it is worth turning on for new uploads and rebuilding the library in batches.', 'ajax-thumbnail-rebuild' ); ?>
		</p>
		<?php if ( ! ATR_Copies::supported( ATR_Avif::MIME ) ) : ?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'The image library on this server cannot write AVIF, so no copies will be made. It needs WordPress 6.5 or later with GD or Imagick built against an AVIF encoder - ask your host. Nothing breaks meanwhile: the WebP copies go on being served.', 'ajax-thumbnail-rebuild' ); ?></p>
			</div>
		<?php endif; ?>
		<?php
	}

	public static function render_avif_enabled(): void {
		$settings = self::all();
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[avif_enabled]" value="1" <?php checked( $settings['avif_enabled'] ); ?> />
			<?php esc_html_e( 'Convert generated images to AVIF and serve the copies', 'ajax-thumbnail-rebuild' ); ?>
		</label>
		<?php
	}

	public static function render_avif_quality(): void {
		$settings = self::all();
		?>
		<input type="number" name="<?php echo esc_attr( self::OPTION ); ?>[avif_quality]" id="atr-avif-quality" class="small-text" min="1" max="100" step="1" value="<?php echo esc_attr( (string) $settings['avif_quality'] ); ?>" />
		<p class="description"><?php esc_html_e( '1 to 100, used when writing the AVIF copies. The scale is not the same one JPEG and WebP use - AVIF around 60 looks about like JPEG at 82, and going higher costs a lot of file size for very little.', 'ajax-thumbnail-rebuild' ); ?></p>
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
