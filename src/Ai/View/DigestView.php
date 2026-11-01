<?php
/**
 * The weekly summary page with the AI wording explained: `&section=alerts&tab=weekly-ai`.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Ai\View;

use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\CommandBar;
use Estasoft\NewsDistribution\Admin\Ui\Grid;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\PageHeader;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Admin\Ui\Tag;
use Estasoft\NewsDistribution\Ai\Features\Digest as DigestFeature;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Digest;
use Estasoft\NewsDistribution\Core\Language\Catalog;
use Estasoft\NewsDistribution\Core\Operations\Flash;
use Estasoft\NewsDistribution\Core\Operations\Links;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * A preview of Monday's email with the opening paragraph the AI wrote, how each person's email is worded, and why the
 * figures are safe. Opening the page never asks the provider: the paragraph shown is the last one made, by a preview
 * a person asked for or by a send. "Make a preview paragraph" is a button, and it costs one request.
 */
final class DigestView {

	/**
	 * The screen's own name: it keys the form nonce.
	 */
	public const KEY = 'digest';

	/**
	 * The running plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * This page's own address.
	 *
	 * @return string
	 */
	public static function url(): string {
		return Links::settings( 'alerts', array( 'tab' => 'weekly-ai' ) );
	}

	/**
	 * A whole page.
	 *
	 * @return string Escaped HTML.
	 */
	public function page(): string {
		$digest = $this->plugin->make( Digest::class );
		$model  = $digest->view_model( $digest->build( null, 'so_far' ) );

		$switch = CommandBar::segments(
			array(
				'preview' => array(
					'label' => __( 'Preview', 'estasoft-news-distribution' ),
					'url'   => self::url(),
				),
				'options' => array(
					'label' => __( 'Options', 'estasoft-news-distribution' ),
					'url'   => Links::settings( 'alerts' ) . '#estasoft-nd-card-alerts-what',
				),
			),
			'preview',
			__( 'Weekly summary email', 'estasoft-news-distribution' )
		);

		return Parts::crumbs( __( 'Settings', 'estasoft-news-distribution' ), Links::settings(), __( 'Alerts', 'estasoft-news-distribution' ) )
			. PageHeader::render(
				__( 'Weekly summary email', 'estasoft-news-distribution' ),
				__( 'Sent every Monday to the addresses in Settings, Alerts.', 'estasoft-news-distribution' ),
				$switch
			)
			. Flash::take()
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-ai-digest' ), $this->card( $model ) . $this->preview_form() )
			. $this->people()
			. $this->notes();
	}

	/**
	 * The email as it would look, with the opening paragraph the AI wrote.
	 *
	 * @param array<string, mixed> $model What `Core\Digest::view_model()` returned.
	 * @return string Escaped HTML.
	 */
	private function card( array $model ): string {
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

		$body .= $this->paragraph();

		foreach ( (array) $model['sections'] as $section ) {
			$body .= Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $this->section( (array) $section ) );
		}

		$foot = Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-muted estasoft-nd-small estasoft-nd-ops-email__foot' ),
			esc_html( (string) $model['footer'] ) . ' ' . Html::tag( 'a', array( 'href' => $model['link']['url'] ), esc_html( (string) $model['link']['label'] ) )
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

	/**
	 * One section of the email: rows of figures, items to act on, or lines.
	 *
	 * @param array<string, mixed> $section A section of the model.
	 * @return string Escaped HTML.
	 */
	private function section( array $section ): string {
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
			$tone  = 'info' === ( $item['tone'] ?? '' ) ? Status::PROCESSING : Status::ATTENTION;
			$text  = Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-grow' ),
				Html::tag( 'strong', array(), esc_html( (string) $item['title'] ) )
				. Html::tag(
					'div',
					array(
						'class' => 'estasoft-nd-muted',
						'dir'   => 'auto',
					),
					esc_html( (string) $item['detail'] )
				)
			);
			$part .= Html::tag( 'div', array( 'class' => 'estasoft-nd-ops-digest-item' ), Icon::svg( Status::describe( $tone )['icon'] ) . $text );
		}

		foreach ( (array) ( $section['lines'] ?? array() ) as $line ) {
			$part .= Html::tag( 'div', array( 'class' => 'estasoft-nd-ops-digest-line' ), StatusView::inline( (string) $line['status'], (string) $line['text'] ) );
		}

		return $part;
	}

	/**
	 * "In plain words": the last paragraph the AI made in this person's language, the numbers it was checked against,
	 * and what it is worded from. It is only ever read from what was stored.
	 *
	 * @return string Escaped HTML.
	 */
	private function paragraph(): string {
		$feature  = $this->plugin->make( DigestFeature::class );
		$language = Catalog::primary( get_user_locale() );
		$last     = $feature->last( $language );
		$title    = Html::tag( 'h3', array( 'class' => 'estasoft-nd-title' ), esc_html__( 'In plain words', 'estasoft-news-distribution' ) );

		if ( null === $last || '' === $last['text'] ) {
			return Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ),
				$title . Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'No paragraph has been made yet. The Monday email asks for one when it is sent. To see the wording before then, make a preview paragraph below: it costs one request.', 'estasoft-news-distribution' ) )
			);
		}

		$numbers = array() === $last['numbers']
			? __( 'The paragraph states no figures of its own', 'estasoft-news-distribution' )
			: sprintf(
				/* translators: %s: a list of numbers such as 24, 21, 3. */
				__( 'Every number in this paragraph matches the table below: %s', 'estasoft-news-distribution' ),
				implode( ', ', $last['numbers'] )
			);
		$made = $last['at'] > 0
			? sprintf(
				/* translators: 1: a date and time such as 19 Oct, 14:02, 2: how it was made: "for a preview" or "for an email that was sent". */
				__( 'Made on %1$s %2$s.', 'estasoft-news-distribution' ),
				wp_date( 'j M, H:i', $last['at'], Clock::zone() ),
				'send' === $last['kind'] ? __( 'for an email that was sent', 'estasoft-news-distribution' ) : __( 'for a preview', 'estasoft-news-distribution' )
			)
			: '';

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ),
			$title
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-suggest' ),
				Html::tag( 'div', array(), Parts::badge() )
				. Html::tag(
					'p',
					array(
						'lang' => $language,
						'dir'  => 'auto',
					),
					esc_html( $last['text'] )
				)
				. Parts::ticks(
					array(
						array(
							'level' => 'pass',
							'text'  => $numbers,
						),
					)
				)
			)
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( __( 'Worded by AI from the figures below. The figures come from your logs.', 'estasoft-news-distribution' ) . ( '' === $made ? '' : ' ' . $made ) ) )
		);
	}

	/**
	 * "Make a preview paragraph": the one button that asks the provider, for one request.
	 *
	 * @return string Escaped HTML.
	 */
	private function preview_form(): string {
		return Parts::form(
			self::url(),
			self::KEY,
			'suggest',
			array( 'estasoft_nd_ai_feature' => 'digest' ),
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-12' ),
				Button::submit( __( 'Make a preview paragraph', 'estasoft-news-distribution' ) )
				. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( '1 request, with the totals in the table only. Nothing is sent to anyone.', 'estasoft-news-distribution' ) )
			)
			. Parts::wait( __( 'Making the paragraph. This takes a few seconds.', 'estasoft-news-distribution' ), true )
		);
	}

	/**
	 * "Each person's language": how each person's email is worded.
	 *
	 * @return string Escaped HTML.
	 */
	private function people(): string {
		$rows = array();

		foreach ( $this->plugin->make( DigestFeature::class )->people() as $person ) {
			$ok     = 'fixed' !== $person['state'];
			$rows[] = array(
				esc_html( $person['name'] ),
				Tag::lang( $person['language'] ),
				Html::tag(
					'span',
					array( 'class' => 'estasoft-nd-ai-inline estasoft-nd-ai-inline--' . ( $ok ? 'ok' : 'none' ) ),
					Icon::svg( $ok ? 'check-circle' : 'minus-circle' ) . Html::tag( 'span', array(), esc_html( $person['text'] ) )
				),
			);
		}

		return Card::flush(
			'ai-people',
			__( 'Each person’s language', 'estasoft-news-distribution' ),
			__( 'The summary arrives in each person’s WordPress language.', 'estasoft-news-distribution' ),
			array() === $rows
				? Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-ai-empty' ), esc_html__( 'No one is on the list yet. Add addresses in Settings, Alerts.', 'estasoft-news-distribution' ) )
				: Parts::fit(
					Grid::render(
						'estasoft-nd-cols-ai-people',
						array(
							__( 'Person', 'estasoft-news-distribution' ),
							__( 'Language', 'estasoft-news-distribution' ),
							__( 'How it is worded', 'estasoft-news-distribution' ),
						),
						$rows,
						__( 'How the weekly summary is worded for each person', 'estasoft-news-distribution' )
					),
					__( 'How the weekly summary is worded for each person', 'estasoft-news-distribution' ),
					true
				)
		);
	}

	/**
	 * Why the figures are safe, what is sent, and what it cannot do.
	 *
	 * @return string Escaped HTML.
	 */
	private function notes(): string {
		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-ai-trio' ),
			Card::note(
				'ai-safe',
				__( 'Why the figures are safe', 'estasoft-news-distribution' ),
				Parts::ticks(
					array(
						array(
							'level' => 'pass',
							'text'  => __( 'The AI writes only the opening paragraph', 'estasoft-news-distribution' ),
						),
						array(
							'level' => 'pass',
							'text'  => __( 'Every number comes from your logs, and News Distribution checks that each number in the paragraph matches', 'estasoft-news-distribution' ),
						),
						array(
							'level' => 'pass',
							'text'  => __( 'If a check fails, the summary goes out without the paragraph', 'estasoft-news-distribution' ),
						),
					)
				)
			)
			. Card::note(
				'ai-digest-sent',
				__( 'What is sent to your provider', 'estasoft-news-distribution' ),
				Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Only the totals in the table, such as 24 and 21, and the channel names. No article titles, text or names of people.', 'estasoft-news-distribution' ) )
			)
			. Card::cannot(
				array(
					__( 'See your articles or change a number', 'estasoft-news-distribution' ),
					__( 'Be sure its wording is the best. The paragraph carries the AI mark, and you can turn it off in Settings, AI.', 'estasoft-news-distribution' ),
				)
			)
		);
	}
}
