<?php
/**
 * Optimising through a service rather than on this server.
 *
 * TinyPNG and ShortPixel do what jpegoptim and pngquant do, only better and on
 * somebody else's machine - which is the answer for the hosts that have no
 * optimiser installed and will not let PHP run one.
 *
 * The trade is real and worth saying out loud: every file goes to a third party
 * and every file counts against a monthly allowance. Nothing here is used
 * unless a site has entered a key.
 *
 * @package ajax-thumbnail-rebuild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATR_Service {

	/** Where the count of what has been used is kept. */
	const USAGE_OPTION = 'ajax_thumbnail_rebuild_service_usage';

	/** Files larger than this are not sent; both services refuse them anyway. */
	const MAX_BYTES = 5242880;

	/**
	 * The services this knows how to talk to.
	 *
	 * @return array<string,array{label:string,endpoint:string,lossy_only:bool,mimes:array}>
	 */
	public static function services(): array {
		return array(
			'tinypng'    => array(
				'label'      => 'TinyPNG',
				'endpoint'   => 'https://api.tinify.com/shrink',
				// TinyPNG has one mode, and it is a lossy one.
				'lossy_only' => true,
				'mimes'      => array( 'image/jpeg', 'image/png', 'image/webp' ),
			),
			'shortpixel' => array(
				'label'      => 'ShortPixel',
				'endpoint'   => 'https://api.shortpixel.com/v2/post-reducer.php',
				'lossy_only' => false,
				'mimes'      => array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ),
			),
		);
	}

	/**
	 * Which service a site has set up, if any.
	 *
	 * @return string Empty when none is configured.
	 */
	public static function chosen(): string {
		$service = (string) ATR_Settings::get( 'optimize_service', 'none' );
		$key     = trim( (string) ATR_Settings::get( 'optimize_service_key' ) );

		if ( ! isset( self::services()[ $service ] ) || '' === $key ) {
			return '';
		}

		/* TinyPNG only compresses one way. A site that has said it does not want
		   lossy compression has said it about this too. */
		if ( ! empty( self::services()[ $service ]['lossy_only'] ) && ! ATR_Settings::get( 'optimize_lossy' ) ) {
			return '';
		}

		return $service;
	}

	/**
	 * Whether the chosen service will look at this format.
	 *
	 * @param string $mime Mime type.
	 */
	public static function handles( string $mime ): bool {
		$service = self::chosen();

		return '' !== $service && in_array( $mime, self::services()[ $service ]['mimes'], true );
	}

	/**
	 * Send one file away and bring back what came home.
	 *
	 * @param string $path Absolute path of the file to optimise.
	 * @param string $mime Its mime type.
	 * @param string $into Where to write the answer.
	 * @return true|WP_Error Whether $into now holds an optimised copy.
	 */
	public static function optimize( string $path, string $mime, string $into ) {
		$service = self::chosen();

		if ( '' === $service ) {
			return new WP_Error( 'atr_service_none', __( 'No optimisation service is set up.', 'ajax-thumbnail-rebuild' ) );
		}

		if ( ! self::handles( $mime ) ) {
			return new WP_Error( 'atr_service_mime', __( 'The service does not handle this kind of file.', 'ajax-thumbnail-rebuild' ) );
		}

		clearstatcache( true, $path );

		if ( filesize( $path ) > self::max_bytes() ) {
			return new WP_Error( 'atr_service_large', __( 'The file is larger than the service accepts.', 'ajax-thumbnail-rebuild' ) );
		}

		$url = 'tinypng' === $service
			? self::send_to_tinypng( $path )
			: self::send_to_shortpixel( $path, $mime );

		if ( is_wp_error( $url ) ) {
			return $url;
		}

		return self::download( $url, $into, $service );
	}

	/**
	 * @param string $path File to send.
	 * @return string|WP_Error URL of the optimised copy.
	 */
	private static function send_to_tinypng( string $path ) {
		$response = wp_remote_post( self::endpoint( 'tinypng' ), array(
			'timeout' => self::timeout(),
			'headers' => array(
				// The documented form: the literal word "api" as the user name.
				'Authorization' => 'Basic ' . base64_encode( 'api:' . self::key() ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				'Content-Type'  => 'application/octet-stream',
			),
			'body'    => file_get_contents( $path ), // phpcs:ignore WordPress.WP.AlternativeFunctions
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		// The service says how much of the month's allowance has gone.
		$used = wp_remote_retrieve_header( $response, 'compression-count' );

		if ( '' !== $used ) {
			self::record_usage( (int) $used );
		}

		if ( 401 === $code ) {
			return new WP_Error( 'atr_service_key', __( 'TinyPNG did not accept that API key.', 'ajax-thumbnail-rebuild' ) );
		}

		if ( 429 === $code ) {
			return new WP_Error( 'atr_service_quota', __( 'The TinyPNG allowance for this month is used up.', 'ajax-thumbnail-rebuild' ) );
		}

		if ( $code < 200 || $code > 299 || empty( $body['output']['url'] ) ) {
			return new WP_Error(
				'atr_service_failed',
				sprintf(
					/* translators: 1: service name, 2: what it said. */
					__( '%1$s answered: %2$s', 'ajax-thumbnail-rebuild' ),
					'TinyPNG',
					isset( $body['message'] ) ? (string) $body['message'] : (string) $code
				)
			);
		}

		return (string) $body['output']['url'];
	}

	/**
	 * @param string $path File to send.
	 * @param string $mime Its mime type.
	 * @return string|WP_Error URL of the optimised copy.
	 */
	private static function send_to_shortpixel( string $path, string $mime ) {
		$fields = array(
			'key'            => self::key(),
			'plugin_version' => 'ATR' . ATR_VERSION,
			// 1 lossy, 0 lossless: the site's own setting decides which.
			'lossy'          => ATR_Settings::get( 'optimize_lossy' ) ? '1' : '0',
			'wait'           => (string) min( 30, self::timeout() - 5 ),
			'convertto'      => '',
			'file_paths'     => wp_json_encode( array( 'file1' => $path ) ),
		);

		$body = self::multipart( $fields, 'file1', $path, $mime, $boundary );

		$response = wp_remote_post( self::endpoint( 'shortpixel' ), array(
			'timeout' => self::timeout(),
			'headers' => array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ),
			'body'    => $body,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code   = (int) wp_remote_retrieve_response_code( $response );
		$answer = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code > 299 || ! is_array( $answer ) ) {
			return new WP_Error(
				'atr_service_failed',
				sprintf(
					/* translators: 1: service name, 2: what it said. */
					__( '%1$s answered: %2$s', 'ajax-thumbnail-rebuild' ),
					'ShortPixel',
					(string) $code
				)
			);
		}

		$first  = isset( $answer[0] ) ? (array) $answer[0] : $answer;
		$status = isset( $first['Status'] ) ? (array) $first['Status'] : array();
		$result = ATR_Settings::get( 'optimize_lossy' ) ? 'LossyURL' : 'LosslessURL';

		if ( 2 !== (int) ( $status['Code'] ?? 0 ) || empty( $first[ $result ] ) ) {
			return new WP_Error(
				'atr_service_failed',
				sprintf(
					/* translators: 1: service name, 2: what it said. */
					__( '%1$s answered: %2$s', 'ajax-thumbnail-rebuild' ),
					'ShortPixel',
					(string) ( $status['Message'] ?? __( 'no optimised file came back', 'ajax-thumbnail-rebuild' ) )
				)
			);
		}

		if ( isset( $first['OriginalSize'], $first['LossySize'] ) ) {
			self::record_usage( self::usage() + 1 );
		}

		return (string) $first[ $result ];
	}

	/**
	 * A multipart body, because the HTTP API does not build one.
	 *
	 * @param array       $fields   Plain fields.
	 * @param string      $name     Field name for the file.
	 * @param string      $path     File to attach.
	 * @param string      $mime     Its mime type.
	 * @param string|null $boundary Set to the boundary that was used.
	 * @return string
	 */
	private static function multipart( array $fields, string $name, string $path, string $mime, &$boundary ): string {
		$boundary = 'atr' . md5( $path . microtime() );
		$body     = '';

		foreach ( $fields as $field => $value ) {
			$body .= '--' . $boundary . "\r\n";
			$body .= 'Content-Disposition: form-data; name="' . $field . '"' . "\r\n\r\n";
			$body .= $value . "\r\n";
		}

		$body .= '--' . $boundary . "\r\n";
		$body .= 'Content-Disposition: form-data; name="' . $name . '"; filename="' . wp_basename( $path ) . '"' . "\r\n";
		$body .= 'Content-Type: ' . $mime . "\r\n\r\n";
		$body .= file_get_contents( $path ) . "\r\n"; // phpcs:ignore WordPress.WP.AlternativeFunctions
		$body .= '--' . $boundary . "--\r\n";

		return $body;
	}

	/**
	 * Fetch the optimised copy the service is holding.
	 *
	 * @param string $url     Where it is.
	 * @param string $into    Where to put it.
	 * @param string $service Which service it came from.
	 * @return true|WP_Error
	 */
	private static function download( string $url, string $into, string $service ) {
		$args = array( 'timeout' => self::timeout() );

		if ( 'tinypng' === $service ) {
			$args['headers'] = array( 'Authorization' => 'Basic ' . base64_encode( 'api:' . self::key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		}

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'atr_service_download', __( 'The optimised file could not be fetched back.', 'ajax-thumbnail-rebuild' ) );
		}

		$body = wp_remote_retrieve_body( $response );

		if ( '' === $body ) {
			return new WP_Error( 'atr_service_empty', __( 'The service sent back an empty file.', 'ajax-thumbnail-rebuild' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions
		return false !== file_put_contents( $into, $body )
			? true
			: new WP_Error( 'atr_service_write', __( 'The optimised file could not be written.', 'ajax-thumbnail-rebuild' ) );
	}

	/**
	 * @param string $service Service name.
	 * @return string
	 */
	private static function endpoint( string $service ): string {
		$endpoint = (string) ( self::services()[ $service ]['endpoint'] ?? '' );

		/**
		 * Filter where a service is reached, for testing or for a proxy.
		 *
		 * @param string $endpoint URL.
		 * @param string $service  Service name.
		 */
		return (string) apply_filters( 'ajax_thumbnail_rebuild_service_endpoint', $endpoint, $service );
	}

	private static function key(): string {
		return trim( (string) ATR_Settings::get( 'optimize_service_key' ) );
	}

	private static function timeout(): int {
		return (int) apply_filters( 'ajax_thumbnail_rebuild_service_timeout', 45 );
	}

	private static function max_bytes(): int {
		return (int) apply_filters( 'ajax_thumbnail_rebuild_service_max_bytes', self::MAX_BYTES );
	}

	/**
	 * How much of the allowance has gone, as far as this site knows.
	 */
	public static function usage(): int {
		$usage = get_option( self::USAGE_OPTION, array() );

		return (int) ( $usage['count'] ?? 0 );
	}

	/**
	 * When that number was last true.
	 */
	public static function usage_time(): int {
		$usage = get_option( self::USAGE_OPTION, array() );

		return (int) ( $usage['time'] ?? 0 );
	}

	/**
	 * @param int $count Files used this month.
	 */
	private static function record_usage( int $count ): void {
		update_option( self::USAGE_OPTION, array(
			'count'   => $count,
			'time'    => time(),
			'service' => self::chosen(),
		), false );
	}
}
