<?php
/**
 * Wiring: hooks, assets, and the pieces they belong to.
 *
 * @package ajax-thumbnail-rebuild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATR_Plugin {

	/**
	 * Capability required to rebuild thumbnails, for every part of the plugin.
	 *
	 * Filterable so a site can hand the screen to another role.
	 */
	public static function capability(): string {
		return (string) apply_filters( 'ajax_thumbnail_rebuild_capability', 'manage_options' );
	}

	private ATR_Admin_Page $admin_page;

	private ATR_Rest_Controller $rest;

	private ATR_Image_Proxy $proxy;

	private ATR_On_Demand $on_demand;

	private ATR_Media_Library $media_library;

	private ATR_Uploads $uploads;

	private ATR_Optimizer $optimizer;

	public function __construct() {
		$this->admin_page = new ATR_Admin_Page();
		$this->rest       = new ATR_Rest_Controller();
		$this->proxy      = new ATR_Image_Proxy();
		$this->on_demand  = new ATR_On_Demand();

		$this->media_library = new ATR_Media_Library();
		$this->uploads       = new ATR_Uploads();
		$this->optimizer     = new ATR_Optimizer();
	}

	public function boot(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this->proxy, 'register' ) );
		add_action( 'init', array( ATR_Copies::class, 'register' ) );
		add_action( 'init', array( $this->on_demand, 'register' ) );
		add_action( 'init', array( $this->uploads, 'register' ) );
		add_action( 'init', array( $this->optimizer, 'register' ) );
		add_action( 'admin_init', array( ATR_Settings::class, 'register' ) );
		add_action( 'rest_api_init', array( $this->rest, 'register_routes' ) );
		add_action( 'admin_menu', array( $this->admin_page, 'register' ) );
		add_action( 'admin_init', array( $this->media_library, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );
		add_action( 'wp_enqueue_media', array( $this, 'enqueue_attachment_button' ) );
		add_filter( 'attachment_fields_to_edit', array( $this, 'add_attachment_button' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( ATR_FILE ), array( $this, 'add_action_link' ) );
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'ajax-thumbnail-rebuild', false, dirname( plugin_basename( ATR_FILE ) ) . '/languages' );
	}

	public function admin_page(): ATR_Admin_Page {
		return $this->admin_page;
	}

	/**
	 * Assets for the tools screen, and for wherever an attachment can be edited.
	 *
	 * @param string $hook_suffix Current admin screen.
	 */
	public function enqueue_admin( string $hook_suffix ): void {
		if ( $hook_suffix === $this->admin_page->hook_suffix() ) {
			$this->enqueue_page();
			return;
		}

		if ( in_array( $hook_suffix, array( 'upload.php', 'post.php', 'post-new.php' ), true ) ) {
			$this->enqueue_attachment_button();
		}
	}

	/**
	 * The version an asset is served under.
	 *
	 * The plugin version alone is not enough: a stylesheet edited between two
	 * releases keeps the same URL, and browsers - and caches in front of the
	 * site - go on serving what they already have. The file's own timestamp
	 * changes whenever the file does.
	 *
	 * @param string $relative Path inside the plugin.
	 */
	private function asset_version( string $relative ): string {
		$path = ATR_PATH . $relative;

		return file_exists( $path ) ? ATR_VERSION . '.' . filemtime( $path ) : ATR_VERSION;
	}

	private function enqueue_page(): void {
		wp_enqueue_style(
			'ajax-thumbnail-rebuild',
			ATR_URL . 'assets/css/admin.css',
			array( 'common' ),
			$this->asset_version( 'assets/css/admin.css' )
		);

		wp_enqueue_script(
			'ajax-thumbnail-rebuild',
			ATR_URL . 'assets/js/admin.js',
			array( 'wp-api-fetch', 'wp-i18n' ),
			$this->asset_version( 'assets/js/admin.js' ),
			true
		);

		wp_localize_script( 'ajax-thumbnail-rebuild', 'ATRSettings', array(
			'editLink' => admin_url( 'post.php?post=%d&action=edit' ),
			// Set when the media library sent a selection over; empty means the whole library.
			'include'  => ATR_Media_Library::requested_ids(),
		) );

		wp_enqueue_script(
			'ajax-thumbnail-rebuild-cleanup',
			ATR_URL . 'assets/js/cleanup.js',
			array( 'wp-api-fetch', 'wp-i18n' ),
			$this->asset_version( 'assets/js/cleanup.js' ),
			true
		);

		wp_set_script_translations( 'ajax-thumbnail-rebuild', 'ajax-thumbnail-rebuild', ATR_PATH . 'languages' );
		wp_set_script_translations( 'ajax-thumbnail-rebuild-cleanup', 'ajax-thumbnail-rebuild', ATR_PATH . 'languages' );
	}

	/**
	 * The rebuild button shown on a single attachment, in the list and in the modal.
	 */
	public function enqueue_attachment_button(): void {
		if ( ! current_user_can( self::capability() ) || wp_script_is( 'ajax-thumbnail-rebuild-attachment', 'enqueued' ) ) {
			return;
		}

		wp_enqueue_script(
			'ajax-thumbnail-rebuild-attachment',
			ATR_URL . 'assets/js/attachment.js',
			array( 'wp-api-fetch', 'wp-i18n' ),
			$this->asset_version( 'assets/js/attachment.js' ),
			true
		);

		wp_set_script_translations( 'ajax-thumbnail-rebuild-attachment', 'ajax-thumbnail-rebuild', ATR_PATH . 'languages' );
	}

	/**
	 * Add the rebuild button to the attachment details.
	 *
	 * @param array   $fields Attachment fields.
	 * @param WP_Post $post   Attachment.
	 * @return array
	 */
	public function add_attachment_button( $fields, $post ) {
		if ( ! is_array( $fields ) || ! current_user_can( self::capability() ) || ! wp_attachment_is_image( $post ) ) {
			return $fields;
		}

		$this->enqueue_attachment_button();

		/* The id lives in a data attribute and the message next to the button, so several
		   attachments on one screen no longer share one element id between them. */
		$fields['ajax-thumbnail-rebuild'] = array(
			'label' => __( 'Images', 'ajax-thumbnail-rebuild' ),
			'input' => 'html',
			'html'  => sprintf(
				'<button type="button" class="button atr-rebuild-button" data-id="%1$d">%2$s</button> '
				. '<button type="button" class="button atr-rebuild-button" data-id="%1$d" data-mode="optimize">%3$s</button> '
				. '<span class="atr-rebuild-message" aria-live="polite"></span>',
				(int) $post->ID,
				esc_html__( 'Rebuild sizes', 'ajax-thumbnail-rebuild' ),
				esc_html__( 'Optimise', 'ajax-thumbnail-rebuild' )
			),
		);

		/* Replacing keeps the id and the URL, so everything already pointing at this
		   image goes on pointing at it and simply shows the new one. */
		$fields['ajax-thumbnail-rebuild-replace'] = array(
			'label' => __( 'Replace file', 'ajax-thumbnail-rebuild' ),
			'input' => 'html',
			'html'  => sprintf(
				'<input type="file" class="atr-replace-file" accept="image/*" data-id="%1$d" /> '
				. '<button type="button" class="button atr-replace-button" data-id="%1$d">%2$s</button>'
				. '<span class="atr-replace-message" aria-live="polite"></span>'
				. '<p class="description">%3$s</p>',
				(int) $post->ID,
				esc_html__( 'Replace', 'ajax-thumbnail-rebuild' ),
				esc_html__( 'The new file has to be the same kind of image, because the name and the address stay as they are - which is what keeps every link to this image working.', 'ajax-thumbnail-rebuild' )
			),
		);

		return $fields;
	}

	/**
	 * Link straight to the screen from the plugins list.
	 *
	 * @param array $links Action links.
	 * @return array
	 */
	public function add_action_link( $links ) {
		if ( is_array( $links ) && current_user_can( self::capability() ) ) {
			array_unshift( $links, sprintf(
				'<a href="%s">%s</a>',
				esc_url( ATR_Admin_Page::url() ),
				esc_html__( 'Rebuild thumbnails', 'ajax-thumbnail-rebuild' )
			) );
		}

		return $links;
	}
}
