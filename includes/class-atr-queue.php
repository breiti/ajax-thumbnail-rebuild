<?php
/**
 * Doing the heavy work after the upload rather than during it.
 *
 * Optimising a file and writing its WebP and AVIF copies both happen while
 * WordPress is still building the attachment, which is what makes uploading a
 * batch of photographs feel slow: the browser waits for programs that shell
 * out, and sometimes for a service across the network. None of it has to
 * happen before the file is stored.
 *
 * With this on, the upload finishes as soon as the sizes are written and the
 * rest is queued. Action Scheduler runs the queue where a site has it -
 * WooCommerce and a good many other plugins ship it - and WP-Cron runs it
 * where nothing does.
 *
 * @package ajax-thumbnail-rebuild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATR_Queue {

	/** The hook a queued attachment is handed back on, under either runner. */
	const HOOK = 'atr_process_attachment';

	/** Action Scheduler group, so the queue is one filterable list in its screen. */
	const GROUP = 'ajax-thumbnail-rebuild';

	/**
	 * How long a WP-Cron job waits before it may run.
	 *
	 * WP-Cron is spawned by a request, so the wait is a floor rather than a
	 * schedule. It is there to keep the job off the tail of the upload request
	 * itself, which is the request this whole feature exists to shorten.
	 */
	const DELAY = 30;

	public function register(): void {
		/* Registered whatever the setting says: turning the setting off must not
		   strand attachments that were queued while it was on. */
		add_action( self::HOOK, array( __CLASS__, 'process' ) );

		/* After the optimiser at 15 and the copies at 20, both of which stand aside
		   when the work is being deferred. */
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'enqueue_after_generate' ), 25, 2 );
	}

	/**
	 * Whether the site has asked for the work to be done in the background.
	 */
	public static function is_enabled(): bool {
		/**
		 * Filter whether optimising and the copies are queued rather than run on upload.
		 *
		 * @param bool $enabled Whether the setting is on.
		 */
		return (bool) apply_filters( 'ajax_thumbnail_rebuild_background_enabled', (bool) ATR_Settings::get( 'background_enabled' ) );
	}

	/**
	 * Whether this upload should be queued instead of worked on now.
	 *
	 * With neither optimising nor a copy format switched on there is nothing to
	 * defer, and queueing a job that would do nothing is worse than not queueing.
	 */
	public static function is_deferring(): bool {
		return self::is_enabled() && ( ATR_Optimizer::is_enabled() || (bool) ATR_Copies::enabled_formats() );
	}

	/**
	 * Queue the attachment whose sizes have just been written.
	 *
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment id.
	 * @return array Metadata, untouched: this only puts the id in the queue.
	 */
	public static function enqueue_after_generate( $metadata, $attachment_id ) {
		if ( is_array( $metadata ) && self::is_deferring() ) {
			self::enqueue( (int) $attachment_id );
		}

		return $metadata;
	}

	/**
	 * Put one attachment in the queue, unless it is already in it.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return bool Whether it is queued now.
	 */
	public static function enqueue( int $attachment_id ): bool {
		if ( $attachment_id <= 0 ) {
			return false;
		}

		$args = array( $attachment_id );

		if ( self::has_action_scheduler() ) {
			if ( as_has_scheduled_action( self::HOOK, $args, self::GROUP ) ) {
				return true;
			}

			return (bool) as_enqueue_async_action( self::HOOK, $args, self::GROUP );
		}

		if ( wp_next_scheduled( self::HOOK, $args ) ) {
			return true;
		}

		return true === wp_schedule_single_event( time() + self::DELAY, self::HOOK, $args );
	}

	/**
	 * Do for one attachment what the upload did not.
	 *
	 * Both pieces are called directly rather than through the filter they
	 * normally hang on, so neither of them stands aside for the queue a second
	 * time. Everything is read fresh: by the time this runs the upload is long
	 * over, and the attachment may even have been deleted.
	 *
	 * @param int|string $attachment_id Attachment id, as the runner hands it over.
	 */
	public static function process( $attachment_id ): void {
		$attachment_id = (int) $attachment_id;

		if ( $attachment_id <= 0 || ! wp_attachment_is_image( $attachment_id ) ) {
			return;
		}

		$metadata = wp_get_attachment_metadata( $attachment_id, true );

		if ( ! is_array( $metadata ) ) {
			return;
		}

		$before = $metadata;

		if ( ATR_Optimizer::is_enabled() ) {
			$result   = ATR_Optimizer::optimize_attachment( $attachment_id, $metadata );
			$metadata = $result['metadata'];
		}

		$metadata = ATR_Copies::generate_for_attachment( $metadata, $attachment_id );

		if ( is_array( $metadata ) && $metadata !== $before ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}
	}

	/**
	 * Which runner the queue is on, for the screen to name.
	 *
	 * @return string 'action-scheduler' or 'cron'.
	 */
	public static function runner(): string {
		return self::has_action_scheduler() ? 'action-scheduler' : 'cron';
	}

	/**
	 * How many attachments are waiting their turn.
	 */
	public static function pending(): int {
		if ( self::has_action_scheduler() && function_exists( 'as_get_scheduled_actions' ) ) {
			$actions = as_get_scheduled_actions(
				array(
					'hook'     => self::HOOK,
					'group'    => self::GROUP,
					'status'   => 'pending',
					'per_page' => -1,
				),
				'ids'
			);

			return count( (array) $actions );
		}

		$count = 0;

		foreach ( (array) _get_cron_array() as $events ) {
			if ( isset( $events[ self::HOOK ] ) ) {
				$count += count( (array) $events[ self::HOOK ] );
			}
		}

		return $count;
	}

	/**
	 * Whether a queue runner worth using is installed.
	 *
	 * Action Scheduler is loaded by whichever plugin ships the newest copy of it,
	 * on plugins_loaded - well before anything here asks.
	 */
	private static function has_action_scheduler(): bool {
		$has = function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_has_scheduled_action' );

		/**
		 * Filter whether the queue runs on Action Scheduler.
		 *
		 * Return false to stay on WP-Cron on a site that has Action Scheduler -
		 * worth having where the site would rather not add to that queue.
		 *
		 * @param bool $has Whether Action Scheduler is installed.
		 */
		return (bool) apply_filters( 'ajax_thumbnail_rebuild_use_action_scheduler', $has );
	}

	/**
	 * Drop the WP-Cron jobs when the plugin goes away.
	 *
	 * Nothing would answer them, so they would sit in the cron array being
	 * retried. Action Scheduler cleans up after itself.
	 */
	public static function clear(): void {
		if ( function_exists( 'wp_unschedule_hook' ) ) {
			wp_unschedule_hook( self::HOOK );
		}
	}
}
