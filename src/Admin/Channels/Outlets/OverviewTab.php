<?php
/**
 * Outlets, Queue and webhooks.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Channels\Outlets;

use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Channels\Outlets\MediumOutlet;
use Estasoft\NewsDistribution\Channels\Outlets\Target;
use Estasoft\NewsDistribution\Channels\Outlets\WebhookOutlet;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Language\Catalog;
use Estasoft\NewsDistribution\Core\Retry;
use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * What needs a person (a failed webhook, stories waiting for Medium), the
 * Medium import queue and the webhooks. Medium has no connection to send
 * through, so its queue is a list of stories to import by hand; nothing here
 * says a story was posted to Medium.
 */
final class OverviewTab extends AbstractTab {

	public function slug(): string {
		return 'overview';
	}

	public function render(): string {
		$html = $this->attention_bar() . $this->medium_note();

		return $html . $this->form(
			$this->medium_card() . $this->webhooks_card() . $this->side_card(
				__( 'What it cannot do', 'estasoft-news-distribution' ),
				Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'It cannot post to Medium, and it cannot see whether you imported a story or what Medium does with it. A webhook can only report whether the other side answered. It cannot fix an endpoint that is offline.', 'estasoft-news-distribution' ) )
			),
			__( 'Medium import queue and webhooks', 'estasoft-news-distribution' ),
			array(),
			false
		);
	}

	/**
	 * The reasons the channel needs attention, in one bar.
	 *
	 * @return string
	 */
	private function attention_bar(): string {
		$items = $this->channel->attention();

		if ( array() === $items ) {
			return '';
		}

		$texts = array_map(
			static function ( array $item ): string {
				return $item['text'];
			},
			$items
		);

		return Message::bar( 'warning', Html::tag( 'strong', array(), esc_html__( 'Needs attention.', 'estasoft-news-distribution' ) ) . ' ' . esc_html( implode( ' ', $texts ) ) );
	}

	/**
	 * What Medium is now and what the buttons do.
	 *
	 * @return string
	 */
	private function medium_note(): string {
		return Message::text( 'info', __( 'Medium accepts no new connections from plugins, so News Distribution cannot post there. “Import to Medium” opens Medium’s import page and copies the article address for you to paste. Medium’s import tool adds a canonical link back to your site, which search engines can use to credit it.', 'estasoft-news-distribution' ) );
	}

	/**
	 * The stories to import by hand.
	 *
	 * @return string
	 */
	private function medium_card(): string {
		$medium = $this->channel->outlet( 'medium' );
		$id     = 'estasoft-nd-card-medium';

		if ( ! $medium instanceof MediumOutlet ) {
			return '';
		}

		$head = Html::tag(
			'h2',
			array(
				'class' => 'estasoft-nd-title',
				'id'    => $id,
			),
			esc_html__( 'Medium import queue', 'estasoft-news-distribution' )
		);
		$days = max( 1, (int) round( (int) $this->plugin->rules()->limit( 'outlets', 'medium_window_days', 7 ) ) );

		if ( ! $medium->is_on( $this->channel->settings() ) ) {
			return Html::tag(
				'section',
				array(
					'class'           => 'estasoft-nd-card estasoft-nd-card--flush',
					'aria-labelledby' => $id,
				),
				Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-card-head' ),
					$head . Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'The list is off. Turn it on to see the stories you published in the last few days, newest first, each with a link to Medium’s import page.', 'estasoft-news-distribution' ) )
				) . Html::tag( 'div', array( 'class' => 'estasoft-nd-row estasoft-nd-outlets-foot' ), $this->action_button( 'medium_on', __( 'Turn on the Medium list', 'estasoft-news-distribution' ), array( 'variant' => 'primary' ) ) )
			);
		}

		$list  = $medium->stories( $this->channel->carried_languages() );
		$rows  = '';
		$shown = count( $list['rows'] );

		foreach ( $list['rows'] as $story ) {
			$rows .= $this->story_row( $story );
		}

		if ( '' === $rows ) {
			$rows = Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-row' ),
				Html::tag(
					'p',
					array( 'class' => 'estasoft-nd-muted' ),
					esc_html(
						sprintf(
							/* translators: %d: number of days. */
							_n( 'No story was published in the last %d day.', 'No story was published in the last %d days.', $days, 'estasoft-news-distribution' ),
							$days
						)
					)
				)
			);
		}

		$description = sprintf(
			/* translators: 1: stories shown, 2: stories in all, 3: number of days. */
			_n( 'Stories to import into Medium by hand, newest first. Showing %1$d of %2$d from the last %3$d day.', 'Stories to import into Medium by hand, newest first. Showing %1$d of %2$d from the last %3$d days.', $days, 'estasoft-news-distribution' ),
			$shown,
			$list['total'],
			$days
		);

		$foot = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-row estasoft-nd-outlets-foot' ),
			Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( '“Mark as imported” only changes this list. News Distribution changes nothing on Medium, so there is nothing to undo there; you can mark a story again at any time.', 'estasoft-news-distribution' ) )
			. Html::tag( 'div', array(), $this->action_button( 'medium_off', __( 'Turn off the Medium list', 'estasoft-news-distribution' ), array( 'size' => 'sm' ) ) )
		);

		return Html::tag(
			'section',
			array(
				'class'           => 'estasoft-nd-card estasoft-nd-card--flush',
				'aria-labelledby' => $id,
			),
			Html::tag( 'div', array( 'class' => 'estasoft-nd-card-head' ), $head . Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( $description ) ) ) . $rows . $foot
		);
	}

	/**
	 * One story of the queue.
	 *
	 * @param array{id: int, title: string, url: string, language: string, available_ts: int, state: string, imported_ts: int} $story The story.
	 * @return string
	 */
	private function story_row( array $story ): string {
		$title = Html::tag(
			'span',
			array(
				'class' => 'estasoft-nd-strong estasoft-nd-outlets-title',
				'lang'  => $story['language'],
				'dir'   => Catalog::direction( $story['language'] ),
			),
			Html::tag(
				'a',
				array(
					'class'  => 'estasoft-nd-outlets-plain',
					'href'   => $story['url'],
					'target' => '_blank',
					'rel'    => 'noopener',
				),
				esc_html( $story['title'] )
			)
		);

		$from = Html::tag(
			'span',
			array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
			esc_html(
				sprintf(
					/* translators: %s: a time such as 14:38, or "yesterday", or a date. */
					__( 'Available from %s', 'estasoft-news-distribution' ),
					$this->when( $story['available_ts'] )
				)
			)
		);

		$name = Html::tag( 'div', array( 'class' => 'estasoft-nd-group-row__name' ), $title . $from );

		if ( 'waiting' === $story['state'] ) {
			$minutes = max( 1, (int) round( $this->plugin->settings()->index_first_delay_seconds() / MINUTE_IN_SECONDS ) );

			return Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-group-row' ),
				$name
				. StatusView::pill(
					Status::QUEUED,
					sprintf(
						/* translators: %s: a time such as 14:38. */
						__( 'Waiting %s', 'estasoft-news-distribution' ),
						Clock::format( $story['available_ts'] )
					)
				)
				. Html::tag(
					'span',
					array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
					esc_html(
						sprintf(
							/* translators: %d: minutes of the index-first delay. */
							_n( 'Waits for the %d-minute index-first delay', 'Waits for the %d-minute index-first delay', $minutes, 'estasoft-news-distribution' ),
							$minutes
						)
					)
				)
			);
		}

		if ( 'imported' === $story['state'] ) {
			return Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-group-row' ),
				$name
				. StatusView::pill( Status::SENT, __( 'Imported', 'estasoft-news-distribution' ) )
				. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Marked as imported by hand', 'estasoft-news-distribution' ) )
				. $this->action_button(
					'medium_unmark:' . $story['id'],
					__( 'Undo', 'estasoft-news-distribution' ),
					array(
						'variant'    => 'subtle',
						'size'       => 'sm',
						'aria_label' => sprintf(
							/* translators: %s: the title of a story. */
							__( 'Undo “marked as imported”: %s', 'estasoft-news-distribution' ),
							$story['title']
						),
					)
				)
			);
		}

		$buttons = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
			Button::link(
				__( 'Import to Medium', 'estasoft-news-distribution' ),
				MediumOutlet::IMPORT_URL,
				array(
					'size'  => 'sm',
					'attrs' => array(
						'target'         => '_blank',
						'rel'            => 'noopener noreferrer',
						'data-copy'      => $story['url'],
						'data-copy-done' => __( 'Article address copied. Paste it into Medium.', 'estasoft-news-distribution' ),
						'aria-label'     => sprintf(
							/* translators: %s: the title of a story. */
							__( 'Import to Medium: %s (opens in a new tab)', 'estasoft-news-distribution' ),
							$story['title']
						),
					),
				)
			)
			. $this->action_button(
				'medium_mark:' . $story['id'],
				__( 'Mark as imported', 'estasoft-news-distribution' ),
				array(
					'variant'    => 'subtle',
					'size'       => 'sm',
					'aria_label' => sprintf(
						/* translators: %s: the title of a story. */
						__( 'Mark as imported: %s', 'estasoft-news-distribution' ),
						$story['title']
					),
				)
			)
		);

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-group-row' ),
			$name . StatusView::pill( Status::PROCESSING, __( 'Not imported yet', 'estasoft-news-distribution' ) ) . $buttons
		);
	}

	/**
	 * The webhooks.
	 *
	 * @return string
	 */
	private function webhooks_card(): string {
		$webhook = $this->channel->outlet( 'webhook' );
		$id      = 'estasoft-nd-card-webhooks';

		if ( ! $webhook instanceof WebhookOutlet ) {
			return '';
		}

		$settings = $this->channel->settings();
		$targets  = $webhook->targets( $settings, array() );
		$rows     = '';

		foreach ( $targets as $target ) {
			$rows .= $this->webhook_row( $webhook, $target );
		}

		if ( '' === $rows ) {
			$rows = Html::tag( 'div', array( 'class' => 'estasoft-nd-row' ), Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'No endpoint yet. Add one to send each published article to your own system.', 'estasoft-news-distribution' ) ) );
		}

		$free = count( $targets ) < $webhook->slots();

		if ( ! $free ) {
			$add = Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
				esc_html(
					sprintf(
						/* translators: %d: the most endpoints there can be. */
						_n( 'All %d endpoint is in use.', 'All %d endpoints are in use.', $webhook->slots(), 'estasoft-news-distribution' ),
						$webhook->slots()
					)
				)
			);
		} elseif ( $this->can_change_secrets() ) {
			$add = Button::link( __( 'Add endpoint', 'estasoft-news-distribution' ), $this->screen->url( 'overview', array( 'webhook' => 'new' ) ) );
		} else {
			$add = Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Only an administrator can add an endpoint.', 'estasoft-news-distribution' ) );
		}

		return Html::tag(
			'section',
			array(
				'class'           => 'estasoft-nd-card estasoft-nd-card--flush',
				'aria-labelledby' => $id,
			),
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-card-head' ),
				Html::tag(
					'h2',
					array(
						'class' => 'estasoft-nd-title',
						'id'    => $id,
					),
					esc_html__( 'Webhooks', 'estasoft-news-distribution' )
				)
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'News Distribution sends each published article to these endpoints, with its canonical URL in the payload.', 'estasoft-news-distribution' ) )
			) . $rows . Html::tag( 'div', array( 'class' => 'estasoft-nd-row estasoft-nd-outlets-foot' ), $add )
		);
	}

	/**
	 * One endpoint: its name and address, what happened last, and its buttons.
	 *
	 * @param WebhookOutlet $webhook The outlet.
	 * @param Target        $target  The endpoint.
	 * @return string
	 */
	private function webhook_row( WebhookOutlet $webhook, Target $target ): string {
		$key  = $target->key();
		$last = $this->state()->webhook( $key );
		$hint = $webhook->address_hint( $key );
		$name = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-group-row__name estasoft-nd-outlets-wide' ),
			Html::tag( 'span', array( 'class' => 'estasoft-nd-strong' ), esc_html( $target->label() ) )
			. ( '' !== $hint ? Html::tag( 'span', array( 'class' => 'estasoft-nd-mono estasoft-nd-outlets-address' ), esc_html( $hint ) ) : '' )
		);

		$buttons = '';

		if ( ! empty( $last['post'] ) && 'failed' === ( $last['status'] ?? '' ) && $target->is_on() && $target->is_complete() ) {
			$buttons .= $this->action_button(
				'webhook_retry:' . $key,
				__( 'Retry now', 'estasoft-news-distribution' ),
				array(
					'size'       => 'sm',
					'aria_label' => sprintf(
						/* translators: %s: the name of an endpoint. */
						__( 'Retry %s now', 'estasoft-news-distribution' ),
						$target->label()
					),
				)
			);
		}

		$buttons .= Button::link(
			__( 'Edit', 'estasoft-news-distribution' ),
			$this->screen->url( 'overview', array( 'webhook' => $key ) ),
			array(
				'size'  => 'sm',
				'attrs' => array(
					'aria-label' => sprintf(
						/* translators: %s: the name of an endpoint. */
						__( 'Edit %s', 'estasoft-news-distribution' ),
						$target->label()
					),
				),
			)
		);

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-group-row' ),
			$name
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-outlets-last' ), $this->webhook_status( $target, $last ) )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ), $buttons )
		);
	}

	/**
	 * What happened last at an endpoint, in words.
	 *
	 * @param Target               $target The endpoint.
	 * @param array<string, mixed> $last   What the state holds for it.
	 * @return string
	 */
	private function webhook_status( Target $target, array $last ): string {
		$muted = static function ( string $text ): string {
			return Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $text ) );
		};

		if ( ! $target->is_complete() ) {
			return StatusView::inline( Status::NOT_SET_UP, __( 'Not set up', 'estasoft-news-distribution' ) )
				. $muted( __( 'Add the address and the signing secret.', 'estasoft-news-distribution' ) );
		}

		if ( ! $target->is_on() ) {
			return StatusView::inline( Status::OFF, __( 'Off', 'estasoft-news-distribution' ) )
				. $muted( __( 'Nothing is sent to this endpoint.', 'estasoft-news-distribution' ) );
		}

		if ( array() === $last || empty( $last['time'] ) ) {
			return StatusView::inline( Status::NEVER, __( 'Not sent yet', 'estasoft-news-distribution' ) );
		}

		$time    = $this->when( (int) $last['time'] );
		$is_test = 'ping' === ( $last['event'] ?? '' );
		$message = (string) ( $last['message'] ?? '' );

		if ( 'sent' === ( $last['status'] ?? '' ) ) {
			return StatusView::inline(
				$is_test ? Status::TEST_PASSED : Status::SENT,
				sprintf(
					$is_test
						/* translators: %s: a time such as 13:56. */
						? __( 'Test passed %s', 'estasoft-news-distribution' )
						/* translators: %s: a time such as 13:56. */
						: __( 'Sent %s', 'estasoft-news-distribution' ),
					$time
				)
			) . $muted( $message );
		}

		$html = StatusView::inline(
			Status::FAILED,
			sprintf(
				$is_test
					/* translators: %s: a time such as 13:56. */
					? __( 'Test failed %s', 'estasoft-news-distribution' )
					/* translators: %s: a time such as 13:56. */
					: __( 'Failed %s', 'estasoft-news-distribution' ),
				$time
			)
		);

		$tail = '';

		if ( ! $is_test ) {
			$attempts = max( 1, (int) ( $last['attempts'] ?? 1 ) );

			if ( ! empty( $last['final'] ) ) {
				$tail = sprintf(
					/* translators: 1: number of attempts, 2: the time of the last one, such as 14:17. */
					_n( 'Retries stopped after %1$d attempt, the last at %2$s. Check the endpoint, then retry.', 'Retries stopped after %1$d attempts, the last at %2$s. Check the endpoint, then retry.', $attempts, 'estasoft-news-distribution' ),
					$attempts,
					$time
				);
			} else {
				$tail = sprintf(
					/* translators: 1: attempts made, 2: attempts in all. */
					__( 'Attempt %1$d of %2$d. The next try is queued.', 'estasoft-news-distribution' ),
					$attempts,
					Retry::MAX_ATTEMPTS
				);
			}
		}

		return $html . $muted( trim( $message . ' ' . $tail ) );
	}
}
