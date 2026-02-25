<?php
// TODO: Default settings on install for all the emails

class SPC_Email {

	public $id;
	protected $class;
	protected $name;
	protected $description;
	protected $template;
	protected $custom_recipients = false;
	protected $to;
	protected $to_name;
	protected $recipients = array();
	protected $from;
	protected $from_name;
	protected $subject;
	protected $content;
	protected $args           = array(); // Args/variables to pass into the template
	protected $search_replace = array();
	protected $reply_to;

	public function __construct() {

		$this->init();

		$this->add_search_replace(
			array(
				'site_name'    => get_bloginfo( 'name' ),
				'sitename'     => get_bloginfo( 'name' ),
				'site_url'     => get_bloginfo( 'url' ),
				'siteurl'      => get_bloginfo( 'url' ),
				'sunshine_url' => sunshine_get_page_url( 'home' ),
				'sunshineurl'  => sunshine_get_page_url( 'home' ),
				'register_url' => sunshine_get_page_url( 'account' ),
				'first_name'   => __( 'Friend', 'sunshine-photo-cart' ),
			)
		);

		add_filter( 'sunshine_options_email_' . $this->id, array( $this, 'default_options' ), 1 );

	}

	public function init() {}

	public function default_options( $fields ) {
		$fields['1000']      = array(
			'id'   => $this->id . '_header',
			'name' => $this->name . ' <a style="font-size: 12px; font-weight: normal; float: right;" href="' . admin_url( 'admin.php?page=sunshine&section=email' ) . '">' . __( 'Return to email list', 'sunshine-photo-cart' ) . '</a>',
			'type' => 'header',
		);
		$search_replace_keys = $this->get_search_replace_keys();
		if ( ! empty( $search_replace_keys ) ) {
			$search_replace = '[' . join( '], [', $search_replace_keys ) . ']';
			/* translators: %s is the list of available template tags */
			$fields['1000']['description'] = sprintf( __( 'Available template tags: %s', 'sunshine-photo-cart' ), $search_replace );
		}
		$fields['1001'] = array(
			'name' => __( 'Enabled', 'sunshine-photo-cart' ),
			'id'   => 'email_' . $this->id . '_active',
			'type' => 'checkbox',
		);

		if ( $this->custom_recipients ) {
			$fields['1100'] = array(
				'name'        => __( 'Recipient(s)', 'sunshine-photo-cart' ),
				'id'          => 'email_' . $this->id . '_recipients',
				'type'        => 'text',
				'description' => __( 'Comma separated list of email addresses', 'sunshine-photo-cart' ),
			);
		}
		$fields['1200'] = array(
			'name'        => __( 'Subject', 'sunshine-photo-cart' ),
			'id'          => 'email_' . $this->id . '_subject',
			'type'        => 'text',
			'placeholder' => $this->subject,
		);
		$fields['1300'] = array(
			'name' => __( 'Message', 'sunshine-photo-cart' ),
			'id'   => 'email_' . $this->id . '_message',
			'type' => 'wysiwyg',
			// 'description' => __( 'Added message to be included within the email template', 'sunshine-photo-cart' ),
		);
		return $fields;
	}

	public function get_name() {
		return $this->name;
	}

	public function get_description() {
		return $this->description;
	}

	public function get_message() {
		return SPC()->get_option( 'email_' . $this->id . '_message' );
	}

	public function is_active() {
		return SPC()->get_option( 'email_' . $this->id . '_active' );
	}

	public function allows_custom_recipients() {
		return $this->custom_recipients;
	}

	public function set_template( $template, $base_path = '' ) {
		if ( empty( $base_path ) ) {
			$base_path = SUNSHINE_PHOTO_CART_PATH . 'templates/email/';
		}
		if ( file_exists( TEMPLATEPATH . '/sunshine/email/' . $template . '.php' ) ) {
			$template_path = TEMPLATEPATH . '/sunshine/email/' . $template . '.php';
		} else {
			$template_path = trailingslashit( $base_path ) . $template . '.php';
		}
		if ( file_exists( $template_path ) ) {
			$this->template = $template_path;
		}
	}

	public function get_subject() {
		// return $this->subject;
		return SPC()->get_option( 'email_' . $this->id . '_subject' );
	}

	public function add_recipient( $email ) {
		if ( ! empty( $email ) && is_email( $email ) ) {
			$this->recipients[] = $email;
		}
	}

	public function set_recipients( $recipients ) {
		$this->recipients = array();
		if ( ! empty( $recipients ) ) {
			if ( is_array( $recipients ) ) {
				foreach ( $recipients as $email ) {
					$this->add_recipient( $email );
				}
			} else {
				$this->add_recipient( $recipients );
			}
		}
	}

	public function clear_recipients() {
		$this->recipients = array();
	}

	public function get_default_recipients() {
		$recipients = SPC()->get_option( 'email_' . $this->id . '_recipients' );
		if ( $recipients ) {
			$recipients = explode( ',', $recipients );
			$recipients = array_map( 'trim', $recipients );
		}
		return $recipients;
	}

	public function get_recipients() {
		return $this->recipients;
	}

	public function set_from( $from ) {
		if ( ! empty( $from ) && is_email( $from ) ) {
			$this->from = $from;
		}
	}
	public function set_from_name( $from_name ) {
		if ( ! empty( $from_name ) ) {
			$this->from_name = sanitize_text_field( $from_name );
		}
	}

	public function set_subject( $subject ) {
		if ( ! empty( $subject ) ) {
			$this->subject = sanitize_text_field( $subject );
		}
	}

	public function set_content( $content ) {
		if ( ! empty( $content ) ) {
			$this->content = wp_kses_post( $content );
		}
	}

	public function get_content() {
		return $this->content;
	}

	public function set_args( $args ) {
		$this->args = $args;
	}

	public function add_args( $args ) {
		$this->args = array_merge( $this->args, $args );
	}

	public function set_search_replace( $search_replace ) {
		$this->search_replace = $search_replace;
	}

	public function add_search_replace( $search_replace ) {
		$this->search_replace = array_merge( $this->search_replace, $search_replace );
	}

	public function set_search_replace_item( $key, $value ) {
		$this->search_replace[ $key ] = $value;
	}

	public function get_search_replace() {
		return $this->search_replace;
	}

	public function get_search_replace_keys() {
		return array_keys( $this->search_replace );
	}

	public function set_reply_to( $email ) {
		if ( is_email( $email ) ) {
			$this->reply_to = $email;
		}
	}

	public function get_template_content( $template ) {

		// Check if we are passing a simple template name like "header", if so get it from main email template directory.
		if ( basename( $template, '.php' ) == $template ) {
			if ( file_exists( TEMPLATEPATH . '/sunshine/email/' . $template . '.php' ) ) {
				$template_path = TEMPLATEPATH . '/sunshine/email/' . $template . '.php';
			} else {
				$template_path = SUNSHINE_PHOTO_CART_PATH . 'templates/email/' . $template . '.php';
			}
		} else {
			$template_path = $template;
		}

		ob_start();
			extract( $this->args );
			include $template_path;
			$template_content = ob_get_contents();
		ob_end_clean();
		return $template_content;

	}

	public function send() {

		// Init the custom recipients from admin settings
		if ( $this->custom_recipients ) {
			$default_recipients = $this->get_default_recipients();
			if ( ! empty( $default_recipients ) ) {
				foreach ( $default_recipients as $recipient ) {
					$this->add_recipient( $recipient );
				}
			}
		}

		if ( ( empty( $this->template ) && empty( $this->content ) ) || empty( $this->recipients ) || empty( $this->subject ) || ! $this->is_active() ) {
			/*
			sunshine_log( 'Not sending email' );
			sunshine_log( 'Template: ' . $this->template );
			sunshine_log( $this->recipients, 'Recipients' );
			sunshine_log( 'Subject: ' . $this->subject );
			sunshine_log( $this->content, 'Content' );
			if ( $this->is_active() ) {
				sunshine_log( 'mail active' );
			} else {
				sunshine_log( 'NOT active' );
			}
			*/
			SPC()->log( 'Email not sent: ' . $this->template );
			SPC()->log( 'Recipients: ' . print_r( $this->recipients, 1 ) );
			SPC()->log( 'Subject: ' . $this->subject );
			return false;
		}

		$default_search_replace = array(
			'site_name'    => get_bloginfo( 'name' ),
			'sitename'     => get_bloginfo( 'name' ),
			'site_url'     => get_bloginfo( 'url' ),
			'siteurl'      => get_bloginfo( 'url' ),
			'sunshine_url' => sunshine_get_page_url( 'home' ),
			'sunshineurl'  => sunshine_get_page_url( 'home' ),
			'register_url' => sunshine_get_page_url( 'account' ),
		);
		foreach ( $default_search_replace as $key => $value ) {
			$this->search_replace[ $key ] = $value;
		}

		// sunshine_log( 'Sending ' . $this->id );

		if ( empty( $this->from ) ) { // Set up default from email
			$this->from = SPC()->get_option( 'from_email' );
			if ( empty( $this->from ) ) { // Default to admin email if no from is set
				$this->from = get_option( 'admin_email' );
			}
		}

		if ( empty( $this->from_name ) ) { // Set up default from name
			$this->from_name = SPC()->get_option( 'from_name' );
			if ( empty( $this->from_name ) ) { // Default to blog name if no from name is set
				$this->from_name = get_option( 'blogname' );
			}
		}

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $this->from_name . ' <' . $this->from . '>',
		);
		if ( ! empty( $this->reply_to ) ) {
			$headers[] = 'Reply-To: ' . $this->reply_to;
		}

		// Make sure we get any custom message that may exist.
		$this->args['message']  = $this->get_message();
		$this->args['template'] = $this->template;

		// Get main content from template if not yet set.
		if ( empty( $this->content ) ) {
			$this->content = $this->get_template_content( $this->template );
		}

		// Add header/footer.
		$header  = $this->get_template_content( 'header' );
		$footer  = $this->get_template_content( 'footer' );
		$content = $header . $this->content . $footer;

		// Search/replace.
		if ( ! empty( $this->search_replace ) ) {
			foreach ( $this->search_replace as $key => $value ) {
				if ( is_array( $value ) ) {
					continue; // Skip arrays, they cannot be replacements.
				}
				$search[]  = '[' . $key . ']';
				$replace[] = $value;
			}
		}
		$subject = str_replace( $search, $replace, $this->subject );
		$content = str_replace( $search, $replace, $content );

		// Run through emogrifier.
		if ( ! class_exists( 'Sunshine_Emogrifier' ) ) {
			include_once SUNSHINE_PHOTO_CART_PATH . 'includes/class-emogrifier.php';
		}
		$css        = $this->get_template_content( 'style' );
		$css       .= SPC()->get_option( 'css_email' );
		$emogrifier = new Sunshine_Emogrifier( $content, $css );
		$content    = $emogrifier->emogrify();

		// Get attachments from filter
		$attachments = apply_filters( 'sunshine_email_attachments', array(), $this->id, $this->args );

		// Send.
		foreach ( $this->recipients as $email ) {
			$result = wp_mail( $email, $subject, $content, $headers, $attachments );
			if ( $result ) {
				SPC()->log( 'Email sent to ' . $email . ': ' . $subject );
			} else {
				SPC()->log( 'FAILED: Email attempt to ' . $email . ': ' . $subject );
			}
		}

		// Clean up temporary attachment files
		if ( ! empty( $attachments ) ) {
			foreach ( $attachments as $attachment ) {
				if ( file_exists( $attachment ) && ( strpos( $attachment, sys_get_temp_dir() ) !== false || strpos( $attachment, '.ics' ) !== false ) ) {
					@unlink( $attachment );
				}
			}
		}

		$this->recipients = array();
		$this->content    = '';

		return $content;

	}

	function reset() {
		$this->recipients     = array();
		$this->subject        = '';
		$this->content        = '';
		$this->to             = '';
		$this->to_name        = '';
		$this->recipients     = array();
		$this->from           = '';
		$this->from_name      = '';
		$this->subject        = '';
		$this->content        = '';
		$this->args           = array();
		$this->search_replace = array();
		$this->reply_to       = '';
	}

}
