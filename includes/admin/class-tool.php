<?php
/**
 * Base class for "Sunshine → Tools" entries.
 *
 * Tool subclasses extend this, set a key/name/description, implement
 * `do_process()` for one-shot work or override the new batched-execution
 * methods for chunked work, and auto-register through the `sunshine_tools`
 * filter.
 *
 * Two execution paths:
 *
 *   1. Admin UI: the Tools screen calls `pre_process()` (preview info) then,
 *      on click, `process()` which checks nonce/cap and calls `do_process()`.
 *      `do_process()` typically echoes HTML — for chunked tools it renders a
 *      progress bar + JS that fires per-step AJAX requests against handlers
 *      added by the subclass.
 *
 *   2. REST API: the `/tools` controller in the API add-on calls
 *      `is_chunked()`, `count_remaining()`, and `process_batch( $size )` on
 *      chunked tools, or buffers `do_process()` output for one-shot tools.
 *      That lets external callers (Lightroom, CLI, AI agents) drive chunked
 *      tools by looping `process_batch()` calls without involving a browser.
 *
 * Tools opt in to the API path by overriding `count_remaining()` and
 * `process_batch()`; tools that don't override stay admin-only.
 */
class SPC_Tool {

	protected $name;
	protected $key;
	protected $description;
	protected $button_label;

	/**
	 * Default number of units processed per `process_batch()` call when the
	 * caller doesn't ask for a specific size. Tools override per their
	 * server-cost profile:
	 *   - regenerate: 1 (heavy — full decode/resize/watermark)
	 *   - orphans: 25 (light — disk + tiny lookup)
	 *   - unused-image-sizes: 25 (light — DB + file metadata)
	 *
	 * @var int
	 */
	protected $batch_size = 1;

	/**
	 * True for tools that have countable, repeatable units of work the API
	 * can drive batch-by-batch. False for one-shot tools (sessions clear,
	 * reinstall) that run to completion in a single request.
	 *
	 * @var bool
	 */
	protected $is_chunked = false;

	function __construct( $name, $key, $description = '', $button_label = '' ) {

		$this->name         = $name;
		$this->key          = $key;
		$this->description  = $description;
		$this->button_label = $button_label;

		add_filter( 'sunshine_tools', array( $this, 'register' ) );

	}

	function get_name() {
		return $this->name;
	}

	function get_key() {
		return $this->key;
	}

	function get_description() {
		return $this->description;
	}

	function get_button_label() {
		return $this->button_label;
	}

	/**
	 * Whether this tool exposes a chunked unit-of-work surface that the API
	 * can drive (`count_remaining()` + `process_batch()`). Tools that haven't
	 * been refactored for the API stay false; the REST API can still describe
	 * them, but won't try to drive them step-by-step.
	 */
	public function is_chunked() {
		return (bool) $this->is_chunked;
	}

	/**
	 * Default number of units this tool prefers to process per API batch.
	 * The framework caps caller overrides to this value so a request can't
	 * blow memory or PHP timeouts.
	 */
	public function get_batch_size() {
		return max( 1, (int) $this->batch_size );
	}

	/**
	 * Total number of units of work remaining for chunked tools. Default
	 * implementation returns 0 — override in subclasses that have a known
	 * total to report up front.
	 */
	public function count_remaining() {
		return 0;
	}

	/**
	 * Process up to $size units. Override in chunked tools.
	 *
	 * $params is a free-form array of tool-specific arguments (e.g. an
	 * `offset` cursor, a gallery filter, watermark flag). Tools that
	 * auto-shrink their work-set as items are processed (orphans, where
	 * deleted folders are gone next call) can ignore offset entirely;
	 * tools whose work-set stays the same size between calls (regenerate,
	 * unused-image-sizes) read `offset` to find the next chunk.
	 *
	 * Return shape:
	 *   array(
	 *     'processed'   => int,    // how many were handled this call
	 *     'remaining'   => int,    // estimate left after this call
	 *     'next_offset' => int,    // optional cursor to send on the next call
	 *     'log'         => array,  // optional per-item result entries
	 *     'errors'      => array,  // optional per-item error entries
	 *   )
	 *
	 * Default returns an empty result so existing one-shot tools (sessions,
	 * reinstall) don't need to know about the batched path.
	 */
	public function process_batch( $size = null, $params = array() ) {
		return array(
			'processed'   => 0,
			'remaining'   => 0,
			'next_offset' => 0,
			'log'         => array(),
			'errors'      => array(),
		);
	}

	function register( $tools ) {
		$tools[ $this->get_key() ] = $this;
		return $tools;
	}

	function pre_process() { }

	function process() {
		// Verify nonce for security
		if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ), 'sunshine_tool_' . $this->get_key() ) ) {
			wp_die( esc_html__( 'Security check failed', 'sunshine-photo-cart' ) );
		}

		// Verify user capabilities
		if ( ! current_user_can( 'sunshine_manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this tool', 'sunshine-photo-cart' ) );
		}

		// Call the child class's implementation
		$this->do_process();
	}

	protected function do_process() { }

}
