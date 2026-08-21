<?php
/**
 * Rebuilding from the media library list.
 *
 * A row action for one image, and a bulk action that hands the selection to the
 * plugin's own screen - which already rebuilds one image per request, with a
 * progress bar and a Stop button, rather than trying to do the lot inside the
 * request that submitted the form.
 *
 * @package ajax-thumbnail-rebuild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATR_Media_Library {

	/** Bulk action names, and the query arguments the selection travels in. */
	const BULK_ACTION = 'atr_rebuild';

	const BULK_ACTION_OPTIMIZE = 'atr_optimize';

	public function register(): void {
		add_filter( 'media_row_actions', array( $this, 'add_row_action' ), 10, 2 );
		add_filter( 'bulk_actions-upload', array( $this, 'add_bulk_action' ) );
		add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk_action' ), 10, 3 );
	}

	/**
	 * "Rebuild thumbnails" beside Edit and Delete in the media list.
	 *
	 * @param array   $actions Row actions.
	 * @param WP_Post $post    Attachment.
	 * @return array
	 */
	public function add_row_action( $actions, $post ) {
		if ( ! is_array( $actions ) || ! current_user_can( ATR_Plugin::capability() ) || ! wp_attachment_is_image( $post ) ) {
			return $actions;
		}

		/* The array key becomes the class of the span WordPress wraps this in, so it
		   must not be the class the script listens on. */
		$actions['atr-rebuild'] = sprintf(
			'<button type="button" class="button-link atr-rebuild-button" data-id="%1$d">%2$s</button> <span class="atr-rebuild-message" aria-live="polite"></span>',
			(int) $post->ID,
			esc_html__( 'Rebuild thumbnails', 'ajax-thumbnail-rebuild' )
		);

		$actions['atr-optimize'] = sprintf(
			'<button type="button" class="button-link atr-rebuild-button" data-id="%1$d" data-mode="optimize">%2$s</button> <span class="atr-rebuild-message" aria-live="polite"></span>',
			(int) $post->ID,
			esc_html__( 'Optimise', 'ajax-thumbnail-rebuild' )
		);

		return $actions;
	}

	/**
	 * @param array $actions Bulk actions.
	 * @return array
	 */
	public function add_bulk_action( $actions ) {
		if ( ! is_array( $actions ) || ! current_user_can( ATR_Plugin::capability() ) ) {
			return $actions;
		}

		$actions[ self::BULK_ACTION ]          = __( 'Rebuild thumbnails', 'ajax-thumbnail-rebuild' );
		$actions[ self::BULK_ACTION_OPTIMIZE ] = __( 'Optimise images', 'ajax-thumbnail-rebuild' );

		return $actions;
	}

	/**
	 * Send the selection to the plugin's screen instead of rebuilding here.
	 *
	 * Resizing dozens of images inside the form submission is exactly the timeout
	 * this plugin exists to avoid.
	 *
	 * @param string $redirect Where the list table is about to go.
	 * @param string $action   Bulk action chosen.
	 * @param array  $ids      Selected attachment ids.
	 * @return string
	 */
	public function handle_bulk_action( $redirect, $action, $ids ) {
		if ( ! in_array( $action, array( self::BULK_ACTION, self::BULK_ACTION_OPTIMIZE ), true ) || ! current_user_can( ATR_Plugin::capability() ) ) {
			return $redirect;
		}

		$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );

		if ( ! $ids ) {
			return $redirect;
		}

		return add_query_arg(
			array(
				'ids'  => implode( ',', $ids ),
				'mode' => self::BULK_ACTION_OPTIMIZE === $action ? 'optimize' : 'rebuild',
			),
			ATR_Admin_Page::url( 'rebuild' )
		);
	}

	/**
	 * The attachment ids the Rebuild tab was asked to work on, if any.
	 *
	 * @return int[]
	 */
	public static function requested_ids(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list of ids to offer, nothing is changed.
		$raw = isset( $_GET['ids'] ) ? sanitize_text_field( wp_unslash( $_GET['ids'] ) ) : '';

		if ( '' === $raw ) {
			return array();
		}

		return array_values( array_unique( array_filter( array_map( 'absint', explode( ',', $raw ) ) ) ) );
	}

	/**
	 * Which job the Rebuild tab was sent here to do.
	 */
	public static function requested_mode(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- picks a radio button, nothing is changed.
		$mode = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : '';

		return 'optimize' === $mode ? 'optimize' : 'rebuild';
	}
}
