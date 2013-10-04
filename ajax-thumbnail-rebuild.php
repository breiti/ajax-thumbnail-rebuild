<?php
/*
	Plugin name: AJAX Thumbnail Rebuild
	Plugin URI: http://breiti.cc/wordpress/ajax-thumbnail-rebuild
	Author: junkcoder
	Author URI: http://breiti.cc
	Version: 1.08
	Description: Rebuild all thumbnails
	Max WP Version: 3.2.1
	Text Domain: ajax-thumbnail-rebuild

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

class Ajax_Thumbnail_Rebuild {
	/**
	 * Class constructor
	 */
	function Ajax_Thumbnail_Rebuild() {
		add_action( 'admin_menu', array( &$this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'load_scripts' ) );
		add_action( 'wp_ajax_ajax_thumbnail_rebuild', array( $this, 'rebuild_thumbnails' ) );

		load_plugin_textdomain( 'ajax-thumbnail-rebuild', false, basename( dirname( __FILE__ ) ) . '/languages' );
	}

	/**
	 * Add plugin page under Tools
	 */
	function add_admin_menu() {
		add_management_page( __( 'Rebuild all Thumbnails', 'ajax-thumbnail-rebuild' ), __( 'Rebuild Thumbnails', 'ajax-thumbnail-rebuild' ), 'manage_options', 'ajax-thumbnail-rebuild', array( &$this, 'management_page' ) );
	}

	/**
	 * Load CSS, JS and localize
	 * @return void
	 */
	function load_scripts() {
		// Load CSS
		wp_enqueue_style( 'ajax-thumbnail-rebuild', plugins_url( 'assets/css/ajax-thumbnail-rebuild.css', __FILE__ ) );

		// Load sprintf extension so we could properly format the translated text
		// Also load plugins main file
		wp_enqueue_script( 'sprintf', plugins_url( 'assets/js/sprintf.min.js', __FILE__ ), false, false, true );
		wp_enqueue_script( 'ajax-thumbnail-rebuild', plugins_url( 'assets/js/ajax-thumbnail-rebuild.js', __FILE__ ), array( 'jquery' ), false, true );

		// Add translations
		$localization	= array(
				'reading_attachments'	=> __( 'Reading attachments...', 'ajax-thumbnail-rebuild' ),
				'error_msg'				=> __( 'Error', 'ajax-thumbnail-rebuild' ),
				'no_attachments'		=> __( 'No attachments found.', 'ajax-thumbnail-rebuild' ),
				'rebuilding'			=> __( 'Rebuilding %s of %s (%s)...', 'ajax-thumbnail-rebuild' ),
				'done'					=> __( 'Done.', 'ajax-thumbnail-rebuild' )
			);

		// Localize translations for JS
		wp_localize_script( 'ajax-thumbnail-rebuild', 'ajaxthumbnail', $localization );
	}

	/**
	 * Plugins management page under Tools section
	 * @return void
	 */
	function management_page() {
		?>
		<div class="wrap">
			<?php screen_icon(); ?>
			<h2><?php _e( 'Rebuild Thumbnails', 'ajax-thumbnail-rebuild' ) ?></h2>
			<div id="message" class="updated fade"></div>

			<form method="post" action="" id="thumbnails-form">
			    <h4><?php _e( 'Select which thumbnails you want to rebuild', 'ajax-thumbnail-rebuild' ); ?>:</h4>
				<p><a href="#" id="size-toggle"><?php _e( 'Toggle all', 'ajax-thumbnail-rebuild' ); ?></a></p>
				<ul id="sizeselect">
				<?php foreach ( $this->get_sizes() as $s ) : ?>
					<li>
						<input type="checkbox" name="thumbnails[]" id="sizeselect-<?php echo $s['name'] ?>" checked="checked" value="<?php echo $s['name'] ?>" />
						<label for="sizeselect-<?php echo $s['name'] ?>">
							<em><?php echo $s['name'] ?></em> 
							(<?php echo $s['width'] ?>x<?php echo $s['height'] ?><?php if ($s['crop']) echo ' ' . __( 'cropped', 'ajax-thumbnail-rebuild' ); ?>)
						</label>
					</li>
				<?php endforeach;?>
				</ul>
				<p>
					<input type="checkbox" id="onlyfeatured" name="onlyfeatured" />
					<label for="onlyfeatured"><?php _e( 'Only rebuild featured images', 'ajax-thumbnail-rebuild' ); ?></label>
				</p>

				<p><?php _e( "Note: If you've changed the dimensions of your thumbnails, existing thumbnail images will not be deleted.", 'ajax-thumbnail-rebuild' ) ?></p>
				<input type="button" id="regenerate" class="button" name="ajax_thumbnail_rebuild" id="ajax_thumbnail_rebuild" value="<?php _e( 'Rebuild All Thumbnails', 'ajax-thumbnail-rebuild' ) ?>" />
			</form>

			<div id="thumb">
				<h4><?php _e( 'Last image', 'ajax-thumbnail-rebuild' ); ?>:</h4>
				<img id="thumb-img" />
			</div>

			<p class="author-comments"><?php printf( __( "If you find this plugin useful, I'd be happy to read your comments on the %splugin homepage%s. If you experience any problems, feel free to leave a comment too.", 'ajax-thumbnail-rebuild'), '<a href="http://breiti.cc/wordpress/ajax-thumbnail-rebuild" target="_blank">', '</a>' ) ?></p>
		</div>
		<?php
	}

	/**
	 * The main action - rebuilding
	 * @return void
	 */
	function rebuild_thumbnails() {
		global $wpdb;

		$action			= $_POST["do"];
		$thumbnails 	= isset( $_POST['thumbnails'] )? $_POST['thumbnails'] : NULL;
		$onlyfeatured	= isset( $_POST['onlyfeatured'] ) ? $_POST['onlyfeatured'] : 0;

		// Get all of the images
		if( $action == "getlist" ) {
			// Do we need only featured images?
			if ( $onlyfeatured ) {
				/* Get all featured images */
				$featured_images = $wpdb->get_results( "SELECT meta_value,{$wpdb->posts}.post_title AS title FROM {$wpdb->postmeta}, {$wpdb->posts} WHERE meta_key = '_thumbnail_id' AND {$wpdb->postmeta}.post_id={$wpdb->posts}.ID" );

				foreach( $featured_images as $image ) {
				    $res[]	= array( 'id' => $image->meta_value, 'title' => $image->title );
				}
			}
			// Get all images
			else {
				$attachments	= get_children( array(
					'post_type'			=> 'attachment',
					'post_mime_type'	=> 'image',
					'numberposts'		=> -1,
					'post_status'		=> null,
					'post_parent'		=> null, // any parent
					'output'			=> 'object',
				));

				foreach ( $attachments as $attachment ) {
				    $res[]	= array( 'id' => $attachment->ID, 'title' => $attachment->post_title );
				}
			}

			// Output images
			die( json_encode( $res ) );
		}
		// Regenerate thumbnails
		elseif ( $action == "regen" ) {
			$id				= $_POST["id"];
			$fullsizepath	= get_attached_file( $id );

			if ( FALSE !== $fullsizepath && @file_exists( $fullsizepath ) ) {
				set_time_limit( 30 );
				wp_update_attachment_metadata( $id, $this->generate_attachment_metadata( $id, $fullsizepath, $thumbnails ) );
			}

			die( wp_get_attachment_thumb_url( $id ) );
		}
	}

	/**
	 * Get available image sizes
	 * @return array Sizes with options (width, height, crop)
	 */
	function get_sizes() {
		global $_wp_additional_image_sizes;

		foreach ( get_intermediate_image_sizes() as $s ) {
			$sizes[$s] = array( 'name' => '', 'width' => '', 'height' => '', 'crop' => FALSE );

			// Read theme added sizes or fall back to default sizes set in options...
			$sizes[$s]['name'] = $s;

			if ( isset( $_wp_additional_image_sizes[$s]['width'] ) )
				$sizes[$s]['width'] = intval( $_wp_additional_image_sizes[$s]['width'] ); 
			else
				$sizes[$s]['width'] = get_option( "{$s}_size_w" );

			if ( isset( $_wp_additional_image_sizes[$s]['height'] ) )
				$sizes[$s]['height'] = intval( $_wp_additional_image_sizes[$s]['height'] );
			else
				$sizes[$s]['height'] = get_option( "{$s}_size_h" );

			if ( isset( $_wp_additional_image_sizes[$s]['crop'] ) )
				$sizes[$s]['crop'] = intval( $_wp_additional_image_sizes[$s]['crop'] );
			else
				$sizes[$s]['crop'] = get_option( "{$s}_crop" );
		}

		return $sizes;
	}


	/**
	 * Generate post thumbnail attachment meta data.
	 *
	 * @since 2.1.0
	 *
	 * @param int $attachment_id Attachment Id to process.
	 * @param string $file Filepath of the Attached image.
	 * @return mixed Metadata for attachment.
	 */
	function generate_attachment_metadata( $attachment_id, $file, $thumbnails = NULL ) {
		$attachment	= get_post( $attachment_id );
		$metadata	= array();

		if ( preg_match( '!^image/!', get_post_mime_type( $attachment ) ) && file_is_displayable_image( $file ) ) {
			$imagesize					= getimagesize( $file );
			$metadata['width']			= $imagesize[0];
			$metadata['height']			= $imagesize[1];
			list($uwidth, $uheight)		= wp_constrain_dimensions( $metadata['width'], $metadata['height'], 128, 96 );
			$metadata['hwstring_small']	= "height='$uheight' width='$uwidth'";

			// Make the file path relative to the upload dir
			$metadata['file']			= _wp_relative_upload_path($file);

			$sizes						= $this->get_sizes();
			$sizes						= apply_filters( 'intermediate_image_sizes_advanced', $sizes );

			foreach ($sizes as $size => $size_data ) {
				if( isset( $thumbnails ) && !in_array( $size, $thumbnails ))
					$intermediate_size = image_get_intermediate_size( $attachment_id, $size_data['name'] );
				else
					$intermediate_size = image_make_intermediate_size( $file, $size_data['width'], $size_data['height'], $size_data['crop'] );

				if ($intermediate_size)
					$metadata['sizes'][$size] = $intermediate_size;
			}

			// fetch additional metadata from exif/iptc
			$image_meta = wp_read_image_metadata( $file );
			if ( $image_meta )
				$metadata['image_meta'] = $image_meta;

		}

		return apply_filters( 'wp_generate_attachment_metadata', $metadata, $attachment_id );
	}
}

add_action( 'plugins_loaded', create_function( '', 'global $AjaxThumbnailRebuild; $AjaxThumbnailRebuild = new Ajax_Thumbnail_Rebuild();' ) );
