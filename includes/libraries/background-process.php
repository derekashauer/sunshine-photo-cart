<?php
/**
 * WP Background Process
 *
 * @package WP-Background-Processing
 */

/**
 * Abstract WP_Background_Process class.
 *
 * @abstract
 * @extends WP_Async_Request
 */
abstract class SPC_Background_Process extends SPC_Async_Request {

	/**
	 * Action
	 *
	 * (default value: 'background_process')
	 *
	 * @var string
	 * @access protected
	 */
	protected $action = 'background_process';

	/**
	 * Start time of current process.
	 *
	 * (default value: 0)
	 *
	 * @var int
	 * @access protected
	 */
	protected $start_time = 0;

	/**
	 * Cron_hook_identifier
	 *
	 * @var mixed
	 * @access protected
	 */
	protected $cron_hook_identifier;

	/**
	 * Cron_interval_identifier
	 *
	 * @var mixed
	 * @access protected
	 */
	protected $cron_interval_identifier;

	/**
	 * Initiate new background process
	 */
	public function __construct() {
		parent::__construct();

		$this->cron_hook_identifier     = $this->identifier . '_cron';
		$this->cron_interval_identifier = $this->identifier . '_cron_interval';

		add_action( $this->cron_hook_identifier, array( $this, 'handle_cron_healthcheck' ) );
		add_filter( 'cron_schedules', array( $this, 'schedule_cron_healthcheck' ) );
	}

	/**
	 * Dispatch
	 *
	 * @access public
	 * @return void
	 */
	public function dispatch() {
		// Schedule the cron healthcheck.
		$this->schedule_event();

		// Perform remote post.
		return parent::dispatch();
	}

	/**
	 * Push to queue
	 *
	 * @param mixed $data Data.
	 *
	 * @return $this
	 */
	public function push_to_queue( $data ) {
		$this->data[] = $data;

		return $this;
	}

	/**
	 * Save queue
	 *
	 * @return $this
	 */
	public function save() {
		$key = $this->generate_key();

		if ( ! empty( $this->data ) ) {
			update_site_option( $key, $this->data );
		}

		return $this;
	}

	/**
	 * Update queue
	 *
	 * @param string $key  Key.
	 * @param array  $data Data.
	 *
	 * @return $this
	 */
	public function update( $key, $data ) {
		if ( ! empty( $data ) ) {
			update_site_option( $key, $data );
		}

		return $this;
	}

	/**
	 * Delete queue
	 *
	 * @param string $key Key.
	 *
	 * @return $this
	 */
	public function delete( $key ) {
		delete_site_option( $key );

		return $this;
	}

	/**
	 * Generate key
	 *
	 * Generates a unique key based on microtime. Queue items are
	 * given a unique key so that they can be merged upon save.
	 *
	 * @param int $length Length.
	 *
	 * @return string
	 */
	protected function generate_key( $length = 64 ) {
		$unique  = md5( microtime() . rand() );
		$prepend = $this->identifier . '_batch_';

		return substr( $prepend . $unique, 0, $length );
	}

	/**
	 * Maybe process queue
	 *
	 * Checks whether data exists within the queue and that
	 * the process is not already running.
	 */
	public function maybe_handle() {
		// Don't lock up other requests while processing
		session_write_close();

		if ( $this->is_process_running() ) {
			// Background process already running.
			wp_die();
		}

		if ( $this->is_queue_empty() ) {
			// No data to process.
			wp_die();
		}

		check_ajax_referer( $this->identifier, 'nonce' );

		$this->handle();

		wp_die();
	}

	/**
	 * Is queue empty
	 *
	 * @return bool
	 */
	protected function is_queue_empty() {
		global $wpdb;

		$table  = $wpdb->options;
		$column = 'option_name';

		if ( is_multisite() ) {
			$table  = $wpdb->sitemeta;
			$column = 'meta_key';
		}

		$key = $wpdb->esc_like( $this->identifier . '_batch_' ) . '%';

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"
			SELECT COUNT(*)
			FROM {$table}
			WHERE {$column} LIKE %s
		",
				$key
			)
		);

		return ( $count > 0 ) ? false : true;
	}

	/**
	 * Is process running
	 *
	 * Check whether the current process is already running
	 * in a background process.
	 */
	protected function is_process_running() {
		if ( get_site_transient( $this->identifier . '_process_lock' ) ) {
			// Process already running.
			return true;
		}

		return false;
	}

	/**
	 * Lock process
	 *
	 * Lock the process so that multiple instances can't run simultaneously.
	 * Override if applicable, but the duration should be greater than that
	 * defined in the time_exceeded() method.
	 */
	protected function lock_process() {
		$this->start_time = time(); // Set start time of current process.

		$lock_duration = ( property_exists( $this, 'queue_lock_time' ) ) ? $this->queue_lock_time : 60; // 1 minute
		$lock_duration = apply_filters( $this->identifier . '_queue_lock_time', $lock_duration );

		set_site_transient( $this->identifier . '_process_lock', microtime(), $lock_duration );
	}

	/**
	 * Unlock process
	 *
	 * Unlock the process so that other instances can spawn.
	 *
	 * @return $this
	 */
	protected function unlock_process() {
		delete_site_transient( $this->identifier . '_process_lock' );

		return $this;
	}

	/**
	 * Get batch
	 *
	 * @return stdClass Return the first batch from the queue
	 */
	protected function get_batch() {
		global $wpdb;

		$table        = $wpdb->options;
		$column       = 'option_name';
		$key_column   = 'option_id';
		$value_column = 'option_value';

		if ( is_multisite() ) {
			$table        = $wpdb->sitemeta;
			$column       = 'meta_key';
			$key_column   = 'meta_id';
			$value_column = 'meta_value';
		}

		$key = $wpdb->esc_like( $this->identifier . '_batch_' ) . '%';

		$query = $wpdb->get_row(
			$wpdb->prepare(
				"
			SELECT *
			FROM {$table}
			WHERE {$column} LIKE %s
			ORDER BY {$key_column} ASC
			LIMIT 1
		",
				$key
			)
		);

		$batch = new stdClass();

		// The row can disappear between is_queue_empty() and this SELECT when more
		// than one process is draining the queue. Return an empty batch instead of
		// fataling on a null $query, which would leave the lock set and no reschedule.
		if ( ! $query ) {
			$batch->key  = '';
			$batch->data = array();

			return $batch;
		}

		$batch->key  = $query->$column;
		$batch->data = maybe_unserialize( $query->$value_column );

		if ( ! is_array( $batch->data ) ) {
			$batch->data = array();
		}

		return $batch;
	}

	/**
	 * Handle
	 *
	 * Pass each queue item to the task handler, while remaining
	 * within server memory and time limit constraints.
	 */
	protected function handle() {
		$this->lock_process();

		// Give the run room to finish a batch. Without this a default 30s PHP limit
		// can kill the request part way through an item, leaving the lock set and
		// the batch unwritten.
		@set_time_limit( $this->get_time_limit() + 60 );

		do {
			$batch = $this->get_batch();

			// Nothing left to work on (the row was claimed by another process).
			if ( empty( $batch->key ) ) {
				break;
			}

			foreach ( $batch->data as $key => $value ) {
				$task = $this->task( $value );

				if ( false !== $task ) {
					$batch->data[ $key ] = $task;
				} else {
					unset( $batch->data[ $key ] );
				}

				if ( $this->time_exceeded() || $this->memory_exceeded() ) {
					// Batch limits reached.
					break;
				}
			}

			// Update or delete current batch.
			if ( ! empty( $batch->data ) ) {
				$this->update( $batch->key, $batch->data );
			} else {
				$this->delete( $batch->key );
			}
		} while ( ! $this->time_exceeded() && ! $this->memory_exceeded() && ! $this->is_queue_empty() );

		$this->unlock_process();

		// Start next batch or complete process.
		if ( ! $this->is_queue_empty() ) {
			$this->dispatch();
		} else {
			$this->complete();
		}

		// No wp_die() here. maybe_handle() already ends the ajax request, and calling
		// it from a cron run would abort every cron event queued after this one.
	}

	/**
	 * Memory exceeded
	 *
	 * Ensures the batch process never exceeds 90%
	 * of the maximum WordPress memory.
	 *
	 * @return bool
	 */
	protected function memory_exceeded() {
		$memory_limit   = $this->get_memory_limit() * 0.9; // 90% of max memory
		$current_memory = memory_get_usage( true );
		$return         = false;

		if ( $current_memory >= $memory_limit ) {
			$return = true;
		}

		return apply_filters( $this->identifier . '_memory_exceeded', $return );
	}

	/**
	 * Get memory limit
	 *
	 * @return int
	 */
	protected function get_memory_limit() {
		if ( function_exists( 'ini_get' ) ) {
			$memory_limit = ini_get( 'memory_limit' );
		} else {
			// Sensible default.
			$memory_limit = '128M';
		}

		if ( ! $memory_limit || - 1 === intval( $memory_limit ) ) {
			// Unlimited, set to 32GB.
			$memory_limit = '32000M';
		}

		return wp_convert_hr_to_bytes( $memory_limit );
	}

	/**
	 * How long a single run may work for, in seconds.
	 *
	 * Subclasses set $run_time_limit to change it; the filter still wins.
	 *
	 * @return int
	 */
	protected function get_time_limit() {
		$limit = property_exists( $this, 'run_time_limit' ) ? $this->run_time_limit : 20;

		return (int) apply_filters( $this->identifier . '_default_time_limit', $limit );
	}

	/**
	 * Time exceeded.
	 *
	 * Ensures the batch never exceeds a sensible time limit.
	 * A timeout limit of 30s is common on shared hosting.
	 *
	 * @return bool
	 */
	protected function time_exceeded() {
		$finish = $this->start_time + $this->get_time_limit();
		$return = false;

		if ( time() >= $finish ) {
			$return = true;
		}

		return apply_filters( $this->identifier . '_time_exceeded', $return );
	}

	/**
	 * Complete.
	 *
	 * Override if applicable, but ensure that the below actions are
	 * performed, or, call parent::complete().
	 */
	protected function complete() {
		// Unschedule the cron healthcheck.
		$this->clear_scheduled_event();
	}

	/**
	 * Schedule cron healthcheck
	 *
	 * @access public
	 *
	 * @param mixed $schedules Schedules.
	 *
	 * @return mixed
	 */
	public function schedule_cron_healthcheck( $schedules ) {
		$interval = apply_filters( $this->identifier . '_cron_interval', 5 );

		if ( property_exists( $this, 'cron_interval' ) ) {
			$interval = apply_filters( $this->identifier . '_cron_interval', $this->cron_interval );
		}

		// Adds every 5 minutes to the existing schedules.
		$schedules[ $this->identifier . '_cron_interval' ] = array(
			'interval' => MINUTE_IN_SECONDS * $interval,
			/* translators: %d is the interval in minutes */
			'display'  => sprintf( __( 'Every %d minute(s)', 'sunshine-photo-cart' ), $interval ),
		);

		return $schedules;
	}

	/**
	 * Handle cron healthcheck
	 *
	 * Restart the background process if not already running
	 * and data exists in the queue.
	 */
	public function handle_cron_healthcheck() {
		// As of WordPress 6.9 _wp_cron() runs on the `shutdown` action, so without this
		// guard an ordinary page view can have a full batch of image processing bolted
		// onto its shutdown. That makes the visitor wait, and if PHP's time limit kills
		// the request mid-query every later query in it fails ("Commands out of sync").
		//
		// DOING_CRON is defined for a real wp-cron.php request (including the loopback
		// WordPress spawns for itself); `doing_wp_cron` covers the query-arg form. If
		// neither is set we are piggybacking on someone's page load, so hand the work
		// off to its own request instead of doing it here.
		if ( ! $this->is_cron_request() ) {
			if ( ! $this->is_queue_empty() ) {
				$this->dispatch();
			}

			return;
		}

		SPC()->log( 'Background Process: Starting background processing' );

		if ( $this->is_process_running() ) {
			// Background process already running.
			return;
		}

		if ( $this->is_queue_empty() ) {
			// No data to process.
			$this->clear_scheduled_event();

			return;
		}

		$this->handle();
	}

	/**
	 * Whether this request is a genuine cron run rather than a page load that
	 * happens to be firing cron on shutdown.
	 *
	 * @return bool
	 */
	protected function is_cron_request() {
		return ( defined( 'DOING_CRON' ) && DOING_CRON ) || isset( $_GET['doing_wp_cron'] );
	}

	/**
	 * Schedule event
	 */
	protected function schedule_event() {
		// Deliberately checks for a *recurring* event rather than any event. A pending
		// one-off would otherwise satisfy wp_next_scheduled() and the recurring backstop
		// would never get created — which is how the queue could stall permanently.
		if ( $this->has_recurring_event() ) {
			return;
		}

		// Start 1 minute out so it does not run on this request's shutdown. The one-off
		// event scheduled in dispatch() handles starting promptly; this only exists to
		// recover a queue whose one-off event was lost.
		$start_time = time() + 60;
		$scheduled  = wp_schedule_event( $start_time, $this->cron_interval_identifier, $this->cron_hook_identifier );
		if ( is_wp_error( $scheduled ) ) {
			SPC()->log( 'Background process: Failed to schedule cron event - ' . $scheduled->get_error_message() );
		}
	}

	/**
	 * Whether a recurring healthcheck event is already scheduled for this queue.
	 *
	 * @return bool
	 */
	protected function has_recurring_event() {
		$crons = _get_cron_array();
		if ( empty( $crons ) || ! is_array( $crons ) ) {
			return false;
		}

		foreach ( $crons as $events ) {
			if ( ! isset( $events[ $this->cron_hook_identifier ] ) ) {
				continue;
			}

			foreach ( $events[ $this->cron_hook_identifier ] as $event ) {
				if ( ! empty( $event['schedule'] ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Clear scheduled event
	 */
	protected function clear_scheduled_event() {
		$timestamp = wp_next_scheduled( $this->cron_hook_identifier );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, $this->cron_hook_identifier );
		}
	}

	/**
	 * Cancel Process
	 *
	 * Stop processing queue items, clear cronjob and delete batch.
	 */
	public function cancel_process() {
		if ( ! $this->is_queue_empty() ) {
			$batch = $this->get_batch();

			$this->delete( $batch->key );

			wp_clear_scheduled_hook( $this->cron_hook_identifier );
		}

	}

	/**
	 * Task
	 *
	 * Override this method to perform any actions required on each
	 * queue item. Return the modified item for further processing
	 * in the next pass through. Or, return false to remove the
	 * item from the queue.
	 *
	 * @param mixed $item Queue item to iterate over.
	 *
	 * @return mixed
	 */
	abstract protected function task( $item );

}
