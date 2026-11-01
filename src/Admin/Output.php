<?php
/**
 * Prints the HTML the screens build.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin;

use Estasoft\NewsDistribution\Admin\Ui\Icon;

defined( 'ABSPATH' ) || exit;

/**
 * Screens build their markup as strings, escaping each piece as it goes. This
 * is the one place that prints it, through wp_kses() with a list of the tags
 * and attributes the screens use. A script tag, an event handler or a
 * javascript: address cannot get through, whatever a component let in.
 */
final class Output {

	/**
	 * Prints screen markup.
	 *
	 * @param string $html Markup built by the Ui components.
	 * @return void
	 */
	public static function show( string $html ): void {
		echo wp_kses( $html, self::allowed() );
	}

	/**
	 * The tags and attributes screens may print.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function allowed(): array {
		static $allowed = null;

		if ( null !== $allowed ) {
			return $allowed;
		}

		$global = array(
			'class'            => true,
			'id'               => true,
			'role'             => true,
			'title'            => true,
			'style'            => true,

			'lang'             => true,
			'dir'              => true,
			'hidden'           => true,
			'tabindex'         => true,
			'data-*'           => true,
			'aria-*'           => true,
			'aria-label'       => true,
			'aria-labelledby'  => true,
			'aria-describedby' => true,
			'aria-hidden'      => true,
			'aria-current'     => true,
			'aria-invalid'     => true,
			'aria-live'        => true,
			'aria-expanded'    => true,
			'aria-controls'    => true,
			'aria-pressed'     => true,
			'aria-busy'        => true,
		);

		$tags = array(
			'div'        => array(),
			'span'       => array(),
			'p'          => array(),
			'h1'         => array(),
			'h2'         => array(),
			'h3'         => array(),
			'h4'         => array(),
			'strong'     => array(),
			'em'         => array(),
			'small'      => array(),
			'code'       => array(),
			'pre'        => array(),
			'br'         => array(),
			'hr'         => array(),
			'ul'         => array(),
			'ol'         => array(),
			'li'         => array(),
			'dl'         => array(),
			'dt'         => array(),
			'dd'         => array(),
			'section'    => array(),
			'header'     => array(),
			'footer'     => array(),
			'main'       => array(),
			'nav'        => array(),
			'aside'      => array(),
			'figure'     => array(),
			'figcaption' => array(),
			'a'          => array(
				'href'     => true,
				'target'   => true,
				'rel'      => true,
				'download' => true,
			),
			'img'        => array(
				'src'     => true,
				'alt'     => true,
				'width'   => true,
				'height'  => true,
				'loading' => true,
			),
			'details'    => array( 'open' => true ),
			'summary'    => array(),
			'table'      => array(),
			'thead'      => array(),
			'tbody'      => array(),
			'tr'         => array(),
			'th'         => array(
				'scope'   => true,
				'colspan' => true,
			),
			'td'         => array( 'colspan' => true ),
			'caption'    => array(),
			'form'       => array(
				'action'     => true,
				'method'     => true,
				'enctype'    => true,
				'novalidate' => true,
			),
			'label'      => array( 'for' => true ),
			'fieldset'   => array( 'disabled' => true ),
			'legend'     => array(),
			'input'      => array(
				'type'         => true,
				'name'         => true,
				'value'        => true,
				'checked'      => true,
				'disabled'     => true,
				'readonly'     => true,
				'required'     => true,
				'min'          => true,
				'max'          => true,
				'step'         => true,
				'maxlength'    => true,
				'minlength'    => true,
				'pattern'      => true,
				'placeholder'  => true,
				'autocomplete' => true,
				'inputmode'    => true,
				'size'         => true,
				'accept'       => true,
				'multiple'     => true,
				'form'         => true,
			),
			'textarea'   => array(
				'name'        => true,
				'rows'        => true,
				'cols'        => true,
				'maxlength'   => true,
				'placeholder' => true,
				'disabled'    => true,
				'readonly'    => true,
				'required'    => true,
			),
			'select'     => array(
				'name'     => true,
				'disabled' => true,
				'required' => true,
				'multiple' => true,
				'size'     => true,
			),
			'optgroup'   => array(
				'label'    => true,
				'disabled' => true,
			),
			'option'     => array(
				'value'    => true,
				'selected' => true,
				'disabled' => true,
			),
			'button'     => array(
				'type'       => true,
				'name'       => true,
				'value'      => true,
				'disabled'   => true,
				'form'       => true,
				'formaction' => true,
			),
			'progress'   => array(
				'value' => true,
				'max'   => true,
			),
			'time'       => array( 'datetime' => true ),
		);

		foreach ( $tags as $tag => $attributes ) {
			$tags[ $tag ] = $attributes + $global;
		}

		foreach ( Icon::allowed_html() as $tag => $attributes ) {
			$tags[ $tag ] = $attributes + ( 'svg' === $tag ? $global : array() );
		}

		/**
		 * Lets an add-on print more tags on News Distribution screens.
		 *
		 * @param array<string, array<string, bool>> $tags Tags and attributes, in wp_kses() format.
		 */
		$allowed = (array) apply_filters( 'estasoft_nd_allowed_html', $tags );

		return $allowed;
	}
}
