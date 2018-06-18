<?php
/*
 * Plugin Name: AJAX Thumbnail Regeneration
 * Plugin URI: https://wordpress.org/plugins/ajax-thumbnail-rebuild/
 * Description: AJAX Thumbnail Regeneration allows you to rebuild all thumbnails on your site.
 * Version: 2.0
 * Author: Konekt OÜ
 * Author URI: https://www.konekt.ee
 * Developer: Risto Niinemets
 * Developer URI: https://profiles.wordpress.org/ristoniinemets
 * Text Domain: ajax-thumbnail-rebuild
 * Domain Path: /languages
 * Tested up to: 4.9.6
 * Requires PHP: 5.6
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Security check
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ajax_Thumbnail_Rebuild {
	/**
	 * Instance
	 *
	 * @var null
	 */
	private static $instance = null;

	/**
	 * Plugin version
	 */
	const VERSION = '2.0';

	public function __construct() {
		$this->add_hooks();
	}

	public function add_hooks() {
		add_action( 'admin_init',                     array( $this, 'admin_init' ) );
		add_action( 'admin_enqueue_scripts',          array( $this, 'admin_enqueue_scripts' ) );
		add_action( 'admin_menu',                     array( $this, 'add_admin_menu' ) );
		add_action( 'attachment_fields_to_edit',      array( $this, 'add_single_attachment_regenerate_button' ), 10, 2 );

		add_action( 'wp_ajax_ajax_thumbnail_rebuild', array( $this, 'ajax_generate_attachment_thumbnails' ) );
	}

	public function admin_init() {
		// Load translations
		load_plugin_textdomain( 'ajax-thumbnail-rebuild', false, basename( dirname( __FILE__ ) ) . '/languages' );
	}

	public function admin_enqueue_scripts() {
		wp_enqueue_script( 'ajax-thumbnail-rebuild/js', plugins_url( 'assets/js/ajax-thumbnail-rebuild.js', __FILE__ ), [ 'jquery' ], self::VERSION, true );
		wp_enqueue_style( 'ajax-thumbnail-rebuild/css', plugins_url( 'assets/css/ajax-thumbnail-rebuild.css', __FILE__ ), null, self::VERSION );
	}

	public function add_admin_menu() {
		add_management_page(
			__( 'Regenerate all thumbnails', 'ajax-thumbnail-rebuild' ), // Page title
			__( 'Regenerate thumbnails', 'ajax-thumbnail-rebuild' ),     // Menu title
			'manage_options',                                            // Required capabilities
			'ajax-thumbnail-rebuild',                                    // Menu slug
			array( $this, 'admin_page' )                                 // Callback
		);
	}

	public function admin_page() {

	}

	public function add_single_attachment_regenerate_button( $form_fields, $post ) {
		// Add new field
		$form_fields[] = [
			'label' => esc_html__( 'Thumbnails', 'ajax-thumbnail-rebuild' ),
			'input' => 'html',
			'html'  => sprintf( '<button class="button thumbnail-rebuild__generate-button thumbnail-rebuild__generate-button--single" data-attachment-id="%d" data-nonce="%s">%s</button>', esc_attr( $post->ID ), esc_attr( wp_create_nonce( 'ajax-thumbnail-rebuild-generate' ) ), esc_html__( 'Regenerate thumbnails', 'ajax-thumbnail-rebuild' ) )
		];

		return $form_fields;
	}

	public function ajax_generate_attachment_thumbnails() {
		check_ajax_referer( 'ajax-thumbnail-rebuild-generate' );

		// Get attachment ID
		$attachment_id = isset( $_POST['attachment_id'] ) ? intval( $_POST['attachment_id'] ) : null;

		if ( null !== $attachment_id && 0 < $attachment_id ) {
			if ( 'attachment' === get_post_type( $attachment_id ) ) {
				$result = $this->generate_attachment_thumbnails( $attachment_id );

				wp_send_json_success( $result );
			}
		}

		wp_send_json_error( [ 'error' => 'invalid_attachment_id' ] );
	}

	public function generate_attachment_thumbnails( $attachment_id ) {
		return true;
	}

	/**
	 * Fetch instance of this plugin
	 *
	 * @return Ajax_Thumbnail_Rebuild
	 */
	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self;
		}

		return self::$instance;
	}
}
/**
 * Returns the main instance of Ajax_Thumbnail_Rebuild to prevent the need to use globals.
 * @return Ajax_Thumbnail_Rebuild
 */
function ajax_thumbnail_build() {
	return Ajax_Thumbnail_Rebuild::instance();
}

// Global for backwards compatibility.
$GLOBALS['ajax_thumbnail_rebuild'] = ajax_thumbnail_build();
