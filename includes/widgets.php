<?php
/**
 * Adds Main Menu widget.
 */
class Sunshine_Widget_Gallery_Password_Box extends WP_Widget {

	/**
	 * Register widget with WordPress.
	 */
	public function __construct() {
		parent::__construct(
			'sunshine_widget_gallery_password_box', // Base ID
			__( 'Sunshine Gallery Password Box', 'sunshine-photo-cart' ), // Name
			array( 'description' => __( 'Display a gallery password box', 'sunshine-photo-cart' ) ) // Args
		);
	}

	/**
	 * Front-end display of widget.
	 *
	 * @see WP_Widget::widget()
	 *
	 * @param array $args     Widget arguments.
	 * @param array $instance Saved values from database.
	 */
	public function widget( $args, $instance ) {
		if ( $instance['visibility'] == 'sunshine_only' && ! is_sunshine() ) {
			return;
		}

		extract( $args );
		$title = apply_filters( 'widget_title', $instance['title'] );

		echo wp_kses_post( $before_widget );
		if ( ! empty( $title ) ) {
			echo wp_kses_post( $before_title . $title . $after_title );
		}
		sunshine_gallery_password_form();
		echo wp_kses_post( $after_widget );
	}

	/**
	 * Sanitize widget form values as they are saved.
	 *
	 * @see WP_Widget::update()
	 *
	 * @param array $new_instance Values just sent to be saved.
	 * @param array $old_instance Previously saved values from database.
	 *
	 * @return array Updated safe values to be saved.
	 */
	public function update( $new_instance, $old_instance ) {
		$instance               = array();
		$instance['title']      = wp_strip_all_tags( $new_instance['title'] );
		$instance['visibility'] = wp_strip_all_tags( $new_instance['visibility'] );

		return $instance;
	}

	/**
	 * Back-end widget form.
	 *
	 * @see WP_Widget::form()
	 *
	 * @param array $instance Previously saved values from database.
	 */
	public function form( $instance ) {
		$title = '';
		if ( isset( $instance['title'] ) ) {
			$title = $instance['title'];
		}
		$visibility = '';
		if ( isset( $instance['visibility'] ) ) {
			$visibility = $instance['visibility'];
		}
		?>
		<p>
		<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title (Optional):', 'sunshine-photo-cart' ); ?></label>
		<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<p>
		<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Visibility (Optional):', 'sunshine-photo-cart' ); ?></label>
		<br /><input type="checkbox" value="sunshine_only" name="<?php echo esc_attr( $this->get_field_name( 'visibility' ) ); ?>" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" <?php checked( 'sunshine_only', $visibility ); ?> /> <?php esc_html_e( 'Only visible when on Sunshine pages', 'sunshine-photo-cart' ); ?>
		</p>
		<?php
	}

} // class Foo_Widget

class Sunshine_Widget_Main_Menu extends WP_Widget {

	/**
	 * Register widget with WordPress.
	 */
	public function __construct() {
		parent::__construct(
			'sunshine_widget_main_menu', // Base ID
			__( 'Sunshine Main Menu', 'sunshine-photo-cart' ), // Name
			array( 'description' => __( 'Display Sunshine\'s main menu links', 'sunshine-photo-cart' ) ) // Args
		);
	}

	/**
	 * Front-end display of widget.
	 *
	 * @see WP_Widget::widget()
	 *
	 * @param array $args     Widget arguments.
	 * @param array $instance Saved values from database.
	 */
	public function widget( $args, $instance ) {
		global $sunshine;

		if ( $instance['visibility'] == 'sunshine_only' && ! is_sunshine() ) {
			return;
		}

		extract( $args );
		$title = apply_filters( 'widget_title', $instance['title'] );

		echo wp_kses_post( $before_widget );
		if ( ! empty( $title ) ) {
			echo wp_kses_post( $before_title . $title . $after_title );
		}
		sunshine_main_menu();
		echo wp_kses_post( $after_widget );

	}

	/**
	 * Sanitize widget form values as they are saved.
	 *
	 * @see WP_Widget::update()
	 *
	 * @param array $new_instance Values just sent to be saved.
	 * @param array $old_instance Previously saved values from database.
	 *
	 * @return array Updated safe values to be saved.
	 */
	public function update( $new_instance, $old_instance ) {
		$instance               = array();
		$instance['title']      = wp_strip_all_tags( $new_instance['title'] );
		$instance['visibility'] = wp_strip_all_tags( $new_instance['visibility'] );
		return $instance;
	}

	/**
	 * Back-end widget form.
	 *
	 * @see WP_Widget::form()
	 *
	 * @param array $instance Previously saved values from database.
	 */
	public function form( $instance ) {
		$title = '';
		if ( isset( $instance['title'] ) ) {
			$title = $instance['title'];
		}
		$visibility = '';
		if ( isset( $instance['visibility'] ) ) {
			$visibility = $instance['visibility'];
		}
		?>
		<p>
		<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title (Optional):', 'sunshine-photo-cart' ); ?></label>
		<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<p>
		<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Visibility (Optional):', 'sunshine-photo-cart' ); ?></label>
		<br /><input type="checkbox" value="sunshine_only" name="<?php echo esc_attr( $this->get_field_name( 'visibility' ) ); ?>" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" <?php checked( 'sunshine_only', $visibility ); ?> /> <?php esc_html_e( 'Only visible when on Sunshine pages', 'sunshine-photo-cart' ); ?>
		</p>
		<?php
	}

}

class Sunshine_Widget_Search extends WP_Widget {

	/**
	 * Register widget with WordPress.
	 */
	public function __construct() {
		parent::__construct(
			'sunshine_widget_search', // Base ID
			__( 'Sunshine Search', 'sunshine-photo-cart' ), // Name
			array( 'description' => __( 'Display a search box', 'sunshine-photo-cart' ) ) // Args
		);
	}

	/**
	 * Front-end display of widget.
	 *
	 * @see WP_Widget::widget()
	 *
	 * @param array $args     Widget arguments.
	 * @param array $instance Saved values from database.
	 */
	public function widget( $args, $instance ) {
		global $sunshine;

		if ( $instance['visibility'] == 'sunshine_only' && ! is_sunshine() ) {
			return;
		}

		extract( $args );
		$title = apply_filters( 'widget_title', $instance['title'] );

		echo wp_kses_post( $before_widget );
		if ( ! empty( $title ) ) {
			echo wp_kses_post( $before_title . $title . $after_title );
		}
		sunshine_search();
		echo wp_kses_post( $after_widget );

	}

	/**
	 * Sanitize widget form values as they are saved.
	 *
	 * @see WP_Widget::update()
	 *
	 * @param array $new_instance Values just sent to be saved.
	 * @param array $old_instance Previously saved values from database.
	 *
	 * @return array Updated safe values to be saved.
	 */
	public function update( $new_instance, $old_instance ) {
		$instance               = array();
		$instance['title']      = wp_strip_all_tags( $new_instance['title'] );
		$instance['visibility'] = wp_strip_all_tags( $new_instance['visibility'] );
		return $instance;
	}

	/**
	 * Back-end widget form.
	 *
	 * @see WP_Widget::form()
	 *
	 * @param array $instance Previously saved values from database.
	 */
	public function form( $instance ) {
		$title = '';
		if ( isset( $instance['title'] ) ) {
			$title = $instance['title'];
		}
		$visibility = '';
		if ( isset( $instance['visibility'] ) ) {
			$visibility = $instance['visibility'];
		}
		?>
		<p>
		<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title (Optional):', 'sunshine-photo-cart' ); ?></label>
		<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<p>
		<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Visibility (Optional):', 'sunshine-photo-cart' ); ?></label>
		<br /><input type="checkbox" value="sunshine_only" name="<?php echo esc_attr( $this->get_field_name( 'visibility' ) ); ?>" id="<?php echo esc_attr( $this->get_field_id( 'visibility' ) ); ?>" <?php checked( 'sunshine_only', $visibility ); ?> /> <?php esc_html_e( 'Only visible when on Sunshine pages', 'sunshine-photo-cart' ); ?>
		</p>
		<?php
	}

}

add_action( 'widgets_init', 'sunshine_widgets_init' );
function sunshine_widgets_init() {
	register_widget( 'sunshine_widget_gallery_password_box' );
	register_widget( 'sunshine_widget_main_menu' );
	register_widget( 'sunshine_widget_search' );
}
?>
