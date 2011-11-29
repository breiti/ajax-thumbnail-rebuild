<?php
/* Plugin name: AJAX Thumbnail Rebuild
   Plugin URI: http://breiti.cc/wordpress/ajax-thumbnail-rebuild
   Author: junkcoder
   Author URI: http://breiti.cc
   Version: 1.05
   Description: Rebuild all thumbnails
   Max WP Version: 3.0.1

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

class AjaxThumbnailRebuild {

	function AjaxThumbnailRebuild() {
		add_action( 'admin_menu', array(&$this, 'addAdminMenu') );
	}

	function addAdminMenu() {
		add_management_page( __( 'Rebuild all Thumbnails', 'ajax-thumbnail-rebuild' ), __( 'Rebuild Thumbnails', 'ajax_thumbnail_rebuild'
 ), 'manage_options', 'ajax-thumbnail-rebuild', array(&$this, 'ManagementPage') );
	}

	function ManagementPage() {
		?>
		<div id="message" class="updated fade" style="display:none"></div>
		<script type="text/javascript">
		// <![CDATA[

		function setMessage(msg) {
			jQuery("#message").html(msg);
			jQuery("#message").show();
		}

		function regenerate() {
			jQuery("#ajax_thumbnail_rebuild").attr("disabled", true);
			setMessage("<p>Reading attachments...</p>");

			inputs = jQuery( 'input:checked' );
			var thumbnails= '';
			if( inputs.length != jQuery( 'input[type=checkbox]' ).length ){
				inputs.each( function(){
					thumbnails += '&thumbnails[]='+jQuery(this).val();
				} );
			}

			var onlypostthumbs = jQuery("#onlypostthumbs").attr('checked') ? 1 : 0;

			jQuery.ajax({
				url: "<?php bloginfo('wpurl'); ?>/wp-admin/admin-ajax.php", 
				type: "POST",
				data: "action=ajax_thumbnail_rebuild&do=getlist&onlypostthumbs="+onlypostthumbs,
				success: function(result) {
					var list = eval(result);
					var curr = 0;

					function regenItem() {
						if (curr >= list.length) {
							jQuery("#ajax_thumbnail_rebuild").removeAttr("disabled");
							setMessage("Done.");
							return;
						}
						setMessage("Regenerating " + (curr+1) + " of " + list.length + " (" + list[curr].title + ")...");

						jQuery.ajax({
							url: "<?php bloginfo('wpurl'); ?>/wp-admin/admin-ajax.php",
							type: "POST",
							data: "action=ajax_thumbnail_rebuild&do=regen&id=" + list[curr].id + thumbnails,
							success: function(result) {
								jQuery("#thumb").show();
								jQuery("#thumb-img").attr("src",result);

								curr = curr + 1;
								regenItem();
							}
						});
					}

					regenItem();
				},
				error: function(request, status, error) {
					setMessage("Error " + request.status);
				}
			});
		}

		// ]]>
		</script>

		<form method="post" action="" style="display:inline; float:left; padding-right:30px;">
		    <h4>Select which thumbnails you want to rebuild:</h4>
			<p>

			<?php
			global $_wp_additional_image_sizes;

			foreach ( get_intermediate_image_sizes() as $s ):

				if ( isset( $_wp_additional_image_sizes[$s]['width'] ) ) // For theme-added sizes
					$width = intval( $_wp_additional_image_sizes[$s]['width'] );
				else                                                     // For default sizes set in options
					$width = get_option( "{$s}_size_w" );

				if ( isset( $_wp_additional_image_sizes[$s]['height'] ) ) // For theme-added sizes
					$height = intval( $_wp_additional_image_sizes[$s]['height'] );
				else                                                      // For default sizes set in options
					$height = get_option( "{$s}_size_h" );

				if ( isset( $_wp_additional_image_sizes[$s]['crop'] ) )   // For theme-added sizes
					$crop = intval( $_wp_additional_image_sizes[$s]['crop'] );
				else                                                      // For default sizes set in options
					$crop = get_option( "{$s}_crop" );
			?>

				<input type="checkbox" name="thumbnails[]" checked="checked" value="<?php echo $s ?>" />
				<label>
					<em><?php echo $s ?></em> (width : <?php echo $width ?>, height : <?php echo $height ?>, crop : <?php echo $crop ?>)
				</label>
				<br/>
			<?php endforeach;?>
			</p>
			<p>
				<input type="checkbox" id="onlypostthumbs" name="onlypostthumbs" />
				<label>Only rebuild post thumbnails</label>
			</p>

			<input type="button" onClick="javascript:regenerate();" class="button"
			       name="ajax_thumbnail_rebuild" id="ajax_thumbnail_rebuild"
			       value="<?php _e( 'Regenerate All Thumbnails', 'ajax-thumbnail-rebuild' ) ?>" />
			<br />
		</form>

		<div id="thumb" style="display:none;"><h4>Last image:</h4><img id="thumb-img" /></div>

		<p style="clear:both; padding-top:2em;">
		If you find this plugin useful, I'd be happy to read your comments on
		the <a href="http://breiti.cc/wordpress/ajax-thumbnail-rebuild" target="_blank">plugin homepage</a>.<br />
		If you experience any problems, feel free to leave a comment too.
		</p>

		<?php
	}

};

function ajax_thumbnail_rebuild_ajax() {
	global $wpdb;

	$action = $_POST["do"];
	$thumbnails = isset( $_POST['thumbnails'] )? $_POST['thumbnails'] : NULL;
	$onlypostthumbs = isset( $_POST['onlypostthumbs'] ) ? $_POST['onlypostthumbs'] : 0;

	if ($action == "getlist") {

		if ($onlypostthumbs) {
			/* Get all featured images */
			$featured_images = $wpdb->get_results( "SELECT meta_value FROM {$wpdb->postmeta}
		                                        WHERE meta_key = '_thumbnail_id'" );

			$thumbs = array();
			foreach($featured_images as $image) {
				array_push($thumbs, $image->meta_value);
			}
			$attachments =& get_children( array(
				'post_type' => 'attachment',
				'post_mime_type' => 'image',
				'numberposts' => -1,
				'post_status' => null,
				'post_in' => $thumbs,
				'output' => 'object',
			) );

		} else {
			$attachments =& get_children( array(
				'post_type' => 'attachment',
				'post_mime_type' => 'image',
				'numberposts' => -1,
				'post_status' => null,
				'post_parent' => null, // any parent
				'output' => 'object',
			) );
		}

		foreach ( $attachments as $attachment ) {
			$res[] = array('id' => $attachment->ID, 'title' => $attachment->post_title);
		}
		die( json_encode($res) );
	} else if ($action == "regen") {
		$id = $_POST["id"];

		$fullsizepath = get_attached_file( $id );

		if ( FALSE !== $fullsizepath && @file_exists($fullsizepath) ) {
			set_time_limit( 30 );
			wp_update_attachment_metadata( $id, wp_generate_attachment_metadata_custom( $id, $fullsizepath, $thumbnails ) );
		}

		die( wp_get_attachment_thumb_url( $id ));
	}
}
add_action('wp_ajax_ajax_thumbnail_rebuild', 'ajax_thumbnail_rebuild_ajax');

add_action( 'plugins_loaded', create_function( '', 'global $AjaxThumbnailRebuild; $AjaxThumbnailRebuild = new AjaxThumbnailRebuild();' ) );


/**
 * Generate post thumbnail attachment meta data.
 *
 * @since 2.1.0
 *
 * @param int $attachment_id Attachment Id to process.
 * @param string $file Filepath of the Attached image.
 * @return mixed Metadata for attachment.
 */
function wp_generate_attachment_metadata_custom( $attachment_id, $file, $thumbnails = NULL ) {
	$attachment = get_post( $attachment_id );

	$metadata = array();
	if ( preg_match('!^image/!', get_post_mime_type( $attachment )) && file_is_displayable_image($file) ) {
		$imagesize = getimagesize( $file );
		$metadata['width'] = $imagesize[0];
		$metadata['height'] = $imagesize[1];
		list($uwidth, $uheight) = wp_constrain_dimensions($metadata['width'], $metadata['height'], 128, 96);
		$metadata['hwstring_small'] = "height='$uheight' width='$uwidth'";

		// Make the file path relative to the upload dir
		$metadata['file'] = _wp_relative_upload_path($file);

		// make thumbnails and other intermediate sizes
		global $_wp_additional_image_sizes;

		foreach ( get_intermediate_image_sizes() as $s ) {
			$sizes[$s] = array( 'width' => '', 'height' => '', 'crop' => FALSE );
			if ( isset( $_wp_additional_image_sizes[$s]['width'] ) )
				$sizes[$s]['width'] = intval( $_wp_additional_image_sizes[$s]['width'] ); // For theme-added sizes
			else
				$sizes[$s]['width'] = get_option( "{$s}_size_w" ); // For default sizes set in options
			if ( isset( $_wp_additional_image_sizes[$s]['height'] ) )
				$sizes[$s]['height'] = intval( $_wp_additional_image_sizes[$s]['height'] ); // For theme-added sizes
			else
				$sizes[$s]['height'] = get_option( "{$s}_size_h" ); // For default sizes set in options
			if ( isset( $_wp_additional_image_sizes[$s]['crop'] ) )
				$sizes[$s]['crop'] = intval( $_wp_additional_image_sizes[$s]['crop'] ); // For theme-added sizes
			else
				$sizes[$s]['crop'] = get_option( "{$s}_crop" ); // For default sizes set in options
		}

		$sizes = apply_filters( 'intermediate_image_sizes_advanced', $sizes );

		foreach ($sizes as $size => $size_data ) {
			if( isset( $thumbnails ) )
				if( !in_array( $size, $thumbnails ) )
					continue;

			$resized = image_make_intermediate_size( $file, $size_data['width'], $size_data['height'], $size_data['crop'] );

			if ( $resized )
				$metadata['sizes'][$size] = $resized;
		}

		// fetch additional metadata from exif/iptc
		$image_meta = wp_read_image_metadata( $file );
		if ( $image_meta )
			$metadata['image_meta'] = $image_meta;

	}

	return apply_filters( 'wp_generate_attachment_metadata', $metadata, $attachment_id );
}

?>
