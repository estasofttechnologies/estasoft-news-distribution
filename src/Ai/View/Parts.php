<?php
/**
 * Small pieces the AI screens share: the AI mark, rule-check lines, forms that work without a script.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Ai\View;

use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Ai\Availability;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Every screen that shows a suggestion draws it the same way: a mark that says AI wrote it, the checks it passed,
 * and buttons that post a form. The forms carry their own nonce and work without a script (`Runner` handles them).
 */
final class Parts {

	/**
	 * The mark on anything the AI wrote.
	 *
	 * @return string Escaped HTML.
	 */
	public static function badge(): string {
		return Html::tag( 'span', array( 'class' => 'estasoft-nd-ai' ), esc_html__( 'AI', 'estasoft-news-distribution' ) );
	}

	/**
	 * The checks a suggestion went through, each with a tick, a warning or a cross.
	 *
	 * @param array<int, array{ok?: bool, level?: string, label?: string, text?: string}> $lines The check lines. A `level` of `warn` shows a warning, `info` a note and `none` a minus (something it does not do); without a level, `ok` decides.
	 * @return string Escaped HTML, empty when there are none.
	 */
	public static function ticks( array $lines ): string {
		$items = '';

		foreach ( $lines as $line ) {
			$level = isset( $line['level'] ) ? (string) $line['level'] : ( ! empty( $line['ok'] ) ? 'pass' : 'fail' );
			$tone  = 'pass' === $level ? 'ok' : ( in_array( $level, array( 'warn', 'none', 'info' ), true ) ? $level : 'no' );
			$icon  = array(
				'ok'   => 'check-circle',
				'warn' => 'warning',
				'none' => 'minus-circle',
				'info' => 'info',
				'no'   => 'x-circle',
			)[ $tone ];
			$label = trim( (string) ( $line['label'] ?? '' ) );
			$text  = (string) ( $line['text'] ?? '' );

			$items .= Html::tag(
				'li',
				array( 'class' => 'estasoft-nd-ai-tick estasoft-nd-ai-tick--' . $tone ),
				Icon::svg( $icon ) . Html::tag( 'span', array(), esc_html( '' === $label ? $text : $label . ': ' . $text ) )
			);
		}

		return '' === $items ? '' : Html::tag( 'ul', array( 'class' => 'estasoft-nd-ai-ticks' ), $items );
	}

	/**
	 * "Channels / SmartNews": the way back above a screen's heading.
	 *
	 * @param string $parent_label The page above (plain).
	 * @param string $parent_url   Its address.
	 * @param string $current      This page (plain).
	 * @return string Escaped HTML.
	 */
	public static function crumbs( string $parent_label, string $parent_url, string $current ): string {
		return Html::tag(
			'nav',
			array(
				'class'      => 'estasoft-nd-breadcrumb estasoft-nd-small',
				'aria-label' => __( 'Breadcrumb', 'estasoft-news-distribution' ),
			),
			Html::tag( 'a', array( 'href' => $parent_url ), esc_html( $parent_label ) )
			. Html::tag( 'span', array( 'aria-hidden' => 'true' ), ' / ' )
			. Html::tag( 'span', array( 'aria-current' => 'page' ), esc_html( $current ) )
		);
	}

	/**
	 * A list of short sentences with the same icon before each.
	 *
	 * @param string[] $items Plain sentences.
	 * @param string   $icon  An icon name.
	 * @return string Escaped HTML.
	 */
	public static function bullets( array $items, string $icon = 'info' ): string {
		$html = '';

		foreach ( $items as $item ) {
			$html .= Html::tag( 'li', array( 'class' => 'estasoft-nd-rule' ), Icon::svg( $icon ) . Html::tag( 'span', array(), esc_html( (string) $item ) ) );
		}

		return Html::tag( 'ul', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $html );
	}

	/**
	 * A table that may be wider than its card, kept inside it: it scrolls sideways there, and a person can
	 * reach it with the keyboard.
	 *
	 * @param string $table Trusted HTML of the table.
	 * @param string $label  What the table lists (plain).
	 * @param bool   $narrow Whether the table is short enough to fit a phone without scrolling.
	 * @return string Escaped HTML.
	 */
	public static function fit( string $table, string $label, bool $narrow = false ): string {
		return Html::tag(
			'div',
			array(
				'class'      => Html::classes( 'estasoft-nd-ai-fit', $narrow ? 'estasoft-nd-ai-fit--narrow' : '' ),
				'role'       => 'region',
				'tabindex'   => '0',
				'aria-label' => $label,
			),
			$table
		);
	}

	/**
	 * Gray placeholders and a line saying what is being waited for. A script shows it while a form is sent.
	 *
	 * @param string $text   What is happening.
	 * @param bool   $hidden Whether it starts out hidden.
	 * @return string Escaped HTML.
	 */
	public static function wait( string $text, bool $hidden = false ): string {
		return Html::tag(
			'div',
			array(
				'class'        => 'estasoft-nd-ai-wait',
				'role'         => 'status',
				'data-ai-wait' => '1',
				'hidden'       => $hidden ? 'hidden' : null,
				'aria-live'    => 'polite',
			),
			Html::tag( 'span', array( 'class' => 'estasoft-nd-skeleton estasoft-nd-ai-wait__a' ), '' )
			. Html::tag( 'span', array( 'class' => 'estasoft-nd-skeleton estasoft-nd-ai-wait__b' ), '' )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $text ) )
		);
	}

	/**
	 * The hidden fields every AI form carries.
	 *
	 * @param string               $tab    The screen's own name, which the nonce belongs to.
	 * @param string               $action `suggest` or `decide`.
	 * @param array<string, mixed> $fields More hidden values, name => value.
	 * @return string Escaped HTML.
	 */
	public static function hidden( string $tab, string $action, array $fields = array() ): string {
		$html = Html::void(
			'input',
			array(
				'type'  => 'hidden',
				'name'  => Runner::NONCE,
				'value' => wp_create_nonce( Runner::action( $tab ) ),
			)
		) . Html::void(
			'input',
			array(
				'type'  => 'hidden',
				'name'  => 'estasoft_nd_ai_do',
				'value' => $action,
			)
		);

		foreach ( $fields as $name => $value ) {
			$html .= Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => (string) $name,
					'value' => (string) $value,
				)
			);
		}

		return $html;
	}

	/**
	 * A form that posts back to the screen that printed it.
	 *
	 * @param string               $url    The screen's own address.
	 * @param string               $tab    The screen's own name.
	 * @param string               $action `suggest` or `decide`.
	 * @param array<string, mixed> $fields Hidden values, name => value.
	 * @param string               $inner  Trusted HTML: the buttons and fields.
	 * @param string               $more   More classes.
	 * @return string Escaped HTML.
	 */
	public static function form( string $url, string $tab, string $action, array $fields, string $inner, string $more = '' ): string {
		return Html::tag(
			'form',
			array(
				'method'       => 'post',
				'action'       => $url,
				'class'        => Html::classes( 'estasoft-nd-ai-form', $more ),
				'data-ai-form' => $action,
			),
			self::hidden( $tab, $action, $fields ) . $inner
		);
	}

	/**
	 * A button that sets the decision when its form is posted.
	 *
	 * @param string               $label   Visible text.
	 * @param string               $value   The decision it stands for.
	 * @param array<string, mixed> $options Button options (`variant`, `size`, `name`).
	 * @return string Escaped HTML.
	 */
	public static function decide( string $label, string $value, array $options = array() ): string {
		$name = (string) ( $options['name'] ?? 'decision' );

		return Html::tag(
			'button',
			array(
				'type'  => 'submit',
				'name'  => $name,
				'value' => $value,
				'class' => Html::classes(
					'estasoft-nd-btn',
					'estasoft-nd-btn--sm',
					! empty( $options['variant'] ) ? 'estasoft-nd-btn--' . $options['variant'] : ''
				),
			),
			esc_html( $label )
		);
	}

	/**
	 * "Suggested by AI through Anthropic, 1 request."
	 *
	 * @param array<string, mixed> $suggestion What a suggest call returned.
	 * @return string Plain text.
	 */
	public static function made_by( array $suggestion ): string {
		$name = (string) ( $suggestion['provider_name'] ?? '' );
		$n    = max( 1, (int) ( $suggestion['requests'] ?? 1 ) );

		if ( '' === $name ) {
			return sprintf(
				/* translators: %d: number of requests. */
				_n( 'Suggested by AI, %d request.', 'Suggested by AI, %d requests.', $n, 'estasoft-news-distribution' ),
				$n
			);
		}

		return sprintf(
			/* translators: 1: a provider name such as Anthropic, 2: number of requests. */
			_n( 'Suggested by AI through %1$s, %2$d request.', 'Suggested by AI through %1$s, %2$d requests.', $n, 'estasoft-news-distribution' ),
			$name,
			$n
		);
	}

	/**
	 * The name of the provider a log row's connection (`provider:model`) points at.
	 *
	 * @param Plugin $plugin     The running plugin.
	 * @param string $connection The row's connection, such as `anthropic:claude-sonnet`.
	 * @return string The provider's name, or its id when WordPress no longer lists it, or an empty string.
	 */
	public static function connection_name( Plugin $plugin, string $connection ): string {
		$id = (string) strtok( $connection, ':' );

		if ( '' === $id ) {
			return '';
		}

		foreach ( $plugin->make( Availability::class )->providers() as $provider ) {
			if ( $provider['id'] === $id ) {
				return (string) $provider['name'];
			}
		}

		return $id;
	}
}
