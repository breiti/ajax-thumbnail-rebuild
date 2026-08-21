<?php
/**
 * REST API endpoints the admin screen talks to.
 *
 * @package ajax-thumbnail-rebuild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATR_Rest_Controller {

	const REST_NAMESPACE = 'ajax-thumbnail-rebuild/v1';

	public function register_routes(): void {
		register_rest_route( self::REST_NAMESPACE, '/sizes', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_sizes' ),
				'permission_callback' => array( $this, 'check_permission' ),
			),
		) );

		register_rest_route( self::REST_NAMESPACE, '/attachments', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_attachments' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'only_featured' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'filename'      => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'include'       => array(
						'type'        => 'array',
						'default'     => array(),
						'items'       => array( 'type' => 'integer' ),
						'description' => 'Attachment ids to limit the list to.',
					),
				),
			),
		) );

		register_rest_route( self::REST_NAMESPACE, '/rebuild', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rebuild' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id'    => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
					'sizes' => array(
						'type'        => 'array',
						'default'     => array(),
						'items'       => array( 'type' => 'string' ),
						'description' => 'Size names to rebuild. Empty means every registered size.',
					),
				),
			),
		) );

		register_rest_route( self::REST_NAMESPACE, '/optimize', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'optimize' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
				),
			),
		) );
	}

	/**
	 * Only users who may manage the site may burn its CPU on resizing.
	 *
	 * @return true|WP_Error
	 */
	public function check_permission() {
		if ( current_user_can( ATR_Plugin::capability() ) ) {
			return true;
		}

		return new WP_Error(
			'atr_forbidden',
			__( 'You are not allowed to rebuild thumbnails.', 'ajax-thumbnail-rebuild' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	public function get_sizes(): WP_REST_Response {
		$sizes = array();

		foreach ( ATR_Image_Sizes::all() as $size ) {
			$sizes[] = array(
				'name'       => $size['name'],
				'width'      => $size['width'],
				'height'     => $size['height'],
				'dimensions' => ATR_Image_Sizes::dimensions_label( $size ),
				'crop'       => ATR_Image_Sizes::crop_label( $size ),
			);
		}

		return rest_ensure_response( $sizes );
	}

	public function get_attachments( WP_REST_Request $request ): WP_REST_Response {
		$items = ATR_Attachments::query( array(
			'only_featured' => (bool) $request['only_featured'],
			'filename'      => (string) $request['filename'],
			'include'       => (array) $request['include'],
		) );

		return rest_ensure_response( array(
			'total' => count( $items ),
			'items' => $items,
		) );
	}

	/**
	 * Re-encode an attachment's files without resizing anything.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function optimize( WP_REST_Request $request ) {
		$id = (int) $request['id'];

		if ( 'attachment' !== get_post_type( $id ) ) {
			return new WP_Error(
				'atr_not_found',
				__( 'That attachment does not exist.', 'ajax-thumbnail-rebuild' ),
				array( 'status' => 404 )
			);
		}

		$result = ATR_Optimizer::optimize_attachment( $id );

		if ( '' === $result['message'] ) {
			wp_update_attachment_metadata( $id, $result['metadata'] );
		}

		return rest_ensure_response( array(
			'id'      => $id,
			'skipped' => '' !== $result['message'],
			'message' => $result['message'],
			'saved'   => (int) $result['saved'],
			'files'   => (int) $result['files'],
		) );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function rebuild( WP_REST_Request $request ) {
		$id = (int) $request['id'];

		if ( 'attachment' !== get_post_type( $id ) ) {
			return new WP_Error(
				'atr_not_found',
				__( 'That attachment does not exist.', 'ajax-thumbnail-rebuild' ),
				array( 'status' => 404 )
			);
		}

		$sizes = array_values( array_filter( array_map( 'sanitize_text_field', (array) $request['sizes'] ) ) );

		/* An empty selection means "every registered size", which is what the button on a
		   single attachment sends. Passing the empty array through as a filter would
		   rebuild nothing at all. */
		$result = ATR_Regenerator::rebuild( $id, $sizes ?: null );

		$result['id'] = $id;

		return rest_ensure_response( $result );
	}
}
