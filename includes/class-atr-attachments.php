<?php
/**
 * Finding the image attachments to work on.
 *
 * @package ajax-thumbnail-rebuild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATR_Attachments {

	/**
	 * The images to rebuild, newest first.
	 *
	 * Only the two columns the UI needs are read: loading every attachment as a
	 * WP_Post (with its meta and term caches) is what made this step slow, and
	 * memory hungry, on large libraries.
	 *
	 * @param array $args {
	 *     @type bool   $only_featured Restrict to images used as a featured image.
	 *     @type string $filename      Shell style wildcard matched against the file name.
	 * }
	 * @return array<int,array{id:int,title:string}>
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;

		$args = wp_parse_args( $args, array(
			'only_featured' => false,
			'filename'      => '',
			'include'       => array(),
		) );

		$include = array_values( array_filter( array_map( 'absint', (array) $args['include'] ) ) );

		$filename = trim( (string) $args['filename'] );

		/* post_date is in the select list because MySQL refuses to order a DISTINCT
		   query by a column that is not. */
		$select = "SELECT DISTINCT p.ID AS id, p.post_title AS title, p.post_date";
		$from   = " FROM {$wpdb->posts} AS p";
		$join   = '';
		$where  = " WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'";
		$params = array();

		if ( $include ) {
			// A selection made in the media library: work on exactly these.
			$where .= ' AND p.ID IN (' . implode( ',', $include ) . ')';
		}

		if ( $args['only_featured'] ) {
			/* One featured image can be shared by many posts, hence the DISTINCT:
			   rebuilding the same file once per post is pure waste. */
			$conditions = array( "( tm.meta_key = '_thumbnail_id' AND tm.meta_value = p.ID )" );

			/* A WooCommerce product's main image is its featured image, so that is
			   already covered; its gallery images are a comma separated list of ids
			   in a meta field of their own. */
			foreach ( self::gallery_meta_keys() as $meta_key ) {
				$conditions[] = $wpdb->prepare(
					'( tm.meta_key = %s AND FIND_IN_SET( p.ID, REPLACE( tm.meta_value, " ", "" ) ) )',
					$meta_key
				);
			}

			$join .= " INNER JOIN {$wpdb->postmeta} AS tm ON ( " . implode( ' OR ', $conditions ) . ' )';
		}

		if ( '' !== $filename ) {
			$select  .= ', fm.meta_value AS file';
			$join    .= " INNER JOIN {$wpdb->postmeta} AS fm ON fm.post_id = p.ID AND fm.meta_key = '_wp_attached_file'";
			$where   .= ' AND fm.meta_value LIKE %s';
			$params[] = '%' . self::pattern_to_like( $filename );
		}

		$sql = $select . $from . $join . $where . ' ORDER BY p.post_date DESC';

		if ( $params ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from fixed fragments above.
			$sql = $wpdb->prepare( $sql, $params );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $sql );

		$pattern = '' !== $filename ? self::pattern_to_regex( $filename ) : '';
		$items   = array();

		foreach ( (array) $rows as $row ) {
			/* The SQL LIKE is a coarse pre-filter: it also matches directory names and
			   treats ? as a wildcard only by luck. This is the exact match. */
			if ( '' !== $pattern && ! preg_match( $pattern, wp_basename( (string) ( $row->file ?? '' ) ) ) ) {
				continue;
			}

			$items[] = array(
				'id'    => (int) $row->id,
				'title' => (string) $row->title,
			);
		}

		return $items;
	}

	/**
	 * Meta fields that hold a comma separated list of image ids used on a post.
	 *
	 * WooCommerce's product gallery is the one every shop has; a site with another
	 * can add to it.
	 *
	 * @return string[]
	 */
	public static function gallery_meta_keys(): array {
		$keys = class_exists( 'WooCommerce' ) ? array( '_product_image_gallery' ) : array();

		return array_values( array_filter( array_map( 'strval', (array) apply_filters( 'ajax_thumbnail_rebuild_gallery_meta_keys', $keys ) ) ) );
	}

	/**
	 * How many image attachments the library holds.
	 */
	public static function count(): int {
		$counts = (array) wp_count_attachments( 'image' );

		return (int) array_sum( array_map( 'intval', $counts ) );
	}

	/**
	 * Translate a shell style wildcard into the tail of a SQL LIKE pattern.
	 */
	private static function pattern_to_like( string $pattern ): string {
		global $wpdb;

		/* esc_like escapes the SQL wildcards typed literally; the shell style ones the
		   user meant as wildcards are then translated to their SQL equivalents. */
		return str_replace( array( '*', '?' ), array( '%', '_' ), $wpdb->esc_like( $pattern ) );
	}

	/**
	 * Translate a shell style wildcard into a regex matched against the file name.
	 */
	private static function pattern_to_regex( string $pattern ): string {
		$quoted = preg_quote( $pattern, '#' );
		$quoted = str_replace( array( '\*', '\?' ), array( '.*', '.' ), $quoted );

		return '#^' . $quoted . '$#i';
	}
}
