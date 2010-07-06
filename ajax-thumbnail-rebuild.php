<?php
/* Plugin name: AJAX Thumbnail Rebuild
   Plugin URI: http://breiti.cc/wordpress/ajax-thumbnail-rebuild
   Author: junkcoder
   Author URI: http://breiti.cc
   Version: 1.01
   Description: Rebuild all thumbnails
   Max WP Version: 3.0

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
		<div id="thumb" style="display:none">Last thumbnail: <img id="thumb-img" /></div>
		<script type="text/javascript">
		// <![CDATA[

		function setMessage(msg) {
			jQuery("#message").html(msg);
			jQuery("#message").show();
		}

		function regenerate() {
			jQuery("#ajax_thumbnail_rebuild").attr("disabled", true);
			setMessage("<p>Reading attachments...</p>");
			jQuery.ajax({
				url: "<?php bloginfo('wpurl'); ?>/wp-admin/admin-ajax.php", 
				type: "POST",
				data: "action=ajax_thumbnail_rebuild&do=getlist",
				success: function(result) {
					var list = eval(result);
					var curr = 0;

					function regenItem() {
						if (curr >= list.length) {
							jQuery("#ajax_thumbnail_rebuild").removeAttr("disabled");
							jQuery("#thumb").hide();
							setMessage("Done.");
							return;
						}
						setMessage("Regenerating " + (curr+1) + " of " + list.length + " (" + list[curr].title + ")...");

						jQuery.ajax({
							url: "<?php bloginfo('wpurl'); ?>/wp-admin/admin-ajax.php",
							type: "POST",
							data: "action=ajax_thumbnail_rebuild&do=regen&id=" + list[curr].id,
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

		<form method="post" action="">
			<p>
				<input type="button" onClick="javascript:regenerate();" class="button" name="ajax_thumbnail_rebuild" id="ajax_thumbnail_rebuild"
					 value="<?php _e( 'Regenerate All Thumbnails', 'ajax-thumbnail-rebuild' ) ?>" />
			</p>
		</form>
		<p>If you find this plugin useful, I'd be happy to read your comments on the <a href="http://breiti.cc/wordpress/ajax-thumbnail-rebuild" target="_blank">plugin homepage</a>.<br />If you experience any problems, feel free to leave a comment too.</p>
		<?php
	}

};

add_action('wp_ajax_ajax_thumbnail_rebuild', ajax_thumbnail_rebuild_ajax);

function ajax_thumbnail_rebuild_ajax() {
	$action = $_POST["do"];

	if ($action == "getlist") {
		$attachments =& get_children( array(
			'post_type' => 'attachment',
			'post_mime_type' => 'image',
			'numberposts' => -1,
			'post_status' => null,
			'post_parent' => null, // any parent
			'output' => 'object',
		) );

		foreach ( $attachments as $attachment ) {
			$res[] = array('id' => $attachment->ID, 'title' => $attachment->post_title);
		}
		die( json_encode($res) );
	} else if ($action == "regen") {
		$id = $_POST["id"];

		$fullsizepath = get_attached_file( $id );

		if ( FALSE !== $fullsizepath && @file_exists($fullsizepath) ) {
			set_time_limit( 30 );
			wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $fullsizepath ) );
		}

		die( wp_get_attachment_thumb_url( $id ));
	}
}

add_action( 'plugins_loaded', create_function( '', 'global $AjaxThumbnailRebuild; $AjaxThumbnailRebuild = new AjaxThumbnailRebuild();' ) );
?>
