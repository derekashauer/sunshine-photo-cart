<?php
/**
 * Quick Edit and Bulk Edit support for Sunshine gallery and product meta fields.
 *
 * Fields opt in by setting `'quick_edit' => true` and/or `'bulk_edit' => true`
 * inside the existing `set_options()` definitions on the gallery and product
 * admin meta box classes. This class walks those definitions, renders the
 * matching inputs into Quick Edit / Bulk Edit panels, and persists submissions
 * through the same sanitize and validate filters used by the singular edit
 * screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sunshine_Admin_Quick_Bulk_Edit {

	const ANCHOR_COLUMN = 'sunshine_qbe';
	const NO_CHANGE     = '__sunshine_no_change__';

	/**
	 * @var string[]
	 */
	private $post_types = array( 'sunshine-gallery', 'sunshine-product' );

	public function __construct() {
		foreach ( $this->post_types as $post_type ) {
			add_filter( "manage_edit-{$post_type}_columns", array( $this, 'add_anchor_column' ) );
			add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'render_anchor_column' ), 99, 2 );
		}
		add_action( 'quick_edit_custom_box', array( $this, 'render_quick_edit_box' ), 10, 2 );
		add_action( 'bulk_edit_custom_box', array( $this, 'render_bulk_edit_box' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_head', array( $this, 'admin_head_css' ) );
		add_action( 'save_post', array( $this, 'save' ), 20, 2 );
	}

	/**
	 * Get the meta box instance for a given post type.
	 *
	 * @param string $post_type
	 * @return Sunshine_Admin_Meta_Boxes|null
	 */
	private function get_meta_box_instance( $post_type ) {
		global $sunshine_admin_meta_boxes_gallery, $sunshine_admin_meta_boxes_product;
		if ( 'sunshine-gallery' === $post_type ) {
			return $sunshine_admin_meta_boxes_gallery;
		}
		if ( 'sunshine-product' === $post_type ) {
			return $sunshine_admin_meta_boxes_product;
		}
		return null;
	}

	/**
	 * Walk the registered field definitions for a post type and return the flat
	 * list of fields flagged for the requested mode. Mirrors the iteration shape
	 * used by Sunshine_Admin_Meta_Boxes::save_meta_boxes().
	 *
	 * @param string $post_type
	 * @param string $mode 'quick' or 'bulk'.
	 * @return array
	 */
	private function get_fields( $post_type, $mode ) {
		$instance = $this->get_meta_box_instance( $post_type );
		if ( ! $instance ) {
			return array();
		}
		$flag    = ( 'quick' === $mode ) ? 'quick_edit' : 'bulk_edit';
		$options = $instance->set_options( array() );

		$fields = array();
		if ( ! is_array( $options ) ) {
			return $fields;
		}
		foreach ( $options as $meta_box_id => $tabs ) {
			if ( ! is_array( $tabs ) ) {
				continue;
			}
			foreach ( $tabs as $tab ) {
				if ( empty( $tab['fields'] ) || ! is_array( $tab['fields'] ) ) {
					continue;
				}
				foreach ( $tab['fields'] as $field ) {
					if ( empty( $field['id'] ) || empty( $field[ $flag ] ) ) {
						continue;
					}
					$fields[ $field['id'] ] = $field;
				}
			}
		}
		return array_values( $fields );
	}

	public function add_anchor_column( $columns ) {
		$columns[ self::ANCHOR_COLUMN ] = '';
		return $columns;
	}

	public function render_anchor_column( $column, $post_id ) {
		if ( self::ANCHOR_COLUMN !== $column ) {
			return;
		}
		$post_type = get_post_type( $post_id );
		if ( ! in_array( $post_type, $this->post_types, true ) ) {
			return;
		}

		// Union of quick + bulk fields so JS has data for both modes.
		$fields  = array();
		$by_id   = array();
		$flagged = array_merge( $this->get_fields( $post_type, 'quick' ), $this->get_fields( $post_type, 'bulk' ) );
		foreach ( $flagged as $field ) {
			if ( isset( $by_id[ $field['id'] ] ) ) {
				continue;
			}
			$by_id[ $field['id'] ] = true;
			$fields[]              = $field;
		}

		echo '<div class="sunshine-qbe-data" style="display:none;" aria-hidden="true">';
		foreach ( $fields as $field ) {
			$value = get_post_meta( $post_id, $field['id'], true );
			$type  = $field['type'];

			if ( 'price' === $type ) {
				if ( ! is_array( $value ) ) {
					$value = array();
				}
				foreach ( $value as $level_id => $price ) {
					echo '<span data-field="' . esc_attr( $field['id'] ) . '[' . esc_attr( $level_id ) . ']" data-value="' . esc_attr( $price ) . '"></span>';
				}
			} elseif ( 'date_time' === $type ) {
				$date = $time = '';
				if ( ! empty( $value ) ) {
					$date = gmdate( 'Y-m-d', (int) $value );
					$time = gmdate( 'H:i', (int) $value );
				}
				echo '<span data-field="' . esc_attr( $field['id'] ) . '[date]" data-value="' . esc_attr( $date ) . '"></span>';
				echo '<span data-field="' . esc_attr( $field['id'] ) . '[time]" data-value="' . esc_attr( $time ) . '"></span>';
			} elseif ( 'users' === $type ) {
				$user_data = array();
				if ( is_array( $value ) ) {
					foreach ( $value as $user_id ) {
						$user_id = (int) $user_id;
						if ( ! $user_id ) {
							continue;
						}
						$customer = function_exists( 'sunshine_get_customer' ) ? sunshine_get_customer( $user_id ) : null;
						if ( $customer ) {
							$user_data[] = array(
								'id'   => $user_id,
								'text' => $customer->get_name() . ' (' . $customer->get_email() . ')',
							);
						}
					}
				}
				echo '<span data-field="' . esc_attr( $field['id'] ) . '" data-users="' . esc_attr( wp_json_encode( $user_data ) ) . '"></span>';
			} else {
				if ( is_array( $value ) ) {
					$value = '';
				}
				echo '<span data-field="' . esc_attr( $field['id'] ) . '" data-value="' . esc_attr( $value ) . '"></span>';
			}
		}
		echo '</div>';
	}

	public function render_quick_edit_box( $column_name, $post_type ) {
		if ( self::ANCHOR_COLUMN !== $column_name || ! in_array( $post_type, $this->post_types, true ) ) {
			return;
		}
		$fields = $this->get_fields( $post_type, 'quick' );
		if ( empty( $fields ) ) {
			return;
		}
		$this->render_fieldset( $fields, 'quick' );
	}

	public function render_bulk_edit_box( $column_name, $post_type ) {
		if ( self::ANCHOR_COLUMN !== $column_name || ! in_array( $post_type, $this->post_types, true ) ) {
			return;
		}
		$fields = $this->get_fields( $post_type, 'bulk' );
		if ( empty( $fields ) ) {
			return;
		}
		$this->render_fieldset( $fields, 'bulk' );
	}

	private function render_fieldset( $fields, $mode ) {
		$heading = ( 'quick' === $mode )
			? __( 'Sunshine Settings', 'sunshine-photo-cart' )
			: __( 'Sunshine Bulk Settings', 'sunshine-photo-cart' );
		?>
		<fieldset class="inline-edit-col-right inline-edit-sunshine-qbe">
			<div class="inline-edit-col">
				<h4><?php echo esc_html( $heading ); ?></h4>
				<?php wp_nonce_field( 'sunshine_qbe_save', 'sunshine_qbe_nonce' ); ?>
				<input type="hidden" name="sunshine_qbe_mode" value="<?php echo esc_attr( $mode ); ?>" />
				<?php foreach ( $fields as $field ) : ?>
					<?php $this->render_field( $field, $mode ); ?>
				<?php endforeach; ?>
			</div>
		</fieldset>
		<?php
	}

	private function render_field( $field, $mode ) {
		$id          = $field['id'];
		$type        = $field['type'];
		$name        = isset( $field['name'] ) ? $field['name'] : $id;
		$description = isset( $field['description'] ) ? $field['description'] : '';

		$wrapper_classes = array( 'sunshine-qbe-row', 'sunshine-qbe-row-' . $type, 'sunshine-qbe-row--' . $id );

		echo '<div class="' . esc_attr( implode( ' ', $wrapper_classes ) ) . '" data-sunshine-qbe-field="' . esc_attr( $id ) . '">';
		echo '<label class="sunshine-qbe-label">';
		echo '<span class="title">' . esc_html( $name ) . '</span>';
		echo '<span class="input-text-wrap">';

		switch ( $type ) {

			case 'text':
				$placeholder = ( 'bulk' === $mode ) ? __( '— No change —', 'sunshine-photo-cart' ) : '';
				echo '<input type="text" name="' . esc_attr( $id ) . '" value="" placeholder="' . esc_attr( $placeholder ) . '" />';
				break;

			case 'number':
				$placeholder = ( 'bulk' === $mode ) ? __( '— No change —', 'sunshine-photo-cart' ) : '';
				$min         = isset( $field['min'] ) ? $field['min'] : '';
				$max         = isset( $field['max'] ) ? $field['max'] : '';
				$step        = isset( $field['step'] ) ? $field['step'] : '';
				echo '<input type="number" name="' . esc_attr( $id ) . '" value="" placeholder="' . esc_attr( $placeholder ) . '"';
				if ( '' !== $min ) {
					echo ' min="' . esc_attr( $min ) . '"';
				}
				if ( '' !== $max ) {
					echo ' max="' . esc_attr( $max ) . '"';
				}
				if ( '' !== $step ) {
					echo ' step="' . esc_attr( $step ) . '"';
				}
				echo ' />';
				break;

			case 'checkbox':
				if ( 'bulk' === $mode ) {
					echo '<select name="' . esc_attr( $id ) . '">';
					echo '<option value="' . esc_attr( self::NO_CHANGE ) . '">' . esc_html__( '— No change —', 'sunshine-photo-cart' ) . '</option>';
					echo '<option value="1">' . esc_html__( 'Yes', 'sunshine-photo-cart' ) . '</option>';
					echo '<option value="0">' . esc_html__( 'No', 'sunshine-photo-cart' ) . '</option>';
					echo '</select>';
				} else {
					echo '<input type="hidden" name="' . esc_attr( $id ) . '" value="0" />';
					echo '<input type="checkbox" name="' . esc_attr( $id ) . '" value="1" />';
					if ( ! empty( $description ) ) {
						echo '<span class="sunshine-qbe-description-inline description">' . wp_kses_post( $description ) . '</span>';
					}
				}
				break;

			case 'select':
				$options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
				echo '<select name="' . esc_attr( $id ) . '">';
				if ( 'bulk' === $mode ) {
					echo '<option value="' . esc_attr( self::NO_CHANGE ) . '">' . esc_html__( '— No change —', 'sunshine-photo-cart' ) . '</option>';
				}
				foreach ( $options as $key => $label ) {
					echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</option>';
				}
				echo '</select>';
				break;

			case 'radio':
				// Render radios as a select for compactness inside inline editor.
				$options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
				echo '<select name="' . esc_attr( $id ) . '">';
				if ( 'bulk' === $mode ) {
					echo '<option value="' . esc_attr( self::NO_CHANGE ) . '">' . esc_html__( '— No change —', 'sunshine-photo-cart' ) . '</option>';
				}
				foreach ( $options as $key => $label ) {
					echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</option>';
				}
				echo '</select>';
				break;

			case 'date_time':
				$placeholder = ( 'bulk' === $mode ) ? __( '— No change —', 'sunshine-photo-cart' ) : '';
				echo '<input type="date" name="' . esc_attr( $id ) . '[date]" value="" placeholder="' . esc_attr( $placeholder ) . '" /> ';
				echo '<input type="time" name="' . esc_attr( $id ) . '[time]" value="" />';
				break;

			case 'users':
				$placeholder = ( 'bulk' === $mode )
					? __( '— No change —', 'sunshine-photo-cart' )
					: __( 'Search customers', 'sunshine-photo-cart' );
				echo '<select name="' . esc_attr( $id ) . '[]" multiple="multiple" class="sunshine-qbe-users" data-placeholder="' . esc_attr( $placeholder ) . '" data-search-nonce="' . esc_attr( wp_create_nonce( 'sunshine_search_users' ) ) . '"></select>';
				break;

			case 'price':
				$currency     = function_exists( 'sunshine_get_currency' ) ? sunshine_get_currency() : '';
				$position     = SPC()->get_option( 'currency_symbol_position' );
				$price_levels = function_exists( 'sunshine_get_price_levels' ) ? sunshine_get_price_levels() : array();
				$placeholder  = ( 'bulk' === $mode ) ? __( '— No change —', 'sunshine-photo-cart' ) : '';
				if ( ! empty( $price_levels ) ) {
					echo '<span class="sunshine-qbe-price-levels">';
					foreach ( $price_levels as $price_level ) {
						$level_id    = $price_level->get_id();
						$level_name  = $price_level->get_name();
						$input_html  = '<input type="text" size="6" name="' . esc_attr( $id ) . '[' . esc_attr( $level_id ) . ']" value="" placeholder="' . esc_attr( $placeholder ) . '" />';
						$symbol      = function_exists( 'sunshine_currency_symbol' ) ? sunshine_currency_symbol( $currency ) : '';
						$input_block = ( 'left' === $position ) ? $symbol . $input_html : $input_html . $symbol;
						if ( count( $price_levels ) > 1 ) {
							echo '<span class="sunshine-qbe-price-level"><small>' . esc_html( $level_name ) . '</small> ' . $input_block . '</span> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						} else {
							echo $input_block; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						}
					}
					echo '</span>';
				}
				break;

			default:
				// Unsupported types: render nothing but keep the row so JS doesn't break.
				echo '<em>' . esc_html__( 'Edit on the full screen.', 'sunshine-photo-cart' ) . '</em>';
				break;
		}

		echo '</span>';
		echo '</label>';

		// Checkbox quick mode renders the description inline above; everything else gets a description below.
		$description_inline = ( 'checkbox' === $type && 'quick' === $mode );
		if ( ! empty( $description ) && 'quick' === $mode && ! $description_inline ) {
			echo '<span class="sunshine-qbe-description description">' . wp_kses_post( $description ) . '</span>';
		}

		echo '</div>';
	}

	public function admin_head_css() {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'edit-sunshine-gallery', 'edit-sunshine-product' ), true ) ) {
			return;
		}
		?>
		<style id="sunshine-qbe-styles">
			.column-<?php echo esc_attr( self::ANCHOR_COLUMN ); ?>,
			th.manage-column.column-<?php echo esc_attr( self::ANCHOR_COLUMN ); ?> { display: none !important; }
			.inline-edit-sunshine-qbe { margin-top: 1em; }
			.inline-edit-sunshine-qbe h4 { margin: 0 0 .8em; padding: 0; }
			.inline-edit-sunshine-qbe .sunshine-qbe-row { margin: 0 0 10px; }
			.inline-edit-sunshine-qbe .sunshine-qbe-label { display: flex; align-items: center; gap: 12px; margin: 0; }
			.inline-edit-sunshine-qbe .sunshine-qbe-label .title { flex: 0 0 9em; line-height: 1.3; }
			.inline-edit-row fieldset.inline-edit-sunshine-qbe label span.input-text-wrap { margin-left: 0; }
			.inline-edit-sunshine-qbe .sunshine-qbe-label .input-text-wrap { flex: 1 1 auto; min-width: 0; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
			.inline-edit-sunshine-qbe .sunshine-qbe-label input[type="text"],
			.inline-edit-sunshine-qbe .sunshine-qbe-label input[type="number"],
			.inline-edit-sunshine-qbe .sunshine-qbe-label select { vertical-align: middle; max-width: 100%; }
			.inline-edit-sunshine-qbe .sunshine-qbe-row-text .input-text-wrap input,
			.inline-edit-sunshine-qbe .sunshine-qbe-row-number .input-text-wrap input,
			.inline-edit-sunshine-qbe .sunshine-qbe-row-select select,
			.inline-edit-sunshine-qbe .sunshine-qbe-row-radio select { width: 100%; }
			.inline-edit-sunshine-qbe .sunshine-qbe-description { display: block; margin: 4px 0 0 calc(9em + 12px); color: #646970; font-style: italic; font-size: 12px; }
			.inline-edit-sunshine-qbe .sunshine-qbe-description-inline { color: #646970; font-style: italic; font-size: 12px; margin-left: 4px; }
			.inline-edit-sunshine-qbe .sunshine-qbe-price-levels { display: flex; flex-direction: column; gap: 6px; width: 100%; }
			.inline-edit-sunshine-qbe .sunshine-qbe-price-level { display: flex; align-items: center; gap: 6px; }
			.inline-edit-sunshine-qbe .sunshine-qbe-price-level small { flex: 0 0 8em; color: #50575e; }
			.inline-edit-sunshine-qbe .sunshine-qbe-price-level input { width: 8em; }
			.inline-edit-sunshine-qbe .sunshine-qbe-users { width: 100% !important; min-width: 220px; }
			.inline-edit-sunshine-qbe .select2-container { width: 100% !important; }
			.inline-edit-sunshine-qbe .select2-container .select2-selection--multiple { min-height: 30px; }
		</style>
		<?php
	}

	public function enqueue() {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'edit-sunshine-gallery', 'edit-sunshine-product' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'select2' );
		wp_enqueue_script( 'select2' );
		wp_enqueue_script(
			'sunshine-qbe',
			SUNSHINE_PHOTO_CART_URL . 'assets/js/admin-quick-bulk-edit.js',
			array( 'jquery', 'inline-edit-post', 'select2' ),
			defined( 'SUNSHINE_PHOTO_CART_VERSION' ) ? SUNSHINE_PHOTO_CART_VERSION : null,
			true
		);
		wp_localize_script(
			'sunshine-qbe',
			'sunshineQBE',
			array(
				'noChange' => self::NO_CHANGE,
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			)
		);
	}

	public function save( $post_id, $post ) {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( empty( $_REQUEST['sunshine_qbe_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_REQUEST['sunshine_qbe_nonce'] ), 'sunshine_qbe_save' ) ) {
			return;
		}
		$post_type = $post ? $post->post_type : get_post_type( $post_id );
		if ( ! in_array( $post_type, $this->post_types, true ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$mode = ( isset( $_REQUEST['sunshine_qbe_mode'] ) && 'bulk' === $_REQUEST['sunshine_qbe_mode'] ) ? 'bulk' : 'quick';

		$fields = $this->get_fields( $post_type, $mode );
		if ( empty( $fields ) ) {
			return;
		}

		$instance = $this->get_meta_box_instance( $post_type );
		if ( ! $instance ) {
			return;
		}

		foreach ( $fields as $field ) {
			$key  = $field['id'];
			$type = $field['type'];

			if ( 'date_time' === $type ) {
				if ( ! isset( $_REQUEST[ $key ] ) || ! is_array( $_REQUEST[ $key ] ) ) {
					continue;
				}
				$value = wp_unslash( $_REQUEST[ $key ] );
				if ( 'bulk' === $mode && empty( $value['date'] ) ) {
					continue;
				}
			} elseif ( 'price' === $type ) {
				if ( ! isset( $_REQUEST[ $key ] ) || ! is_array( $_REQUEST[ $key ] ) ) {
					continue;
				}
				$value         = wp_unslash( $_REQUEST[ $key ] );
				$has_any_value = false;
				foreach ( $value as $level_value ) {
					if ( '' !== trim( (string) $level_value ) ) {
						$has_any_value = true;
						break;
					}
				}
				if ( 'bulk' === $mode && ! $has_any_value ) {
					continue;
				}
			} elseif ( 'users' === $type ) {
				$value = isset( $_REQUEST[ $key ] ) ? wp_unslash( $_REQUEST[ $key ] ) : array();
				if ( ! is_array( $value ) ) {
					$value = array();
				}
				$value = array_values( array_filter( array_map( 'intval', $value ) ) );
				if ( 'bulk' === $mode && empty( $value ) ) {
					continue;
				}
				delete_post_meta( $post_id, $key );
				update_post_meta( $post_id, $key, $value );
				continue;
			} elseif ( 'checkbox' === $type ) {
				if ( 'bulk' === $mode ) {
					if ( ! isset( $_REQUEST[ $key ] ) || self::NO_CHANGE === $_REQUEST[ $key ] ) {
						continue;
					}
					$value = (string) wp_unslash( $_REQUEST[ $key ] );
				} else {
					$value = ! empty( $_REQUEST[ $key ] ) ? '1' : '';
				}
			} else {
				if ( ! isset( $_REQUEST[ $key ] ) ) {
					continue;
				}
				$value = wp_unslash( $_REQUEST[ $key ] );
				if ( 'bulk' === $mode ) {
					if ( self::NO_CHANGE === $value ) {
						continue;
					}
					if ( in_array( $type, array( 'text', 'number' ), true ) && '' === trim( (string) $value ) ) {
						continue;
					}
				}
			}

			$value = $instance->sanitize( $value, $type );
			$value = apply_filters( 'sunshine_meta_' . $key . '_validate', $value );

			delete_post_meta( $post_id, $key );
			update_post_meta( $post_id, $key, $value );
		}
	}
}

new Sunshine_Admin_Quick_Bulk_Edit();
