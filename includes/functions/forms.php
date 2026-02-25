<?php

/**
 * Get allowed HTML tags for Sunshine form descriptions
 *
 * Allows rich HTML including form elements in field descriptions
 * while maintaining security through WordPress's wp_kses function.
 *
 * @return array Array of allowed HTML tags and their attributes
 */
function sunshine_form_description_allowed_html() {
	$allowed = wp_kses_allowed_html( 'post' );

	// Add form elements
	$allowed['input'] = array(
		'type'        => true,
		'name'        => true,
		'value'       => true,
		'id'          => true,
		'class'       => true,
		'placeholder' => true,
		'required'    => true,
		'checked'     => true,
		'disabled'    => true,
		'readonly'    => true,
		'min'         => true,
		'max'         => true,
		'step'        => true,
		'data-*'      => true,
	);

	$allowed['textarea'] = array(
		'name'        => true,
		'id'          => true,
		'class'       => true,
		'placeholder' => true,
		'rows'        => true,
		'cols'        => true,
		'required'    => true,
	);

	$allowed['select'] = array(
		'name'     => true,
		'id'       => true,
		'class'    => true,
		'required' => true,
		'multiple' => true,
	);

	$allowed['option'] = array(
		'value'    => true,
		'selected' => true,
	);

	$allowed['label'] = array(
		'for'   => true,
		'class' => true,
	);

	$allowed['button'] = array(
		'type'  => true,
		'class' => true,
		'id'    => true,
	);

	return apply_filters( 'sunshine_form_description_allowed_html', $allowed );
}

function sunshine_form_field( $id, $field, $value = '', $echo = true ) {

	if ( isset( $field['visible'] ) && ! $field['visible'] ) {
		return;
	}

	if ( ! empty( $field['default'] ) ) {
		$value = $field['default'];
	}

	$defaults = array(
		'id'           => $id,
		'name'         => '',
		'description'  => '',
		'type'         => '',
		'min'          => '',
		'max'          => '',
		'step'         => '',
		'default'      => '',
		'placeholder'  => '',
		'select2'      => false,
		'multiple'     => false,
		'options'      => array(),
		'before'       => '',
		'after'        => '',
		'html'         => '',
		'required'     => false,
		'autocomplete' => '',
		'class'        => '',
	);
	$field    = wp_parse_args( $field, $defaults );

	$html_safe = '';

	switch ( $field['type'] ) {

		case 'legend':
			$html_safe .= '<legend>' . esc_html( $field['name'] ) . '</legend>';
			break;

		case 'email':
		case 'tel':
		case 'text':
		case 'password':
			$html_safe .= '<input ' . ( ( $field['required'] ) ? 'required="required"' : '' ) . ' autocomplete="' . esc_attr( $field['autocomplete'] ) . '" id="' . esc_attr( $field['id'] ) . '" type="' . $field['type'] . '" name="' . esc_attr( $id ) . '" placeholder="' . esc_attr( $field['placeholder'] ) . '" value="' . esc_attr( $value ) . '" />' . "\n";
			break;

		case 'number':
			$html_safe .= '<input ' . ( ( $field['required'] ) ? 'required="required"' : '' ) . ' autocomplete="' . esc_attr( $field['autocomplete'] ) . '" id="' . esc_attr( $field['id'] ) . '" type="' . $field['type'] . '" name="' . esc_attr( $id ) . '" placeholder="' . esc_attr( $field['placeholder'] ) . '" min="' . esc_attr( $field['min'] ) . '" max="' . esc_attr( $field['max'] ) . '" step="' . esc_attr( $field['step'] ) . '" value="' . esc_attr( $value ) . '" />' . "\n";
			break;

		case 'date':
			$min_attr   = ! empty( $field['min'] ) ? ' min="' . esc_attr( $field['min'] ) . '"' : '';
			$max_attr   = ! empty( $field['max'] ) ? ' max="' . esc_attr( $field['max'] ) . '"' : '';
			$html_safe .= '<input ' . ( ( $field['required'] ) ? 'required="required"' : '' ) . ' autocomplete="' . esc_attr( $field['autocomplete'] ) . '" id="' . esc_attr( $field['id'] ) . '" type="date" name="' . esc_attr( $id ) . '" value="' . esc_attr( $value ) . '"' . $min_attr . $max_attr . ' />' . "\n";
			break;

		case 'date_time':
			$min_attr = ! empty( $field['min'] ) ? ' min="' . esc_attr( $field['min'] ) . '"' : '';
			$max_attr = ! empty( $field['max'] ) ? ' max="' . esc_attr( $field['max'] ) . '"' : '';
			// Default step to 3600 (1 hour) to limit selection to hours only, unless explicitly set
			$step_attr  = ! empty( $field['step'] ) ? ' step="' . esc_attr( $field['step'] ) . '"' : ' step="3600"';
			$html_safe .= '<input ' . ( ( $field['required'] ) ? 'required="required"' : '' ) . ' autocomplete="' . esc_attr( $field['autocomplete'] ) . '" id="' . esc_attr( $field['id'] ) . '" type="datetime-local" name="' . esc_attr( $id ) . '" value="' . esc_attr( $value ) . '"' . $min_attr . $max_attr . $step_attr . ' />' . "\n";
			break;

		case 'textarea':
			$html_safe .= '<textarea autocomplete="' . esc_attr( $field['autocomplete'] ) . '" ' . ( ( $field['required'] ) ? 'required="required"' : '' ) . ' id="' . esc_attr( $field['id'] ) . '" rows="5" cols="50" name="' . esc_attr( $id ) . '" placeholder="' . esc_attr( $field['placeholder'] ) . '">' . wp_kses_post( $value ) . '</textarea>' . "\n";
			break;

		case 'checkbox':
			$html_safe .= '<label><input id="' . esc_attr( $field['id'] ) . '" type="' . esc_attr( $field['type'] ) . '" name="' . esc_attr( $id ) . '" value="yes" ' . checked( ! empty( $value ), true, false ) . '/> ' . $field['name'] . '</label>' . "\n";
			break;

		case 'checkbox_multi':
			foreach ( $field['options'] as $k => $v ) {
				$html_safe .= '<label for="' . esc_attr( $field['id'] . '_' . $k ) . '" class="sunshine--form--field--checkbox-option"><input type="checkbox" ' . ( ( $field['required'] ) ? 'required="required"' : '' ) . ' ' . checked( ( is_array( $value ) && in_array( $k, $value ) ), true, false ) . ' name="' . esc_attr( $field['id'] ) . '[]" value="' . esc_attr( $k ) . '" id="' . esc_attr( $field['id'] . '_' . $k ) . '" /> ' . wp_kses_post( $v );
				$html_safe .= '</label>';
			}
			break;

		case 'radio':
			foreach ( $field['options'] as $k => $v ) {
				$html_safe .= '<label for="' . esc_attr( $field['id'] . '_' . $k ) . '" class="sunshine--form--field--radio-option" id="sunshine--form--field--radio-option--' . esc_attr( $field['id'] ) . '--' . esc_attr( sanitize_title( $k ) ) . '"><input ' . ( ( $field['required'] ) ? 'required="required"' : '' ) . ' type="radio" ' . checked( $k, $value, false ) . ' name="' . esc_attr( $field['id'] ) . '" value="' . esc_attr( $k ) . '" id="' . esc_attr( $field['id'] . '_' . $k ) . '" /> ';
				if ( is_array( $v ) ) {
					if ( ! empty( $v['label'] ) ) {
						$html_safe .= wp_kses_post( $v['label'] );
					}
					if ( ! empty( $v['description'] ) ) {
						// Skip sanitization for payment_method field as it contains plugin-controlled code.
						// Payment gateway fields include JavaScript and complex HTML from trusted payment method classes.
						if ( 'payment_method' === $field['id'] ) {
							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Payment gateway HTML is plugin-controlled, not user input.
							$html_safe .= '<span class="sunshine--form--field--label-description">' . $v['description'] . '</span>';
						} else {
							$html_safe .= '<span class="sunshine--form--field--label-description">' . wp_kses( $v['description'], sunshine_form_description_allowed_html() ) . '</span>';
						}
					}
				} else {
					$html_safe .= wp_kses_post( $v );
				}
				$html_safe .= '</label>';
			}
			break;

		case 'select':
			$html_safe .= '<select autocomplete="' . esc_attr( $field['autocomplete'] ) . '" ' . ( ( $field['required'] ) ? 'required="required"' : '' ) . ' name="' . esc_attr( $id ) . '" id="' . esc_attr( $field['id'] ) . '" placeholder="' . esc_attr( $field['placeholder'] ) . '">';
			foreach ( $field['options'] as $k => $v ) {
				$html_safe .= '<option ' . selected( ( $value == $k ) || ( is_array( $value ) && in_array( $k, $value ) ), true, false ) . ' value="' . esc_attr( $k ) . '">' . wp_kses_post( $v ) . '</option>';
			}
			$html_safe .= '</select> ';
			if ( $field['select2'] ) {
				$html_safe .= '
				<script type="text/javascript">jQuery(function () {
					jQuery("#' . esc_js( $field['id'] ) . '").select2({ width: "350px", placeholder: "' . esc_js( $field['placeholder'] ) . '" });
					});</script>';
			}
			break;

		case 'country':
			$html_safe .= '<select autocomplete="' . esc_attr( $field['autocomplete'] ) . '" ' . ( ( $field['required'] ) ? 'required="required"' : '' ) . ' name="' . esc_attr( $id ) . '" id="' . esc_attr( $field['id'] ) . '" placeholder="' . esc_attr( $field['placeholder'] ) . '">';
			foreach ( $field['options'] as $k => $v ) {
				$html_safe .= '<option ' . selected( ( $value == $k ) || ( is_array( $value ) && in_array( $k, $value ) ), true, false ) . ' value="' . esc_attr( $k ) . '">' . wp_kses_post( $v ) . '</option>';
			}
			$html_safe .= '</select> ';
			if ( $field['select2'] ) {
				$html_safe .= '
				<script type="text/javascript">jQuery(function () {
					jQuery("#' . esc_js( $field['id'] ) . '").select2({ width: "350px", placeholder: "' . esc_js( $field['placeholder'] ) . '" });
					});</script>';
			}
			break;

		case 'select_multi':
			$html_safe .= '<select autocomplete="' . esc_attr( $field['autocomplete'] ) . '" ' . ( ( $field['required'] ) ? 'required="required"' : '' ) . ' name="' . esc_attr( $id ) . '[]" id="' . esc_attr( $field['id'] ) . '" multiple="multiple">';
			foreach ( $field['options'] as $k => $v ) {
				$html_safe .= '<option ' . selected( in_array( $k, $value ), true, false ) . ' value="' . esc_attr( $k ) . '" />' . wp_kses_post( $v ) . '</label> ';
			}
			$html_safe .= '</select> ';
			break;

		case 'html':
			$html_safe .= $field['html'];
			break;

		case 'submit':
			$html_safe .= '<button id="' . esc_attr( $id ) . '" type="submit" class="sunshine--button">' . wp_kses_post( $field['name'] ) . '</button>';
			$html_safe .= wp_nonce_field( $id, $id, true, false );
			break;

		default:
			do_action( 'sunshine_form_field_' . $field['type'] . '_display' );
			break;

	}

	$required = '';
	if ( isset( $field['required'] ) && $field['required'] ) {
		$required = '<abbr>' . esc_html( apply_filters( 'sunshine_form_field_required_symbol', '*' ) ) . '</abbr>';
	}

	// Add label
	switch ( $field['type'] ) {

		case 'radio':
		case 'checkbox_multi':
			if ( ! empty( $field['name'] ) ) {
				$html_safe = '<span class="sunshine--form--field--name">' . esc_html( $field['name'] ) . '</span>' . $html_safe;
			}
			break;

		case 'legend':
		case 'header':
		case 'submit':
		case 'checkbox':
			break; // Special cases do not need an added label here.

		case 'checkboxXXXX':
			$html_safe = '<span class="sunshine--form--field--name">' . esc_html( $field['name'] ) . '</span><label for="' . esc_attr( $field['id'] ) . '"><span class="sunshine--form--field--name">' . $html_safe . wp_kses_post( $field['description'] ) . $required . '</span></label>';
			break;

		default:
			$html_safe = '<label for="' . esc_attr( $field['id'] ) . '"><span class="sunshine--form--field--name">' . esc_html( $field['name'] ) . $required . '</span></label>' . $html_safe;
			break;

	}

	// Add description
	switch ( $field['type'] ) {

		case 'radio':
		case 'checkbox_multi':
		case 'legend':
			if ( ! empty( $field['description'] ) ) {
				$html_safe .= '<span class="sunshine--form--field-description">' . wp_kses( $field['description'], sunshine_form_description_allowed_html() ) . '</span>';
			}
			break;

		case 'checkbox':
			break;

		default:
			if ( ! empty( $field['description'] ) ) {
				$html_safe .= '<br /><span class="sunshine--form--field-description">' . wp_kses( $field['description'], sunshine_form_description_allowed_html() ) . '</span>' . "\n";
			}
			break;
	}

	$html_safe .= wp_kses_post( apply_filters( 'sunshine_form_field_extra_' . $field['id'], '' ) );

	$size = ( isset( $field['size'] ) && in_array( $field['size'], array( 'full', 'half', 'third' ) ) ) ? $field['size'] : 'full';
	$show = ( isset( $field['show'] ) && ! $field['show'] ) ? ' style="display: none;"' : '';

	$classes = array(
		'sunshine--form--field-' . $field['type'],
		'sunshine--form--field-' . $size,
	);
	/*
	if ( ! empty( $this->errors[ $id ] ) ) {
		$classes[] = 'sunshine--form--field-has-error';
		$html_safe     .= '<div class="sunshine--form--field-error">' . wp_kses_post( $this->errors[ $id ] ) . '</div>';
	}
	*/

	if ( isset( $field['visible'] ) && ! $field['visible'] ) {
		$classes[] = 'sunshine--form--field-hidden';
	}

	if ( isset( $field['required'] ) && $field['required'] ) {
		$classes[] = 'sunshine--form--field-required';
	}

	$before = ( ! empty( $field['before'] ) ) ? '<div class="sunshine--form--field-before" id="sunshine--form--field--before--' . esc_attr( $field['id'] ) . '">' . wp_kses_post( $field['before'] ) . '</div>' : '';
	if ( $before ) {
		$html_safe .= $before;
	}

	$html_safe = '<div class="sunshine--form--field ' . esc_attr( join( ' ', $classes ) ) . '" id="sunshine--form--field--' . esc_attr( $field['id'] ) . '" data-type="' . esc_attr( $field['type'] ) . '"' . $show . '>' . $html_safe . '</div>';

	$after = ( ! empty( $field['after'] ) ) ? '<div class="sunshine--form--field-after" id="sunshine--form--field--after--' . esc_attr( $field['id'] ) . '">' . wp_kses_post( $field['after'] ) . '</div>' : '';
	if ( $after ) {
		$html_safe .= $after;
	}

	if ( $echo ) {
		// Everything above all do escaping, so we can safely output the result.
		echo $html_safe; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		return;
	}
	return $html_safe;

}
