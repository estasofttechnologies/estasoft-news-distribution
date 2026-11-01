<?php
/**
 * An email, drawn on a screen.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Operations;

use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Draws what `Alerts::compose()` and `Digest::view_model()` made, as a card that
 * looks like a plain email: a header (From, To, subject) and a body. It uses the
 * plugin's own classes. The email that is really sent uses inline styles
 * (`Alerts::html()`), because mail programs need them.
 */
final class EmailView {

	/**
	 * The "From" line of an email, as WordPress will write it.
	 *
	 * News Distribution sets no sender. WordPress, or a mail plugin that changes
	 * the sender, decides, so this reads the same two filters WordPress reads.
	 *
	 * @return string Plain text, such as "WordPress <wordpress@example.com>".
	 */
	public static function from_line(): string {
		$host = (string) wp_parse_url( network_home_url(), PHP_URL_HOST );

		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}

		/** This filter is documented in wp-includes/pluggable.php. */
		$address = (string) apply_filters( 'wp_mail_from', 'wordpress@' . $host ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- A WordPress core filter.
		/** This filter is documented in wp-includes/pluggable.php. */
		$name = (string) apply_filters( 'wp_mail_from_name', 'WordPress' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- A WordPress core filter.

		return $name . ' <' . $address . '>';
	}

	/**
	 * One alert email as a card.
	 *
	 * @param string               $id    Unique id for the card.
	 * @param array<string, mixed> $model What `Alerts::compose()` returned.
	 * @param string               $to     Who it goes to (plain text).
	 * @return string Escaped HTML.
	 */
	public static function card( string $id, array $model, string $to ): string {
		$head = Html::tag(
			'div',
			array(
				'class' => 'estasoft-nd-muted estasoft-nd-small',
				'dir'   => 'ltr',
			),
			esc_html( sprintf( /* translators: %s: a sender, such as "WordPress <wordpress@example.com>". */ __( 'From: %s', 'estasoft-news-distribution' ), self::from_line() ) )
		)
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( sprintf( /* translators: %s: one or more names or addresses. */ __( 'To: %s', 'estasoft-news-distribution' ), $to ) ) )
			. Html::tag(
				'h2',
				array(
					'class' => 'estasoft-nd-title',
					'id'    => 'estasoft-nd-email-' . sanitize_key( $id ),
				),
				esc_html( (string) $model['subject'] )
			);

		$body = Html::tag( 'p', array(), esc_html( (string) $model['lead'] ) );

		if ( array() !== $model['facts'] ) {
			$facts = '';

			foreach ( $model['facts'] as $fact ) {
				$facts .= Html::tag( 'div', array(), Html::tag( 'strong', array(), esc_html( (string) $fact['label'] ) ) . ' ' . esc_html( (string) $fact['value'] ) );
			}

			$body .= Html::tag( 'div', array(), $facts );
		}

		if ( array() !== $model['items'] ) {
			$items = '';

			foreach ( $model['items'] as $item ) {
				$items .= Html::tag( 'li', array(), esc_html( (string) $item ) );
			}

			$body .= Html::tag( 'ul', array( 'class' => 'estasoft-nd-ops-list' ), $items );
		}

		foreach ( $model['paragraphs'] as $paragraph ) {
			$body .= Html::tag( 'p', array(), esc_html( (string) $paragraph ) );
		}

		if ( is_array( $model['button'] ) ) {
			$body .= Html::tag(
				'p',
				array(),
				Html::tag(
					'a',
					array(
						'class' => 'estasoft-nd-btn estasoft-nd-btn--primary',
						'href'  => esc_url( (string) $model['button']['url'] ),
					),
					esc_html( (string) $model['button']['label'] )
				)
			);
		}

		if ( '' !== (string) $model['note'] ) {
			$body .= Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( (string) $model['note'] ) );
		}

		return Html::tag(
			'section',
			array(
				'class'           => 'estasoft-nd-card estasoft-nd-card--flush estasoft-nd-ops-email',
				'aria-labelledby' => 'estasoft-nd-email-' . sanitize_key( $id ),
			),
			Html::tag( 'div', array( 'class' => 'estasoft-nd-ops-email__head' ), $head )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-ops-email__body' ), $body )
		);
	}

	/**
	 * The weekly summary as a card, from `Digest::view_model()`.
	 *
	 * @param array<string, mixed> $model What `Digest::view_model()` returned.
	 * @return string Escaped HTML.
	 */
	public static function digest_card( array $model ): string {
		$head = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
			Html::tag(
				'span',
				array(
					'class'       => 'estasoft-nd-ops-mark',
					'aria-hidden' => 'true',
				),
				''
			)
			. Html::tag( 'strong', array(), esc_html__( 'News Distribution', 'estasoft-news-distribution' ) )
			. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-end' ), esc_html( (string) $model['range'] ) )
		);

		$body = Html::tag(
			'h2',
			array(
				'class' => 'estasoft-nd-headline-sm',
				'dir'   => 'auto',
			),
			esc_html( (string) $model['title'] )
		);

		if ( '' !== (string) $model['subtitle'] ) {
			$body .= Html::tag( 'p', array(), esc_html( (string) $model['subtitle'] ) );
		}

		$body .= Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( (string) $model['basis'] ) );

		foreach ( $model['sections'] as $section ) {
			$part = Html::tag( 'h3', array( 'class' => 'estasoft-nd-title' ), esc_html( (string) $section['title'] ) );

			foreach ( (array) ( $section['rows'] ?? array() ) as $row ) {
				$part .= Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-ops-digest-row' ),
					Html::tag(
						'div',
						array(),
						Html::tag( 'div', array(), esc_html( (string) $row['label'] ) )
						. ( '' === (string) $row['hint'] ? '' : Html::tag(
							'div',
							array(
								'class' => 'estasoft-nd-muted estasoft-nd-small',
								'dir'   => 'auto',
							),
							esc_html( (string) $row['hint'] )
						) )
					)
					. Html::tag( 'strong', array(), esc_html( (string) $row['value'] ) )
				);
			}

			foreach ( (array) ( $section['items'] ?? array() ) as $item ) {
				$tone = 'info' === ( $item['tone'] ?? '' ) ? Status::PROCESSING : Status::ATTENTION;
				$text = Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-grow' ),
					Html::tag( 'strong', array(), esc_html( (string) $item['title'] ) ) . Html::tag(
						'div',
						array(
							'class' => 'estasoft-nd-muted',
							'dir'   => 'auto',
						),
						esc_html( (string) $item['detail'] )
					)
				);
				$icon = Icon::svg( Status::describe( $tone )['icon'] );
				$act  = is_array( $item['action'] ) ? Html::tag(
					'a',
					array(
						'class' => 'estasoft-nd-btn estasoft-nd-btn--sm',
						'href'  => esc_url( (string) $item['action']['url'] ),
					),
					esc_html( (string) $item['action']['label'] )
				) : '';

				$part .= Html::tag( 'div', array( 'class' => 'estasoft-nd-ops-digest-item' ), $icon . $text . $act );
			}

			foreach ( (array) ( $section['lines'] ?? array() ) as $line ) {
				$part .= Html::tag( 'div', array( 'class' => 'estasoft-nd-ops-digest-line' ), StatusView::inline( (string) $line['status'], (string) $line['text'] ) );
			}

			$body .= Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $part );
		}

		$foot = Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-muted estasoft-nd-small estasoft-nd-ops-email__foot' ),
			esc_html( (string) $model['footer'] ) . ' ' . Html::tag( 'a', array( 'href' => esc_url( (string) $model['link']['url'] ) ), esc_html( (string) $model['link']['label'] ) )
		);

		return Html::tag(
			'section',
			array(
				'class'      => 'estasoft-nd-card estasoft-nd-card--flush estasoft-nd-ops-email estasoft-nd-ops-email--digest',
				'aria-label' => __( 'Preview of the weekly summary email', 'estasoft-news-distribution' ),
			),
			Html::tag( 'div', array( 'class' => 'estasoft-nd-ops-email__head' ), $head )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-ops-email__body estasoft-nd-stack estasoft-nd-gap-16' ), $body . $foot )
		);
	}
}
