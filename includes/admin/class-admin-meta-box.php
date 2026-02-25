<?php
class Sunshine_Admin_Meta_Boxes {

	// Array: Post Type > Meta Box > Tab > Fields.
	protected $options    = array();
	protected $meta_boxes = array();
	protected $post_type;

	public function __construct() {

		add_action( 'current_screen', array( $this, 'init' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ), 30 );
		add_action( 'save_post', array( $this, 'pre_save_meta_boxes' ), 1, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_sunshine_search_users', array( $this, 'search_users' ) );
		add_action( 'wp_ajax_sunshine_search_galleries', array( $this, 'search_galleries' ) );
		add_action( 'wp_ajax_sunshine_search_products', array( $this, 'search_products' ) );
		$this->register_post_meta();

	}

	private function register_post_meta() {
		register_post_meta(
			'post',
			'sidebar_plugin_meta_block_field',
			array(
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'string',
			)
		);
	}

	public function init() {

		$screen = get_current_screen();
		if ( $screen->base != 'post' || $this->post_type != $screen->post_type ) {
			return;
		}

		add_filter( 'sunshine_admin_meta_boxes_' . $this->post_type, array( $this, 'set_meta_boxes' ), 1 );
		add_filter( 'sunshine_admin_meta_' . $this->post_type, array( $this, 'set_options' ), 1 );
		$this->meta_boxes = apply_filters( 'sunshine_admin_meta_boxes_' . $this->post_type, $this->meta_boxes );
		$this->options    = apply_filters( 'sunshine_admin_meta_' . $this->post_type, $this->options );
		add_action( 'sunshine_save_' . $this->post_type . '_meta', array( $this, 'save_meta_boxes' ) );
	}

	public function set_meta_boxes( $meta_boxes ) {
		return $meta_boxes;
	}

	public function add_meta_boxes() {
		$screen    = get_current_screen();
		$screen_id = $screen ? $screen->id : '';

		if ( ! empty( $this->meta_boxes ) ) {
			foreach ( $this->meta_boxes as $meta_box_screen_id => $meta_boxes ) {
				if ( $screen_id != $meta_box_screen_id ) {
					continue;
				}
				foreach ( $meta_boxes as $meta_box ) {
					$meta_box = wp_parse_args(
						$meta_box,
						array(
							'id'       => wp_generate_password( 10, false ),
							'name'     => __( 'Meta Box', 'sunshine-photo-cart' ),
							'callback' => array( $this, 'show_meta_box' ),
							'screen'   => $meta_box_screen_id,
							'context'  => 'normal',
							'priority' => 'default',
							'args'     => array(
								'__block_editor_compatible_meta_box' => true,
								'__back_compat_meta_box' => false,
							),
						)
					);
					add_meta_box(
						$meta_box['id'],
						$meta_box['name'],
						$meta_box['callback'],
						$meta_box['screen'],
						$meta_box['context'],
						$meta_box['priority'],
						$meta_box['args']
					);
				}
			}
		}

	}

	public function show_meta_box( $post, $args ) {
		if ( empty( $this->options[ $args['id'] ] ) ) {
			return false;
		}
		$tabs = $this->options[ $args['id'] ];
		ksort( $tabs );
		wp_nonce_field( 'sunshine_meta_nonce', 'sunshine_meta_nonce' );

		echo '<div class="sunshine-admin-meta-box-tabs">';
		// If we only have 1 tab, then don't show tab interface
		if ( count( $tabs ) > 1 ) {
			echo '<nav class="sunshine-admin-meta-box-tab-links">';
			$count = 1;
			foreach ( $tabs as $tab ) {
				if ( empty( $tab['fields'] ) ) {
					continue;
				}
				echo '<a href="#sunshine-admin-meta-box-tab-fields-' . esc_attr( $tab['id'] ) . '" id="sunshine-admin-meta-box-tab-link-' . esc_attr( $tab['id'] ) . '"' . ( ( $count == 1 ) ? ' class="active"' : '' ) . '>';
				if ( ! empty( $tab['icon'] ) ) {
					// echo file_get_contents( $tab['icon'] );
				}
				echo wp_kses_post( $tab['name'] ) . '</a>';
				$count++;
			}
			echo '</nav>';
		}

		$count = 1;
		foreach ( $tabs as $tab ) {
			if ( empty( $tab['fields'] ) ) {
				continue;
			}
			ksort( $tab['fields'] );
			echo '<div id="sunshine-admin-meta-box-tab-fields-' . esc_attr( $tab['id'] ) . '" class="sunshine-admin-meta-box-tab-fields"' . ( ( $count > 1 ) ? ' style="display: none;"' : '' ) . '>';
			echo '<table class="sunshine-meta-fields">';
			foreach ( $tab['fields'] as $field ) {
				if ( $field['type'] == 'hidden' ) {
					$this->display_field( $field );
					continue;
				}
				echo '<tr id="sunshine-meta-fields-' . esc_attr( $field['id'] ) . '" class="sunshine-meta-field-' . esc_attr( $field['type'] ) . '" data-type="' . esc_attr( $field['type'] ) . '">';
				if ( ! empty( $field['name'] ) ) {
					echo '<th>';
					echo esc_html( $field['name'] );
					if ( ! empty( $field['documentation'] ) ) {
						echo ' <a href="' . esc_url( $field['documentation'] ) . '" target="_blank" title="' . esc_attr__( 'Documentation', 'sunshine-photo-cart' ) . '" class="sunshine-admin-meta-doc"></a>';
					}
					echo '</th>';
				}
				echo '<td colspan="2">';
				$this->display_field( $field );
				echo '</td>';
				echo '</tr>';
				if ( ! empty( $field['upgrade'] ) ) {
					if ( ! empty( $field['upgrade']['addon'] ) && $field['upgrade']['label'] && ! is_sunshine_addon_active( $field['upgrade']['addon'] ) && ! SPC()->is_pro() ) {
						echo '<tr class="sunshine-meta-field-upgrade">';
						echo '<td colspan="2">';
						echo esc_html( $field['upgrade']['label'] );
						if ( ! empty( $field['upgrade']['url'] ) ) {
							$url = $field['upgrade']['url'];
						} else {
							$url = 'https://www.sunshinephotocart.com/upgrade/';
						}
						echo ' <a href="' . esc_url( $url ) . '?utm_source=plugin&utm_medium=link&utm_campaign=upgrade" target="_blank">' . esc_html__( 'Learn more', 'sunshine-photo-cart' ) . '</a>';
						echo '</td>';
						echo '</tr>';
					}
				}
				$count++;
			}
			echo '</table>';
			echo '</div>';
			$this->display_conditions( $tab['fields'] );
		}
		echo '</div>';
		?>
		<script>
		jQuery( document ).ready(function($){
			$( '.sunshine-admin-meta-box-tab-links a' ).on( 'click', function(){
				var sunshine_admin_meta_box_clicked_tab = $( this ).attr( 'href' );
				$( '.sunshine-admin-meta-box-tab-fields' ).hide();
				$( sunshine_admin_meta_box_clicked_tab ).show();
				$( '.sunshine-admin-meta-box-tab-links a' ).removeClass( 'active' );
				$( this ).addClass( 'active' );
				return false;
			});
		});

		function sunshine_get_condition_field_value( field_id ) {
			var field = jQuery( '#sunshine-meta-fields-' + field_id );
			var field_type = field.data( 'type' );
			var value;
			if ( field_type == 'text' || field_type == 'email' || field_type == 'tel' || field_type == 'password' ) { // Text input box
				value = jQuery( 'input', field ).val();
			} else if ( field_type == 'checkbox' ) {
				value = jQuery( 'input:checked', field ).val();
				if ( typeof value === 'undefined' ) {
					value = 'no';
				}
			} else if ( field_type == 'checkbox_multi' ) {
				value = [];
				jQuery( 'input:checked', field ).each(function(){
					value.push( jQuery( this ).val() );
				});
			} else if ( field_type == 'radio' ) {
				value = jQuery( 'input:checked', field ).val();
				if ( typeof value === 'undefined' ) {
					value = 0;
				}
			} else if ( field_type == 'select' ) {
				value = jQuery( 'select option:selected', field ).val();
			}
			return value;
		}
		</script>
		<?php
	}


	public function pre_save_meta_boxes( $post_id, $post ) {
		$post_id = absint( $post_id );

		if ( empty( $post_id ) || empty( $post ) ) {
			return;
		}

		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || is_int( wp_is_post_revision( $post ) ) || is_int( wp_is_post_autosave( $post ) ) ) {
			return;
		}

		if ( empty( $_POST['sunshine_meta_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['sunshine_meta_nonce'] ), 'sunshine_meta_nonce' ) ) {
			return;
		}

		if ( empty( $_POST['post_ID'] ) || absint( $_POST['post_ID'] ) !== $post_id ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( $this->post_type != $post->post_type ) {
			return;
		}

		do_action( 'sunshine_save_' . $post->post_type . '_meta', $post_id, $post );

	}

	public function save_meta_boxes() {
		global $post;

		if ( empty( $this->options ) || ! is_array( $this->options ) || empty( $_POST ) ) {
			return false;
		}
		foreach ( $this->options as $post_type => $meta_boxes ) {
			foreach ( $meta_boxes as $meta_box ) {
				foreach ( $meta_box as $fields ) {
					if ( empty( $fields ) || ! is_array( $fields ) ) {
						continue;
					}
					foreach ( $fields as $key => $field ) {

						if ( array_key_exists( $field['id'], $_POST ) ) {
							$meta_value = $_POST[ $field['id'] ];
						} else {
							$meta_value = '';
						}

						// Run the field's custom callback
						if ( ! empty( $field['callback'] ) ) {
							$meta_value = call_user_func( $field['callback'], $meta_value );
						}

						// Sanitize field based on type
						$meta_value = $this->sanitize( $meta_value, $field['type'] );

						// Custom validation for this specific field
						$meta_value = apply_filters( 'sunshine_meta_' . $field['id'] . '_validate', $meta_value );

						delete_post_meta( $post->ID, $field['id'] ); // Delete existing meta to prevent any duplicates.
						update_post_meta( $post->ID, $field['id'], $meta_value );

					}
				}
			}
		}
	}

	public function set_options( $options ) {
		return $options;
	}

	public function display_field( $field ) {
		global $post;

		$html = '';

		$meta_key   = $field['id'];
		$meta_value = maybe_unserialize( get_post_meta( $post->ID, $meta_key, true ) );

		if ( empty( $meta_value ) && isset( $field['default'] ) ) {
			$meta_value = $field['default'];
		}

		$defaults = array(
			'id'          => '',
			'name'        => '',
			'description' => '',
			'type'        => '',
			'min'         => '',
			'max'         => '',
			'step'        => '',
			'default'     => '',
			'placeholder' => '',
			'select2'     => false,
			'multiple'    => false,
			'options'     => array(),
			'before'      => '',
			'after'       => '',
			'required'    => false,
		);
		$field    = wp_parse_args( $field, $defaults );

		// Easy, predefined options
		/*
		if ( ! empty( $field['options'] ) ) {
			if ( $field['options'] == 'users' ) {
				$users            = get_users();
				$field['options'] = array();
				foreach ( $users as $user ) {
					$field['options'][ $user->ID ] = $user->display_name;
				}
			}
		}
		*/

		switch ( $field['type'] ) {

			case 'text':
			case 'password':
			case 'hidden':
			case 'email':
				$html .= '<input ' . ( ( $field['required'] ) ? 'required="required"' : '' ) . ' id="' . esc_attr( $field['id'] ) . '" type="' . $field['type'] . '" name="' . esc_attr( $meta_key ) . '" placeholder="' . esc_attr( $field['placeholder'] ) . '" value="' . esc_attr( $meta_value ) . '" />' . "\n";
				break;

			case 'price':
				$currency = sunshine_get_currency();
				$position = SPC()->get_option( 'currency_symbol_position' );

				$price_levels = sunshine_get_price_levels();
				if ( ! empty( $price_levels ) ) {
					if ( count( $price_levels ) > 1 ) {
						$html .= '<div class="sunshine-price-levels">';
						foreach ( $price_levels as $price_level ) {
							if ( is_array( $meta_value ) && array_key_exists( $price_level->get_id(), $meta_value ) && $meta_value[ $price_level->get_id() ] != '' ) {
								$price = $meta_value[ $price_level->get_id() ];
							} else {
								$price = '';
							}
							$price_field = '<input id="' . esc_attr( $field['id'] . '-' . $price_level->get_id() ) . '" type="text" size="8" name="' . esc_attr( $meta_key ) . '[' . $price_level->get_id() . ']" placeholder="' . esc_attr( $field['placeholder'] ) . '" value="' . esc_attr( $price ) . '" />' . "\n";
							if ( $position == 'left' ) {
								$price_field = sunshine_currency_symbol( $currency ) . $price_field;
							} else {
								$price_field = $price_field . sunshine_currency_symbol( $currency );
							}
							$html .= '<div class="sunshine-price-level-price"><label for="' . esc_attr( $field['id'] . '-' . $price_level->get_id() ) . '">' . $price_level->get_name() . '</label>' . $price_field . '</div>';
						}
						$html .= '</div>';

					} else {
						$value       = ( ! empty( $meta_value[ $price_levels[0]->get_id() ] ) ) ? $meta_value[ $price_levels[0]->get_id() ] : '';
						$price_field = '<input id="' . esc_attr( $field['id'] ) . '" type="text" size="8" name="' . esc_attr( $meta_key ) . '[' . $price_levels[0]->get_id() . ']" placeholder="' . esc_attr( $field['placeholder'] ) . '" value="' . esc_attr( $value ) . '" />' . "\n";
						if ( $position == 'left' ) {
							$price_field = sunshine_currency_symbol( $currency ) . $price_field;
						} else {
							$price_field = $price_field . sunshine_currency_symbol( $currency );
						}
						$html .= $price_field;
					}
				}
				break;

			case 'number':
				$html .= '<input ' . ( ( $field['required'] ) ? 'required="required"' : '' ) . ' id="' . esc_attr( $field['id'] ) . '" type="' . $field['type'] . '" name="' . esc_attr( $meta_key ) . '" placeholder="' . esc_attr( $field['placeholder'] ) . '" min="' . esc_attr( $field['min'] ) . '" max="' . esc_attr( $field['max'] ) . '" step="' . esc_attr( $field['step'] ) . '" value="' . esc_attr( $meta_value ) . '" />' . "\n";
				break;

			case 'range':
				$meta_value              = ( $meta_value ) ? $meta_value : 0;
				$min                     = ( isset( $field['min'] ) ) ? $field['min'] : 0;
				$max                     = ( isset( $field['max'] ) ) ? $field['max'] : 100;
				$step                    = ( isset( $field['step'] ) ) ? $field['step'] : 1;
				$set_value_function_name = 'wps_set_value_for_' . str_replace( '-', '_', sanitize_title_with_dashes( $field['id'] ) );
				$html                   .= '<input id="' . esc_attr( $field['id'] ) . '" type="' . $field['type'] . '" name="' . esc_attr( $meta_key ) . '" min="' . esc_attr( $field['min'] ) . '" max="' . esc_attr( $field['max'] ) . '" step="' . esc_attr( $field['step'] ) . '" value="' . esc_attr( $meta_value ) . '" oninput="' . $set_value_function_name . '( value )" /> <output for="' . esc_attr( $field['id'] ) . '" id="' . esc_attr( $field['id'] ) . '-output">' . floatval( $meta_value ) . '</output>' . "\n";
				$html                   .= '<script> function ' . $set_value_function_name . '( range_value ) { document.querySelector( "#' . esc_js( $field['id'] ) . '-output" ).value = range_value; } </script>';
				break;

			case 'text_secret':
				$html .= '<input id="' . esc_attr( $field['id'] ) . '" type="text" name="' . esc_attr( $meta_key ) . '" placeholder="' . esc_attr( $field['placeholder'] ) . '" value="" />' . "\n";
				break;

			case 'textarea':
				$html .= '<textarea ' . ( ( $field['required'] ) ? 'required="required"' : '' ) . ' id="' . esc_attr( $field['id'] ) . '" rows="5" cols="50" name="' . esc_attr( $meta_key ) . '" placeholder="' . esc_attr( $field['placeholder'] ) . '">' . wp_kses_post( $meta_value ) . '</textarea>' . "\n";
				break;

			case 'wysiwyg':
				wp_editor( $meta_value, $field['id'], array( 'textarea_name' => $meta_key ) );
				break;

			case 'checkbox':
				$html .= '<input ' . ( ( $field['required'] ) ? 'required="required"' : '' ) . ' id="' . esc_attr( $field['id'] ) . '" type="' . esc_attr( $field['type'] ) . '" name="' . esc_attr( $meta_key ) . '" value="1" ' . checked( $meta_value, 1, false ) . '/>' . "\n";
				break;

			case 'checkbox_multi':
				foreach ( $field['options'] as $k => $v ) {
					$html .= '<label for="' . esc_attr( $field['id'] . '_' . $k ) . '"><input ' . ( ( $field['required'] ) ? 'required="required"' : '' ) . ' type="checkbox" ' . checked( ( is_array( $meta_value ) && in_array( $k, $meta_value ) ), true, false ) . ' name="' . esc_attr( $meta_key ) . '[]" value="' . esc_attr( $k ) . '" id="' . esc_attr( $field['id'] . '_' . $k ) . '" /> ' . wp_kses_post( $v ) . '</label><br />';
				}
				break;

			case 'radio':
				foreach ( $field['options'] as $k => $v ) {
					$html .= '<label for="' . esc_attr( $field['id'] . '_' . $k ) . '"><input ' . ( ( $field['required'] ) ? 'required="required"' : '' ) . ' type="radio" ' . checked( $k, $meta_value, false ) . ' name="' . esc_attr( $meta_key ) . '" value="' . esc_attr( $k ) . '" id="' . esc_attr( $field['id'] . '_' . $k ) . '" /> ' . wp_kses_post( $v ) . '</label><br />';
				}
				break;

			case 'radio_image':
				foreach ( $field['options'] as $k => $v ) {
					$html .= '<label for="' . esc_attr( $field['id'] . '_' . $k ) . '"><img src="' . esc_url( $v['image'] ) . '" alt="" /><input type="radio" ' . checked( $k, $meta_value, false ) . ' name="' . esc_attr( $meta_key ) . '" value="' . esc_attr( $k ) . '" id="' . esc_attr( $field['id'] . '_' . $k ) . '" /> ' . wp_kses_post( $v['label'] ) . '</label><br />';
				}
				break;

			case 'select':
				$html .= '<select ' . ( ( $field['required'] ) ? 'required="required"' : '' ) . ' name="' . esc_attr( $meta_key ) . ( ( $field['multiple'] ) ? '[]' : '' ) . '" id="' . esc_attr( $field['id'] ) . '"' . ( ( $field['multiple'] ) ? ' multiple="multiple"' : '' ) . '>';
				foreach ( $field['options'] as $k => $v ) {
					$html .= '<option ' . selected( ( $meta_value == $k ) || ( is_array( $meta_value ) && in_array( $k, $meta_value ) ), true, false ) . ' value="' . esc_attr( $k ) . '">' . wp_kses_post( $v ) . '</option>';
				}
				$html .= '</select> ';
				if ( $field['select2'] ) {
					$html .= '
                    <script type="text/javascript">jQuery(function () {
                        jQuery("#' . esc_js( $field['id'] ) . '").select2({ width: "350px", placeholder: "' . esc_js( $field['placeholder'] ) . '" });
                        });</script>';
				}
				break;

			case 'single_select_page':
					$selected = ( $meta_value !== false ) ? $meta_value : false;

				if ( $meta_value == 0 ) {
					$selected = false;
				}

				$args = array(
					'name'       => $meta_key,
					'id'         => $field['id'],
					'sort_order' => 'ASC',
					'echo'       => 0,
					'selected'   => $selected,
				);

				$html .= str_replace( "'>", "'><option></option>", wp_dropdown_pages( $args ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

				if ( $selected ) {
					$html .= '<a href="' . esc_url( get_permalink( $selected ) ) . '" target="_blank" class="button">' . esc_html__( 'View page', 'sunshine-photo-cart' ) . '</a>';
				}

				$html .= '
                <script type="text/javascript">jQuery(function () {
                        jQuery("#' . esc_js( $field['id'] ) . '").select2({ width: "350px", placeholder: "' . esc_js( esc_html__( 'Please select a page', 'sunshine-photo-cart' ) ) . '" });
                    });</script>';
				break;

			case 'select_multi':
				$html .= '<select ' . ( ( $field['required'] ) ? 'required="required"' : '' ) . ' name="' . esc_attr( $meta_key ) . '[]" id="' . esc_attr( $field['id'] ) . '" multiple="multiple">';
				foreach ( $field['options'] as $k => $v ) {
					$html .= '<option ' . selected( ( $meta_value == $k ) || ( is_array( $meta_value ) && in_array( $k, $meta_value ) ), true, false ) . ' value="' . esc_attr( $k ) . '" />' . wp_kses_post( $v ) . '</label> ';
				}
				$html .= '</select> ';
				if ( $field['select2'] ) {
					$html .= '
                    <script type="text/javascript">jQuery(function () {
                        jQuery("#' . esc_js( $field['id'] ) . '").select2({ width: "350px", placeholder: "' . esc_js( $field['placeholder'] ) . '" });
                        });</script>';
				}
				break;

			case 'users':
				$html .= '<select ' . ( ( $field['required'] ) ? 'required="required"' : '' ) . ' name="' . esc_attr( $meta_key ) . '[]" id="' . esc_attr( $field['id'] ) . '" multiple="multiple">';
				$data  = array();
				if ( ! empty( $meta_value ) ) {
					foreach ( $meta_value as $user_id ) {
						$customer = sunshine_get_customer( $user_id );
						$html    .= '<option value="' . esc_attr( $user_id ) . '" selected="selected">' . esc_html( $customer->get_name() ) . ' (' . esc_html( $customer->get_email() ) . ')</option>';
					}
				}
				$html .= '</select> ';
				$html .= '
					<script type="text/javascript">
					jQuery(function () {
						jQuery("#' . esc_js( $field['id'] ) . '").select2({
							width: "350px",
							placeholder: "' . esc_js( $field['placeholder'] ) . '",
							ajax: {
					            url: ajaxurl,
					            dataType: "json",
					            delay: 250,
					            data: function(params) {
					                return {
					                    action: "sunshine_search_users",
					                    search: params.term,
										security: "' . esc_js( wp_create_nonce( 'sunshine_search_users' ) ) . '",
					                };
					            },
					            processResults: function(data) {
					                return {
					                    results: data
					                };
					            }
					        },
					        minimumInputLength: 3
						});
					});
					</script>';
				break;

			case 'galleries':
				$name     = $meta_key;
				$multiple = '';
				if ( $field['multiple'] ) {
					$name     = $meta_key . '[]';
					$multiple = 'multiple="multiple"';
				}
				$html .= '<select ' . ( ( $field['required'] ) ? 'required="required"' : '' ) . ' name="' . esc_attr( $name ) . '" id="' . esc_attr( $field['id'] ) . '" ' . $multiple . '>';
				$data  = array();
				if ( ! empty( $meta_value ) ) {
					foreach ( $meta_value as $gallery_id ) {
						$gallery = sunshine_get_gallery( $gallery_id );
						$html   .= '<option value="' . esc_attr( $gallery_id ) . '" selected="selected">' . esc_html( $gallery->get_name() ) . '</option>';
					}
				}
				$html .= '</select> ';
				$html .= '
					<script type="text/javascript">
					jQuery(function () {
						jQuery("#' . esc_js( $field['id'] ) . '").select2({
							width: "350px",
							allowClear: true,
							placeholder: "' . esc_js( $field['placeholder'] ) . '",
							ajax: {
					            url: ajaxurl,
					            dataType: "json",
					            delay: 250,
					            data: function(params) {
					                return {
					                    action: "sunshine_search_galleries",
										security: "' . esc_js( wp_create_nonce( 'sunshine_search_galleries' ) ) . '",
					                    search: params.term
					                };
					            },
					            processResults: function(data) {
					                return {
					                    results: data
					                };
					            }
					        },
					        minimumInputLength: 3
						});
					});
					</script>';
				break;

			case 'products':
				$name     = $meta_key;
				$multiple = '';
				if ( $field['multiple'] ) {
					$name     = $meta_key . '[]';
					$multiple = 'multiple="multiple"';
				}
				$html .= '<select ' . ( ( $field['required'] ) ? 'required="required"' : '' ) . ' name="' . esc_attr( $name ) . '" id="' . esc_attr( $field['id'] ) . '" ' . $multiple . '>';
				$data  = array();
				if ( ! empty( $meta_value ) ) {
					foreach ( $meta_value as $post_id ) {
						$this_post = sunshine_get_product( $post_id );
						$html     .= '<option value="' . esc_attr( $post_id ) . '" selected="selected">' . esc_html( $this_post->get_name() ) . '</option>';
					}
				}
				$html .= '</select> ';
				$html .= '
					<script type="text/javascript">jQuery(function () {

						jQuery("#' . esc_js( $field['id'] ) . '").select2({
							width: "350px",
							placeholder: "' . esc_js( $field['placeholder'] ) . '",
							ajax: {
					            url: ajaxurl,
					            dataType: "json",
					            delay: 250,
					            data: function(params) {
					                return {
					                    action: "sunshine_search_products",
										security: "' . esc_js( wp_create_nonce( 'sunshine_search_products' ) ) . '",
					                    search: params.term
					                };
					            },
					            processResults: function(data) {
					                return {
					                    results: data
					                };
					            }
					        },
					        minimumInputLength: 3
							});
						});
					</script>';
				break;

			case 'image':
				$image_thumb = '';
				if ( $meta_value ) {
					$image_thumb = wp_get_attachment_thumb_url( $data );
				}
				$html .= '<img id="' . esc_attr( $meta_key ) . '_preview" class="image_preview" src="' . esc_attr( $image_thumb ) . '" /><br/>' . "\n";
				$html .= '<input id="' . esc_attr( $meta_key ) . '_button" type="button" data-uploader_title="' . esc_html__( 'Upload an image', 'sunshine-photo-cart' ) . '" data-uploader_button_text="' . esc_html__( 'Use image', 'sunshine-photo-cart' ) . '" class="image_upload_button button" value="' . esc_html__( 'Upload new image', 'sunshine-photo-cart' ) . '" />' . "\n";
				$html .= '<input id="' . esc_attr( $meta_key ) . '_delete" type="button" class="image_delete_button button" value="' . esc_html__( 'Remove image', 'sunshine-photo-cart' ) . '" />' . "\n";
				$html .= '<input id="' . esc_attr( $meta_key ) . '" class="image_data_field" type="hidden" name="' . esc_attr( $meta_key ) . '" value="' . esc_attr( $meta_value ) . '"/>' . "\n";
				break;

			case 'color':
				?>
				<div class="color-picker" style="position:relative;">
					<input type="text" name="<?php echo esc_attr( $meta_key ); ?>" class="color" value="<?php echo esc_attr( $meta_value ); ?>" />
					<div style="position:absolute;background:#FFF;z-index:99;border-radius:100%;" class="colorpicker"></div>
				</div>
				<?php
				break;

			case 'date':
				$html .= '<input ' . ( ( $field['required'] ) ? 'required="required"' : '' ) . ' id="' . esc_attr( $field['id'] ) . '" type="date" name="' . esc_attr( $meta_key ) . '" value="' . esc_attr( $meta_value ) . '" />' . "\n";
				break;

			case 'date_time':
				$date = $time = '';
				if ( ! empty( $meta_value ) ) {
					$date = date( 'Y-m-d', $meta_value );
					$time = date( 'H:i', $meta_value );
				}
				$html .= '<input id="' . esc_attr( $field['id'] ) . '_date" type="date" name="' . esc_attr( $meta_key ) . '[date]" value="' . esc_attr( $date ) . '" />' . "\n";
				$html .= '<input id="' . esc_attr( $field['id'] ) . '_time" type="time" name="' . esc_attr( $meta_key ) . '[time]" value="' . esc_attr( $time ) . '" />' . "\n";
				break;

			case 'promo':
				$html .= $field['description'];
				$html .= '<p class="sunshine-meta-field-promo-links">';
				if ( ! SPC()->has_plan() ) {
					if ( ! empty( $field['url'] ) ) {
						$html .= '<a href="' . esc_url( $field['url'] ) . '?utm_source=plugin&utm_medium=link&utm_campaign=metapromo" target="_blank" class="button-primary">' . esc_html__( 'See upgrade options', 'sunshine-photo-cart' ) . '</a>';
					}
				} else {
					$html .= '<a href="' . esc_url( admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine-addons' ) ) . '" class="button-primary">' . esc_html__( 'Activate this add-on', 'sunshine-photo-cart' ) . '</a>';
				}
				if ( ! empty( $field['documentation'] ) ) {
					$html .= ' <a href="' . esc_url( $field['documentation'] ) . '" target="_blank" class="button">' . esc_html__( 'Learn more', 'sunshine-photo-cart' ) . '</a>';
				}
				$html .= '</p>';
				break;

			case 'dimensions':
				$w     = ( ! empty( $meta_value['w'] ) ) ? intval( $meta_value['w'] ) : '';
				$h     = ( ! empty( $meta_value['h'] ) ) ? intval( $meta_value['h'] ) : '';
				$html .= '<input type="number" min="0" name="' . esc_attr( $field['id'] ) . '[w]" step="1" style="width: 100px;" value="' . esc_attr( $w ) . '" />px &times; <input type="number" name="' . esc_attr( $field['id'] ) . '[h]" min="0" step="1" style="width: 100px;" value="' . esc_attr( $h ) . '" />px';
				break;

			case 'duration':
				$time          = ( ! empty( $meta_value['time'] ) ) ? intval( $meta_value['time'] ) : '';
				$interval      = ( ! empty( $meta_value['interval'] ) ) ? $meta_value['interval'] : '';
				$html         .= '<input type="number" min="0" name="' . esc_attr( $field['id'] ) . '[time]" step="1" style="width: 60px;" value="' . esc_attr( $time ) . '" />';
				$html         .= '<select name="' . esc_attr( $field['id'] ) . '[interval]">';
					$intervals = array(
						'month' => esc_html__( 'Month(s)', 'sunshine-photo-cart' ),
						'day'   => esc_html__( 'Day(s)', 'sunshine-photo-cart' ),
						'hour'  => esc_html__( 'Hour(s)', 'sunshine-photo-cart' ),
					);
					foreach ( $intervals as $key => $label ) {
						$html .= '<option value="' . esc_attr( $key ) . '" ' . selected( $key, $interval, false ) . '>' . esc_html( $label ) . '</option>';
					}
					$html .= '</select>';
				break;

			case 'html':
				$html .= $meta_value;
				break;

			default:
				do_action( 'sunshine_meta_' . $field['type'] . '_display', $field, $meta_value );
				// $html = apply_filters( 'sunshine_meta_' . $field['type'] . '_display', $html, $field, $meta_value );
				break;

		}

		$html_safe = $field['before'] . $html . $field['after'];

		switch ( $field['type'] ) {

			case 'promo':
				break;

			case 'checkbox':
			case 'radio':
			case 'checkbox_multi':
			case 'color':
			case 'header':
				if ( ! empty( $field['description'] ) ) {
					$html_safe .= '<span class="sunshine-settings-description">' . wp_kses_post( $field['description'] ) . '</span>';
				}
				break;

			default:
				if ( ! empty( $field['description'] ) ) {
					$html_safe .= '<div class="sunshine-settings-description">' . wp_kses_post( $field['description'] ) . '</div>';
				}
				break;
		}

		// Everything in the html_safe variable is escaped, so we can safely ignore the output not escaped warning.
		echo $html_safe; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	}

	private function display_conditions( $fields ) {

		if ( empty( $fields ) || ! is_array( $fields ) ) {
			return;
		}

		foreach ( $fields as $field ) {
			if ( empty( $field['conditions'] ) || ! is_array( $field['conditions'] ) ) {
				continue;
			}
			?>

			<script id="sunshine-conditions-<?php echo esc_attr( $field['id'] ); ?>">

			jQuery( document ).ready(function($){

			<?php
			$i = 0;
			foreach ( $field['conditions'] as $condition ) {
				if ( empty( $condition['compare'] ) || empty( $condition['value'] ) || empty( $condition['field'] ) || empty( $condition['action'] ) ) {
					continue;
				}
				if ( ! in_array( $condition['action'], array( 'show', 'hide' ) ) ) {
					continue;
				}
				if ( ! in_array( $condition['compare'], array( '==', '!=', '<', '>', '<=', '>=' ) ) ) {
					continue;
				}
				$i++;
				?>
					var condition_field_value_<?php echo esc_attr( $field['id'] ); ?> = sunshine_get_condition_field_value( '<?php echo esc_js( $condition['field'] ); ?>' );
					function condition_field_action_<?php echo esc_attr( $field['id'] . $i ); ?>( value ) {
						<?php
						$action_target          = ( isset( $condition['action_target'] ) ) ? $condition['action_target'] : '#sunshine-meta-fields-' . $field['id'];
						$true_action            = ( $condition['action'] == 'show' ) ? 'show' : 'hide';
						$false_action           = ( $condition['action'] == 'show' ) ? 'hide' : 'show';
						$comparison_string_safe = '';
						if ( is_array( $condition['value'] ) ) { // If value is an array, need to compare against each array value
							$comparison_strings = array();
							foreach ( $condition['value'] as $value ) {
								$comparison_strings[] = '( value ' . esc_js( $condition['compare'] ) . ' "' . esc_js( $value ) . '" )';
							}
							$comparison_string_safe = join( ' || ', $comparison_strings );
						} else {
							$comparison_string_safe = 'value ' . esc_js( $condition['compare'] ) . ' "' . esc_js( $condition['value'] ) . '"';
						}
						if ( is_array( $condition['value'] ) ) {
							?>
							var possible_values = [ '<?php echo esc_js( join( "', '", $condition['value'] ) ); ?>' ];
							if ( possible_values.includes( value ) ) {
								$( '<?php echo esc_js( $action_target ); ?>' ).<?php echo esc_js( $true_action ); ?>();
							} else {
								$( '<?php echo esc_js( $action_target ); ?>' ).<?php echo esc_js( $false_action ); ?>();
							}
						<?php } else { ?>
							if ( <?php echo $comparison_string_safe; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> ) {
								$( '<?php echo esc_js( $action_target ); ?>' ).<?php echo esc_js( $true_action ); ?>();
							} else {
								$( '<?php echo esc_js( $action_target ); ?>' ).<?php echo esc_js( $false_action ); ?>();
							}
						<?php } ?>
					}

					// Default action
					condition_field_action_<?php echo esc_attr( $field['id'] . $i ); ?>( condition_field_value_<?php echo esc_attr( $field['id'] ); ?> );

					// On change action
					$( '#<?php echo esc_js( $condition['field'] ); ?>, #sunshine-meta-fields-<?php echo esc_js( $condition['field'] ); ?> input[type="radio"], #sunshine-meta-fields-<?php echo esc_js( $condition['field'] ); ?> input[type="checkbox"]' ).on( 'change', function(){
						condition_field_value_<?php echo esc_attr( $field['id'] ); ?> = sunshine_get_condition_field_value( '<?php echo esc_js( $condition['field'] ); ?>' );
						condition_field_action_<?php echo esc_attr( $field['id'] . $i ); ?>( condition_field_value_<?php echo esc_attr( $field['id'] ); ?> );
					});

				<?php
			}
			echo '});</script>';
		}

	}

	private function display_conditionals( $fields ) {

		if ( empty( $fields ) || ! is_array( $fields ) ) {
			return;
		}

		foreach ( $fields as $field ) {
			if ( empty( $field['conditionals'] ) || ! is_array( $field['conditionals'] ) ) {
				continue;
			}
			echo '<script id="sunshine-conditionals-' . esc_attr( $field['id'] ) . '">';
			foreach ( $field['conditionals'] as $condition ) {
				if ( empty( $condition['field'] ) || empty( $condition['action'] ) || empty( $condition['value'] ) ) {
					continue;
				}
				?>
					jQuery( document ).on( 'change', '#sunshine-meta-fields-<?php echo esc_js( $condition['field'] ); ?> input', function(){
						check_conditional_for_<?php echo esc_js( $field['id'] ); ?>();
					});
					jQuery( document ).on( 'change', '#sunshine-meta-fields-<?php echo esc_js( $condition['field'] ); ?> select', function(){
						check_conditional_for_<?php echo esc_js( $field['id'] ); ?>();
					});

					function check_conditional_for_<?php echo esc_js( $field['id'] ); ?>() {
						if ( jQuery( '#sunshine-meta-fields-<?php echo esc_js( $condition['field'] ); ?> input' ) ) {
							var field_type = jQuery( '#sunshine-meta-fields-<?php echo esc_js( $condition['field'] ); ?> input' ).attr( 'type' );
							if ( field_type == 'radio' ) {
								var field_value = jQuery( '#sunshine-meta-fields-<?php echo esc_js( $condition['field'] ); ?> input:checked' ).val();
								do_conditional_for_<?php echo esc_js( $field['id'] ); ?>( field_value );
							} else if ( field_type == 'checkbox' ) {
								jQuery.each( jQuery( '#sunshine-meta-fields-<?php echo esc_js( $condition['field'] ); ?> input' ), function(){
									do_conditional_for_<?php echo esc_js( $field['id'] ); ?>( field_value );
								});
							}
						} else if ( jQuery( '#sunshine-meta-fields-<?php echo esc_js( $condition['field'] ); ?> select' ) ) {
							var field_value = jQuery( '#sunshine-meta-fields-<?php echo esc_js( $condition['field'] ); ?> select:selected' ).val();
							do_conditional_for_<?php echo esc_js( $field['id'] ); ?>( field_value );
						}
					}

					function do_conditional_for_<?php echo esc_js( $field['id'] ); ?>( field_value = '' ) {
						<?php
						if ( $condition['action'] == 'show' ) {
							$true_action  = 'show';
							$false_action = 'hide';
						} else {
							$true_action  = 'hide';
							$false_action = 'show';
						}
						?>
						if ( field_value == "<?php echo esc_js( $condition['value'] ); ?>" ) {
							jQuery( '#sunshine-meta-fields-<?php echo esc_js( $field['id'] ); ?>').<?php echo esc_js( $true_action ); ?>();
						} else {
							jQuery( '#sunshine-meta-fields-<?php echo esc_js( $field['id'] ); ?>').<?php echo esc_js( $false_action ); ?>();
						}
					}

					jQuery( document ).ready(function($){
						check_conditional_for_<?php echo esc_js( $field['id'] ); ?>();
					});

				<?php
			}
			echo '</script>';
		}

	}


	public function sanitize( $value, $type ) {

		switch ( $type ) {

			case 'textarea':
			case 'wysiwyg':
				$value = wp_kses( $value );
				break;

			case 'price':
				if ( is_array( $value ) ) {
					foreach ( $value as $key => $price ) {
						$price         = sanitize_text_field( $price );
						$value[ $key ] = $price;
						$value[ $key ] = str_replace( SPC()->get_option( 'currency_thousands_separator' ), '', $price );
					}
				}
				break;

			case 'text':
			case 'password':
			case 'checkbox':
			case 'select':
			case 'text_secret':
			case 'number':
			case 'range':
			case 'color':
			case 'radio':
			case 'date':
				$value = sanitize_text_field( $value );
				break;

			case 'select_multi':
			case 'checkbox_multi':
				if ( ! empty( $value ) ) {
					foreach ( $value as &$v ) {
						$v = sanitize_text_field( $v );
					}
				}
				break;

			case 'date_time':
				if ( empty( $value['date'] ) ) {
					$value = '';
				} else {
					$date = sanitize_text_field( $value['date'] );
					if ( empty( $value['time'] ) ) {
						$time = '00:00';
					} else {
						$time = sanitize_text_field( $value['time'] );
					}
					$value = strtotime( $date . ' ' . $time . ':00' );
				}
				break;

			case 'single_select_page':
			case 'image':
				$value = intval( $value );
				break;

			default:
				$value = apply_filters( 'sunshine_meta_' . $type . '_sanitize', $value );
				break;

		}

		return $value;

	}

	function date_format_php_to_js( $sFormat ) {
		switch ( $sFormat ) {
			// Predefined WP date formats
			case 'jS F Y':
				return( 'd MM yy' );
				break;
			case 'Y/m/d':
				return( 'yy/mm/dd' );
				break;
			case 'm/d/Y':
				return( 'mm/dd/yy' );
				break;
			case 'd/m/Y':
				return( 'dd/mm/yy' );
				break;
			default:
				return( 'MM d, yy' );
				break;
		}
	}


	public function enqueue( $page ) { }

	public function search_users() {

		if ( ! wp_verify_nonce( $_REQUEST['security'], 'sunshine_search_users' ) || ! current_user_can( 'sunshine_manage_options' ) ) {
			return false;
		}

		$search = sanitize_text_field( $_GET['search'] );

		$args  = array(
			'search'         => "*{$search}*",
			'search_columns' => array(
				'user_login',
				'user_nicename',
				'user_email',
				'user_url',
			),
			'number'         => -1,
		);
		$users = sunshine_get_customers( $args );

		$meta_search_customers = sunshine_get_customers(
			array(
				'meta_query' => array(
					'relation' => 'OR',
					array(
						'key'     => 'first_name',
						'value'   => $search,
						'compare' => 'LIKE',
					),
					array(
						'key'     => 'last_name',
						'value'   => $search,
						'compare' => 'LIKE',
					),
				),
			)
		);
		if ( ! empty( $meta_search_customers ) ) {
			$users = array_merge( $users, $meta_search_customers );
		}

		$data = array();

		if ( ! empty( $users ) ) {
			foreach ( $users as $user ) {
				$data[ $user->get_id() ] = array(
					'id'   => $user->get_id(),
					'text' => $user->get_name() . ' (' . $user->get_email() . ')',
				);
			}
			$data = array_values( $data ); // Reset to default array as user id as key seems to break select2.
		}

		echo json_encode( $data );
		die();

	}

	public function search_galleries() {

		if ( ! wp_verify_nonce( $_REQUEST['security'], 'sunshine_search_galleries' ) || ! current_user_can( 'sunshine_manage_options' ) ) {
			return false;
		}

		$search = sanitize_text_field( $_REQUEST['search'] );

		$args      = array(
			's'      => $search,
			'number' => -1,
		);
		$galleries = sunshine_get_galleries( $args, 'all' );

		$data = array();

		if ( ! empty( $galleries ) ) {
			foreach ( $galleries as $gallery ) {
				$data[] = array(
					'id'   => $gallery->get_id(),
					'text' => $gallery->get_name(),
				);
			}
		}

		echo json_encode( $data );
		die();

	}

	public function search_products() {

		if ( ! wp_verify_nonce( $_REQUEST['security'], 'sunshine_search_products' ) || ! current_user_can( 'sunshine_manage_options' ) ) {
			return false;
		}

		$search = sanitize_text_field( $_REQUEST['search'] );

		$args     = array(
			's'      => $search,
			'number' => -1,
		);
		$products = sunshine_get_products( '', '', '', $args, true );

		$data = array();

		if ( ! empty( $products ) ) {
			foreach ( $products as $product ) {
				$data[] = array(
					'id'   => $product->get_id(),
					'text' => $product->get_name(),
				);
			}
		}

		echo json_encode( $data );
		die();

	}

}

// $sunshine_admin_meta_boxes = new Sunshine_Admin_Meta_Boxes();
