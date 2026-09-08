<?php
/**
 * Tools -> Rebuild Thumbnails.
 *
 * The screen is built from the components WordPress already ships - postboxes,
 * list tables, form tables, notices - so it looks and behaves like the rest of
 * the admin rather than like a plugin with opinions of its own.
 *
 * @package ajax-thumbnail-rebuild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATR_Admin_Page {

	const SLUG = 'ajax-thumbnail-rebuild';

	/** Hook suffix of the page, for admin_enqueue_scripts. */
	private string $hook_suffix = '';

	public function register(): void {
		$this->hook_suffix = (string) add_management_page(
			__( 'Rebuild Thumbnails', 'ajax-thumbnail-rebuild' ),
			__( 'Rebuild Thumbnails', 'ajax-thumbnail-rebuild' ),
			ATR_Plugin::capability(),
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function hook_suffix(): string {
		return $this->hook_suffix;
	}

	public static function url( string $tab = '' ): string {
		$args = array( 'page' => self::SLUG );

		if ( '' !== $tab ) {
			$args['tab'] = $tab;
		}

		return add_query_arg( $args, admin_url( 'tools.php' ) );
	}

	/**
	 * Every tab on the screen: what it is called and what it shows.
	 *
	 * @return array<string,string>
	 */
	private function tabs(): array {
		$tabs = array(
			'rebuild' => __( 'Rebuild', 'ajax-thumbnail-rebuild' ),
			'sizes'   => __( 'Registered sizes', 'ajax-thumbnail-rebuild' ),
			'cleanup' => __( 'Cleanup', 'ajax-thumbnail-rebuild' ),
		);

		/* The settings are a tab each. All of them on one page was a wall of
		   fields nobody could find anything in. */
		foreach ( ATR_Settings::pages() as $page => $settings ) {
			$tabs[ $page ] = $settings['label'];
		}

		return $tabs;
	}

	private function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'rebuild';

		return array_key_exists( $tab, $this->tabs() ) ? $tab : 'rebuild';
	}

	public function render(): void {
		if ( ! current_user_can( ATR_Plugin::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to rebuild thumbnails.', 'ajax-thumbnail-rebuild' ) );
		}

		$tab   = $this->current_tab();
		$sizes = ATR_Image_Sizes::all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Rebuild Thumbnails', 'ajax-thumbnail-rebuild' ); ?></h1>

			<p class="description">
				<?php esc_html_e( 'Recreate the resized copies WordPress makes of every image. Images are rebuilt one at a time, so a large library will not run into a script timeout.', 'ajax-thumbnail-rebuild' ); ?>
			</p>

			<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Secondary menu', 'ajax-thumbnail-rebuild' ); ?>">
				<?php foreach ( $this->tabs() as $key => $label ) : ?>
					<a href="<?php echo esc_url( self::url( $key ) ); ?>" class="nav-tab <?php echo $key === $tab ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
						<?php if ( 'sizes' === $key ) : ?>
							<span class="atr-count"><?php echo esc_html( number_format_i18n( count( $sizes ) ) ); ?></span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="atr-tab">
				<?php if ( 'sizes' === $tab ) : ?>
					<?php $this->render_sizes_tab( $sizes ); ?>
				<?php elseif ( 'cleanup' === $tab ) : ?>
					<?php $this->render_cleanup_tab(); ?>
				<?php elseif ( isset( ATR_Settings::pages()[ $tab ] ) ) : ?>
					<?php $this->render_settings_tab( $tab ); ?>
				<?php else : ?>
					<?php $this->render_rebuild_tab( $sizes ); ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array<string,array> $sizes
	 */
	private function render_rebuild_tab( array $sizes ): void {
		?>
		<div id="poststuff">
			<div id="post-body" class="metabox-holder columns-2">

				<div id="post-body-content">
					<div class="postbox">
						<div class="postbox-header"><h2 class="hndle"><span><?php esc_html_e( 'Sizes to rebuild', 'ajax-thumbnail-rebuild' ); ?></span></h2></div>
						<div class="inside">
							<?php if ( ! $sizes ) : ?>
								<div class="notice notice-warning inline">
									<p><?php esc_html_e( 'This site has no image sizes registered, so there is nothing to rebuild.', 'ajax-thumbnail-rebuild' ); ?></p>
								</div>
							<?php else : ?>
								<table class="wp-list-table widefat fixed striped table-view-list" id="atr-sizes">
									<thead>
										<tr>
											<td class="manage-column column-cb check-column">
												<label class="screen-reader-text" for="atr-size-select-all"><?php esc_html_e( 'Select all sizes', 'ajax-thumbnail-rebuild' ); ?></label>
												<input type="checkbox" id="atr-size-select-all" checked="checked" />
											</td>
											<th scope="col" class="manage-column column-primary"><?php esc_html_e( 'Size', 'ajax-thumbnail-rebuild' ); ?></th>
											<th scope="col" class="manage-column"><?php esc_html_e( 'Dimensions', 'ajax-thumbnail-rebuild' ); ?></th>
											<th scope="col" class="manage-column"><?php esc_html_e( 'Crop', 'ajax-thumbnail-rebuild' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php $on_demand = ATR_On_Demand::sizes(); ?>
										<?php foreach ( $sizes as $size ) : ?>
											<?php
											$id      = 'atr-size-' . sanitize_html_class( $size['name'] );
											$waiting = in_array( $size['name'], $on_demand, true );
											?>
											<tr>
												<th scope="row" class="check-column">
													<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>">
														<?php
														/* translators: %s: image size name. */
														printf( esc_html__( 'Rebuild the %s size', 'ajax-thumbnail-rebuild' ), esc_html( $size['name'] ) );
														?>
													</label>
													<input type="checkbox" class="atr-size" id="<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( $size['name'] ); ?>" checked="checked" />
												</th>
												<td class="column-primary">
													<label for="<?php echo esc_attr( $id ); ?>"><strong><?php echo esc_html( $size['name'] ); ?></strong></label>
													<?php if ( $waiting ) : ?>
														<span class="atr-badge"><?php esc_html_e( 'on demand', 'ajax-thumbnail-rebuild' ); ?></span>
														<p class="description"><?php esc_html_e( 'Cut the first time a page asks for it. Rebuilding redoes it for the images that already have it, and cuts it for no others.', 'ajax-thumbnail-rebuild' ); ?></p>
													<?php endif; ?>
												</td>
												<td><?php echo esc_html( ATR_Image_Sizes::dimensions_label( $size ) ); ?></td>
												<td><?php echo esc_html( ATR_Image_Sizes::crop_label( $size ) ?: '—' ); ?></td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							<?php endif; ?>
						</div>
					</div>

					<div class="postbox">
						<div class="postbox-header"><h2 class="hndle"><span><?php esc_html_e( 'Which images', 'ajax-thumbnail-rebuild' ); ?></span></h2></div>
						<div class="inside">
							<?php $selected = ATR_Media_Library::requested_ids(); ?>
							<?php if ( $selected ) : ?>
								<div class="notice notice-info inline">
									<p>
										<?php
										printf(
											/* translators: %s: number of images selected in the media library. */
											esc_html( _n( 'Working on the %s image you picked in the media library.', 'Working on the %s images you picked in the media library.', count( $selected ), 'ajax-thumbnail-rebuild' ) ),
											'<strong>' . esc_html( number_format_i18n( count( $selected ) ) ) . '</strong>'
										);
										?>
										<a href="<?php echo esc_url( self::url( 'rebuild' ) ); ?>"><?php esc_html_e( 'Work on the whole library instead', 'ajax-thumbnail-rebuild' ); ?></a>
									</p>
								</div>
							<?php endif; ?>

							<table class="form-table" role="presentation">
								<tbody>
									<tr>
										<th scope="row"><?php esc_html_e( 'Featured images', 'ajax-thumbnail-rebuild' ); ?></th>
										<td>
											<label>
												<input type="checkbox" id="atr-only-featured" />
												<?php esc_html_e( 'Only rebuild images used as a featured image', 'ajax-thumbnail-rebuild' ); ?>
											</label>
											<?php if ( ATR_Attachments::gallery_meta_keys() ) : ?>
												<p class="description">
													<?php esc_html_e( 'A WooCommerce product image is its featured image, so product images are included, and so are the images in a product gallery.', 'ajax-thumbnail-rebuild' ); ?>
												</p>
											<?php endif; ?>
										</td>
									</tr>
									<tr>
										<th scope="row">
											<label for="atr-filename"><?php esc_html_e( 'File name', 'ajax-thumbnail-rebuild' ); ?></label>
										</th>
										<td>
											<input type="text" id="atr-filename" class="regular-text code" placeholder="banner-*.jpg" />
											<p class="description">
												<?php esc_html_e( 'Leave empty to rebuild every image. Use * for any characters and ? for a single one.', 'ajax-thumbnail-rebuild' ); ?>
											</p>
										</td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>
				</div>

				<div id="postbox-container-1" class="postbox-container">
					<div class="postbox">
						<div class="postbox-header"><h2 class="hndle"><span><?php esc_html_e( 'Run', 'ajax-thumbnail-rebuild' ); ?></span></h2></div>
						<div class="inside">
							<p class="atr-library-count">
								<?php
								$total = ATR_Attachments::count();
								printf(
									/* translators: %s: number of images in the media library. */
									esc_html( _n( '%s image in the media library.', '%s images in the media library.', $total, 'ajax-thumbnail-rebuild' ) ),
									'<strong>' . esc_html( number_format_i18n( $total ) ) . '</strong>'
								);
								?>
							</p>

							<?php $mode = ATR_Media_Library::requested_mode(); ?>
							<fieldset class="atr-mode">
								<legend class="screen-reader-text"><?php esc_html_e( 'What to do', 'ajax-thumbnail-rebuild' ); ?></legend>
								<label>
									<input type="radio" name="atr-mode" value="rebuild" <?php checked( 'optimize' !== $mode ); ?> />
									<?php esc_html_e( 'Rebuild the selected sizes', 'ajax-thumbnail-rebuild' ); ?>
								</label>
								<label>
									<input type="radio" name="atr-mode" value="optimize" <?php checked( 'optimize' === $mode ); ?> />
									<?php esc_html_e( 'Optimise only, without resizing', 'ajax-thumbnail-rebuild' ); ?>
								</label>
							</fieldset>

							<div id="atr-progress" class="atr-progress" hidden>
								<div class="atr-progress__track">
									<div class="atr-progress__bar" id="atr-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div>
								</div>
								<p class="atr-progress__status" id="atr-status" aria-live="polite"></p>
								<p class="atr-progress__preview">
									<img id="atr-preview" src="" alt="" hidden />
								</p>
							</div>

							<div id="atr-result"></div>
						</div>
						<div id="major-publishing-actions">
							<span class="spinner" id="atr-spinner"></span>
							<div id="publishing-action">
								<button type="button" class="button button-primary button-large" id="atr-start"
									data-label-rebuild="<?php esc_attr_e( 'Rebuild thumbnails', 'ajax-thumbnail-rebuild' ); ?>"
									data-label-optimize="<?php esc_attr_e( 'Optimise images', 'ajax-thumbnail-rebuild' ); ?>">
									<?php echo 'optimize' === $mode ? esc_html__( 'Optimise images', 'ajax-thumbnail-rebuild' ) : esc_html__( 'Rebuild thumbnails', 'ajax-thumbnail-rebuild' ); ?>
								</button>
								<button type="button" class="button button-large" id="atr-stop" hidden>
									<?php esc_html_e( 'Stop', 'ajax-thumbnail-rebuild' ); ?>
								</button>
							</div>
						</div>
					</div>

					<div class="postbox" id="atr-skipped-box" hidden>
						<div class="postbox-header"><h2 class="hndle"><span><?php esc_html_e( 'Skipped images', 'ajax-thumbnail-rebuild' ); ?></span></h2></div>
						<div class="inside">
							<p class="description"><?php esc_html_e( 'These images were left as they were. Their file is missing or is not readable as an image.', 'ajax-thumbnail-rebuild' ); ?></p>
							<ul class="atr-skipped" id="atr-skipped"></ul>
						</div>
					</div>
				</div>

			</div>
		</div>
		<?php
	}

	private function render_cleanup_tab(): void {
		?>
		<div id="poststuff">
			<div id="post-body" class="metabox-holder columns-2">

				<div id="post-body-content">
					<div class="postbox">
						<div class="postbox-header"><h2 class="hndle"><span><?php esc_html_e( 'Files nothing points at', 'ajax-thumbnail-rebuild' ); ?></span></h2></div>
						<div class="inside">
							<p>
								<?php esc_html_e( 'Change a size from 300x300 to 400x400 and WordPress writes the new file and forgets the old one, which stays on disk for good. This looks for those leftovers: files named after an image that the image itself no longer refers to.', 'ajax-thumbnail-rebuild' ); ?>
							</p>
							<p class="description">
								<?php esc_html_e( 'The scan only reads. Nothing is deleted until you look at the list and say so, and a file that is an attachment in its own right, or that an AVIF or WebP copy belongs to, is never listed. Sizes that are still in an image\'s metadata are left alone even when nothing on the site uses them any more - deleting those is not something a scan can decide for you.', 'ajax-thumbnail-rebuild' ); ?>
							</p>

							<div id="atr-cleanup-result"></div>

							<table class="wp-list-table widefat fixed striped table-view-list" id="atr-cleanup-table" hidden>
								<thead>
									<tr>
										<th scope="col" class="manage-column column-primary"><?php esc_html_e( 'File', 'ajax-thumbnail-rebuild' ); ?></th>
										<th scope="col" class="manage-column"><?php esc_html_e( 'Image', 'ajax-thumbnail-rebuild' ); ?></th>
										<th scope="col" class="manage-column"><?php esc_html_e( 'Size', 'ajax-thumbnail-rebuild' ); ?></th>
									</tr>
								</thead>
								<tbody id="atr-cleanup-rows"></tbody>
							</table>
						</div>
					</div>
				</div>

				<div id="postbox-container-1" class="postbox-container">
					<div class="postbox">
						<div class="postbox-header"><h2 class="hndle"><span><?php esc_html_e( 'Scan', 'ajax-thumbnail-rebuild' ); ?></span></h2></div>
						<div class="inside">
							<p class="atr-library-count">
								<?php
								$total = ATR_Attachments::count();
								printf(
									/* translators: %s: number of images in the media library. */
									esc_html( _n( '%s image in the media library.', '%s images in the media library.', $total, 'ajax-thumbnail-rebuild' ) ),
									'<strong>' . esc_html( number_format_i18n( $total ) ) . '</strong>'
								);
								?>
							</p>

							<div id="atr-cleanup-progress" class="atr-progress" hidden>
								<div class="atr-progress__track">
									<div class="atr-progress__bar" id="atr-cleanup-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div>
								</div>
								<p class="atr-progress__status" id="atr-cleanup-status" aria-live="polite"></p>
							</div>
						</div>
						<div id="major-publishing-actions">
							<span class="spinner" id="atr-cleanup-spinner"></span>
							<div id="publishing-action">
								<button type="button" class="button button-primary button-large" id="atr-cleanup-scan">
									<?php esc_html_e( 'Scan for leftovers', 'ajax-thumbnail-rebuild' ); ?>
								</button>
								<button type="button" class="button button-large" id="atr-cleanup-stop" hidden>
									<?php esc_html_e( 'Stop', 'ajax-thumbnail-rebuild' ); ?>
								</button>
							</div>
						</div>
					</div>

					<div class="postbox" id="atr-cleanup-delete-box" hidden>
						<div class="postbox-header"><h2 class="hndle"><span><?php esc_html_e( 'Delete', 'ajax-thumbnail-rebuild' ); ?></span></h2></div>
						<div class="inside">
							<p id="atr-cleanup-summary"></p>
							<p class="description"><?php esc_html_e( 'Deleting cannot be undone. Take a backup of the uploads folder if you are not sure.', 'ajax-thumbnail-rebuild' ); ?></p>
						</div>
						<div id="major-publishing-actions">
							<div id="publishing-action">
								<button type="button" class="button button-large delete" id="atr-cleanup-delete"
									data-confirm="<?php esc_attr_e( 'Delete these files? This cannot be undone.', 'ajax-thumbnail-rebuild' ); ?>">
									<?php esc_html_e( 'Delete the listed files', 'ajax-thumbnail-rebuild' ); ?>
								</button>
							</div>
						</div>
					</div>
				</div>

			</div>
		</div>
		<?php
	}

	/**
	 * One settings tab: its own form, saving its own fields and nothing else.
	 *
	 * @param string $page Which tab.
	 */
	private function render_settings_tab( string $page ): void {
		$settings = ATR_Settings::pages()[ $page ];

		// options.php saves and redirects back here; this prints what it has to say.
		settings_errors();
		?>
		<div id="poststuff">
		<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
			<?php settings_fields( ATR_Settings::GROUP ); ?>
			<input type="hidden" name="<?php echo esc_attr( ATR_Settings::OPTION ); ?>[_fields]" value="<?php echo esc_attr( implode( ',', $settings['keys'] ) ); ?>" />

			<?php if ( 'serving' === $page && ATR_Image_Proxy::site_looks_local() ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php
						printf(
							/* translators: %s: this site's host name. */
							esc_html__( '%s does not look like a host the proxy can reach from the internet. The settings save either way, but the images will not load while you are on this address.', 'ajax-thumbnail-rebuild' ),
							'<code>' . esc_html( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) . '</code>'
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php $this->render_settings_sections( ATR_Settings::page_slug( $page ) ); ?>

			<?php submit_button(); ?>
		</form>
		</div>
		<?php
	}

	/**
	 * Each registered section as a card of its own.
	 *
	 * do_settings_sections() prints the section title as a plain heading inside
	 * whatever it is called in, and WordPress styles a heading inside a postbox
	 * as a pre-4.4 box title - which lands in the wrong place. A section is a
	 * card here, with the title in the header where a postbox keeps it.
	 *
	 * @param string $slug Settings API page.
	 */
	private function render_settings_sections( string $slug ): void {
		global $wp_settings_sections, $wp_settings_fields;

		foreach ( (array) ( $wp_settings_sections[ $slug ] ?? array() ) as $section ) {
			?>
			<div class="postbox">
				<?php if ( ! empty( $section['title'] ) ) : ?>
					<div class="postbox-header"><h2 class="hndle"><span><?php echo esc_html( $section['title'] ); ?></span></h2></div>
				<?php endif; ?>

				<div class="inside">
					<?php if ( ! empty( $section['callback'] ) ) : ?>
						<?php call_user_func( $section['callback'], $section ); ?>
					<?php endif; ?>

					<?php if ( isset( $wp_settings_fields[ $slug ][ $section['id'] ] ) ) : ?>
						<table class="form-table" role="presentation">
							<?php do_settings_fields( $slug, $section['id'] ); ?>
						</table>
					<?php endif; ?>
				</div>
			</div>
			<?php
		}
	}

	/**
	 * @param array<string,array> $sizes
	 */
	private function render_sizes_tab( array $sizes ): void {
		?>
		<table class="wp-list-table widefat fixed striped table-view-list">
			<thead>
				<tr>
					<th scope="col" class="manage-column column-primary"><?php esc_html_e( 'Size', 'ajax-thumbnail-rebuild' ); ?></th>
					<th scope="col" class="manage-column"><?php esc_html_e( 'Width', 'ajax-thumbnail-rebuild' ); ?></th>
					<th scope="col" class="manage-column"><?php esc_html_e( 'Height', 'ajax-thumbnail-rebuild' ); ?></th>
					<th scope="col" class="manage-column"><?php esc_html_e( 'Crop', 'ajax-thumbnail-rebuild' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $sizes ) : ?>
					<tr class="no-items">
						<td class="colspanchange" colspan="4"><?php esc_html_e( 'No image sizes are registered on this site.', 'ajax-thumbnail-rebuild' ); ?></td>
					</tr>
				<?php endif; ?>
				<?php foreach ( $sizes as $size ) : ?>
					<tr>
						<td class="column-primary"><strong><?php echo esc_html( $size['name'] ); ?></strong></td>
						<td><?php echo esc_html( $size['width'] > 0 ? number_format_i18n( $size['width'] ) : __( 'any', 'ajax-thumbnail-rebuild' ) ); ?></td>
						<td><?php echo esc_html( $size['height'] > 0 ? number_format_i18n( $size['height'] ) : __( 'any', 'ajax-thumbnail-rebuild' ) ); ?></td>
						<td><?php echo esc_html( ATR_Image_Sizes::crop_label( $size ) ?: '—' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<p class="description">
			<?php esc_html_e( 'Sizes come from WordPress, your theme and your plugins. Change them where they are registered, then rebuild.', 'ajax-thumbnail-rebuild' ); ?>
		</p>
		<?php
	}
}
