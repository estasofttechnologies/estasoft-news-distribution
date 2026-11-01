<?php
/**
 * The Release plan tab: the timeline, the plan form and the hold.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Control;

use Estasoft\NewsDistribution\Admin\Check\Times;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\Dialog;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Control\Plan;
use Estasoft\NewsDistribution\Core\Hold;
use Estasoft\NewsDistribution\Core\Operations\Flash;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Draws what `Core\Hold` knows. Nothing here decides anything: the timeline, the plan and the hold are
 * the same ones the pushes and the feeds read.
 */
final class ReleaseView {

	private Plugin $plugin;

	private Hold $hold;

	private int $post_id;

	private string $url;

	/**
	 * @param Plugin $plugin  The running plugin.
	 * @param int    $post_id Article ID.
	 * @param string $url     The tab's own address, where its forms post.
	 */
	public function __construct( Plugin $plugin, int $post_id, string $url ) {
		$this->plugin  = $plugin;
		$this->hold    = $plugin->make( Hold::class );
		$this->post_id = $post_id;
		$this->url     = $url;
	}

	/**
	 * The whole tab as escaped HTML.
	 *
	 * @return string
	 */
	public function render(): string {
		$state    = $this->hold->state( $this->post_id );
		$can      = $this->plugin->access()->can( Access::VIEW, $this->post_id );
		$timeline = new Timeline( $this->plugin, $this->post_id );

		$main = Card::flush( 'release-timeline', __( 'Timeline', 'estasoft-news-distribution' ), '', $timeline->render() );

		if ( $can ) {
			$left = $this->plan_card( $state ) . $this->hold_card( $state );
		} else {
			$left = Message::text( 'info', __( 'Only editors can change when this article goes out. You can see the plan here.', 'estasoft-news-distribution' ) );
		}

		$split = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-split' ),
			Html::tag( 'div', array( 'class' => 'estasoft-nd-split__main' ), $left )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-split__side estasoft-nd-split__side--wide' ), $this->side() )
		);

		return Html::tag( 'div', array( 'class' => 'estasoft-nd-ctl-stack' ), Flash::take() . $this->state_line( $state ) . $main . $split );
	}

	/**
	 * A line under the heading that says what is true now: on hold, an exclusive, or nothing special.
	 *
	 * @param array<string, mixed> $state What `Hold::state()` returned.
	 * @return string Escaped HTML, or an empty string.
	 */
	private function state_line( array $state ): string {
		$held = $state['held'];

		if ( null !== $held ) {
			$by   = $held['by'] > 0 ? (string) get_the_author_meta( 'display_name', (int) $held['by'] ) : '';
			$text = sprintf(
				/* translators: 1: a time such as 14:10, 2: a person's name, 3: the reason they gave. */
				__( 'Held since %1$s by %2$s. Reason: %3$s', 'estasoft-news-distribution' ),
				Times::at( (int) $held['at'] ),
				'' === $by ? __( 'someone', 'estasoft-news-distribution' ) : $by,
				$held['reason']
			);

			return $this->line( 'warning', __( 'On hold', 'estasoft-news-distribution' ), $text );
		}

		if ( $state['exclusive']['active'] ) {
			$name = $this->hold->channel_name( (string) $state['plan']['exclusive'] );

			if ( null === $state['exclusive']['ends'] ) {
				return $this->line(
					'info',
					sprintf(
						/* translators: %s: a channel name such as Microsoft Start. */
						__( 'Exclusive: %s until you release it', 'estasoft-news-distribution' ),
						$name
					),
					''
				);
			}

			return $this->line(
				'info',
				sprintf(
					/* translators: 1: a channel name such as Microsoft Start, 2: a time such as 14:38. */
					__( 'Exclusive: %1$s until %2$s', 'estasoft-news-distribution' ),
					$name,
					Times::at( (int) $state['exclusive']['ends'] )
				),
				''
			);
		}

		return '';
	}

	/**
	 * A badge and a sentence.
	 *
	 * @param string $tone  warning or info.
	 * @param string $badge The badge text.
	 * @param string $text  The sentence, or empty.
	 * @return string
	 */
	private function line( string $tone, string $badge, string $text ): string {
		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8 estasoft-nd-ctl-state' ),
			Html::tag(
				'span',
				array( 'class' => 'estasoft-nd-badge estasoft-nd-badge--' . $tone ),
				Icon::svg( 'warning' === $tone ? 'warning' : 'info' ) . esc_html( $badge )
			)
			. ( '' === $text ? '' : Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html( $text ) ) )
		);
	}

	// ---------------------------------------------------------------------
	// The plan.
	// ---------------------------------------------------------------------

	/**
	 * The "Plan" card.
	 *
	 * @param array<string, mixed> $state What `Hold::state()` returned.
	 * @return string Escaped HTML.
	 */
	private function plan_card( array $state ): string {
		$plan   = $state['plan'];
		$usual  = (int) $state['usual'];
		$mode   = (string) $plan['mode'];
		$rows   = $this->mode_row( $mode, $usual ) . $this->exclusive_rows( $plan, $state ) . $this->custom_rows( $plan );
		$active = (bool) $state['exclusive']['active'];

		$buttons = Button::submit( __( 'Save plan', 'estasoft-news-distribution' ), array( 'variant' => 'primary' ) );

		if ( $active ) {
			$buttons .= Dialog::trigger( 'end-exclusive', __( 'End the exclusive now', 'estasoft-news-distribution' ) );
		}

		$foot = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-ctl-foot' ),
			Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ), $buttons )
			. Form::note(
				$active
					? __( 'Save plan applies to this article only. End the exclusive now sends the article to every other channel at once and cannot be undone: you are asked to confirm first.', 'estasoft-news-distribution' )
					: __( 'Save plan applies to this article only. A channel that already has the article keeps it.', 'estasoft-news-distribution' )
			)
		);

		$card = Card::flush(
			'release-plan',
			__( 'Plan', 'estasoft-news-distribution' ),
			'',
			Form::open( $this->url, 'plan', $this->post_id, $rows . $foot, array( 'data-ctl-plan' => true ) )
		);

		if ( ! $active ) {
			return $card;
		}

		return $card . Dialog::render(
			'end-exclusive',
			__( 'End the exclusive now?', 'estasoft-news-distribution' ),
			__( 'Every other channel gets the article at once. This cannot be undone.', 'estasoft-news-distribution' ),
			Form::open( $this->url, 'end', $this->post_id, Button::submit( __( 'End the exclusive now', 'estasoft-news-distribution' ), array( 'variant' => 'primary' ) ), array( 'class' => 'estasoft-nd-ctl-inline' ) )
		);
	}

	/**
	 * The "Release" row.
	 *
	 * @param string $mode  The saved mode.
	 * @param int    $usual The usual delay in seconds.
	 * @return string Escaped HTML.
	 */
	private function mode_row( string $mode, int $usual ): string {
		$options = array(
			Plan::MODE_DEFAULT   => sprintf(
				/* translators: %s: a length of time such as "15 minutes". */
				__( 'Default: after the usual delay (%s)', 'estasoft-news-distribution' ),
				Plan::words( $usual )
			),
			Plan::MODE_NOW       => __( 'Right away, no delay', 'estasoft-news-distribution' ),
			Plan::MODE_EXCLUSIVE => __( 'Exclusive to one channel first', 'estasoft-news-distribution' ),
			Plan::MODE_CUSTOM    => __( 'Custom time per channel', 'estasoft-news-distribution' ),
		);

		return Form::row(
			'estasoft-nd-ctl-mode',
			__( 'Release', 'estasoft-news-distribution' ),
			Form::select( 'estasoft-nd-ctl-mode', Form::name( 'mode' ), $options, $mode, array( 'data-ctl-mode' => true ) ),
			sprintf(
				/* translators: %s: a length of time such as "15 minutes". */
				__( 'Default sends pushes after the usual %s, so your site is crawled first, and feeds carry the article from the moment it is published. Custom lets you set a time for each channel.', 'estasoft-news-distribution' ),
				Plan::words( $usual )
			)
		);
	}

	/**
	 * The rows that belong to an exclusive: which channel, for how long, and whether search waits too.
	 *
	 * @param array<string, mixed> $plan  The saved plan.
	 * @param array<string, mixed> $state What `Hold::state()` returned.
	 * @return string Escaped HTML.
	 */
	private function exclusive_rows( array $plan, array $state ): string {
		$choices = $this->hold->exclusive_choices();
		$blocked = $this->hold->exclusive_blocked();
		$usual   = (int) $state['usual'];
		$hidden  = Plan::MODE_EXCLUSIVE !== $plan['mode'];
		$attrs   = array(
			'data-ctl-for' => Plan::MODE_EXCLUSIVE,
			'hidden'       => $hidden,
		);

		$help = '';

		if ( array() !== $blocked ) {
			$help = sprintf(
				/* translators: %s: channel names such as "Apple News and SmartNews". */
				_n( '%s is In review, so it cannot be the exclusive yet.', '%s are In review, so they cannot be the exclusive yet.', count( $blocked ), 'estasoft-news-distribution' ),
				wp_sprintf( '%l', array_values( $blocked ) )
			);
		}

		if ( array() === $choices ) {
			$control = Form::note( __( 'No channel can have the article first yet. A channel needs to be on, and approved by its platform.', 'estasoft-news-distribution' ) );
		} else {
			$current = isset( $choices[ $plan['exclusive'] ] ) ? (string) $plan['exclusive'] : (string) array_key_first( $choices );
			$control = Form::select( 'estasoft-nd-ctl-exclusive', Form::name( 'exclusive' ), $choices, $current );
		}

		$ends = '';

		if ( $state['exclusive']['active'] && null !== $state['exclusive']['ends'] ) {
			$ends = sprintf(
				/* translators: %s: a time such as 14:38. */
				__( 'The exclusive ends at %s. The other channels go out then.', 'estasoft-news-distribution' ),
				Times::at( (int) $state['exclusive']['ends'] )
			);
		} elseif ( $state['exclusive']['active'] ) {
			$ends = __( 'The exclusive lasts until you end it. The other channels wait.', 'estasoft-news-distribution' );
		}

		$rows  = Form::row( 'estasoft-nd-ctl-exclusive', __( 'Exclusive to', 'estasoft-news-distribution' ), $control, $help, $attrs );
		$rows .= Form::row(
			'estasoft-nd-ctl-duration',
			__( 'For', 'estasoft-news-distribution' ),
			Form::select( 'estasoft-nd-ctl-duration', Form::name( 'duration' ), Plan::exclusive_choices( $usual ), $this->duration_value( $plan, $usual ) ),
			'' === $ends ? __( 'The other channels go out when the exclusive ends.', 'estasoft-news-distribution' ) : $ends,
			$attrs
		);

		$check = Html::tag(
			'label',
			array(
				'class' => 'estasoft-nd-check estasoft-nd-check--top',
				'for'   => 'estasoft-nd-ctl-delay-search',
			),
			Html::void(
				'input',
				array(
					'type'    => 'checkbox',
					'id'      => 'estasoft-nd-ctl-delay-search',
					'name'    => Form::name( 'delay_search' ),
					'value'   => '1',
					'checked' => (bool) $plan['delay_search'],
				)
			)
			. Html::tag(
				'span',
				array(),
				Html::tag( 'strong', array(), esc_html__( 'Also delay Google News and the search sitemaps', 'estasoft-news-distribution' ) )
				. '<br>'
				. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Leave off unless the exclusive is a contract term. Crawlers read your site anyway.', 'estasoft-news-distribution' ) )
			)
		);

		return $rows . Html::tag( 'div', array_merge( array( 'class' => 'estasoft-nd-form-row estasoft-nd-form-row--wide' ), $attrs ), $check );
	}

	/**
	 * The value the "For" select shows. A saved duration that is not in the list stays in the list.
	 *
	 * @param array<string, mixed> $plan  The saved plan.
	 * @param int                  $usual The usual delay in seconds.
	 * @return int
	 */
	private function duration_value( array $plan, int $usual ): int {
		$duration = (int) $plan['duration'];

		if ( Plan::USUAL === $duration || Plan::UNTIL_RELEASED === $duration ) {
			return $duration;
		}

		return array_key_exists( $duration, Plan::exclusive_choices( $usual ) ) ? $duration : Plan::USUAL;
	}

	/**
	 * The rows of a custom plan: one wait for every channel, and one for each channel that differs.
	 *
	 * @param array<string, mixed> $plan The saved plan.
	 * @return string Escaped HTML.
	 */
	private function custom_rows( array $plan ): string {
		$hidden  = Plan::MODE_CUSTOM !== $plan['mode'];
		$attrs   = array(
			'data-ctl-for' => Plan::MODE_CUSTOM,
			'hidden'       => $hidden,
		);
		$choices = Plan::custom_choices();
		$all     = isset( $plan['times'][ Plan::ALL ] ) ? (int) $plan['times'][ Plan::ALL ] : '';
		$rows    = Form::row(
			'estasoft-nd-ctl-time-all',
			__( 'Every channel', 'estasoft-news-distribution' ),
			Form::select( 'estasoft-nd-ctl-time-all', Form::name( 'times', Plan::ALL ), array( '' => __( 'The usual', 'estasoft-news-distribution' ) ) + $choices, $this->nearest( $all, $choices ) ),
			__( 'How long after publishing a channel gets the article. A channel below can have its own time.', 'estasoft-news-distribution' ),
			$attrs
		);

		foreach ( $this->hold->channels_state( $this->post_id ) as $channel ) {
			if ( $channel['review'] ) {
				continue;
			}

			$value = isset( $plan['times'][ $channel['id'] ] ) ? (int) $plan['times'][ $channel['id'] ] : '';
			$id    = 'estasoft-nd-ctl-time-' . sanitize_key( (string) $channel['id'] );

			$rows .= Form::row(
				$id,
				(string) $channel['label'],
				Form::select( $id, Form::name( 'times', (string) $channel['id'] ), array( '' => __( 'Same as every channel', 'estasoft-news-distribution' ) ) + $choices, $this->nearest( $value, $choices ) ),
				'',
				$attrs
			);
		}

		return $rows;
	}

	/**
	 * The choice closest to a stored wait, so a value saved by the editor panel or the REST API still shows.
	 *
	 * @param int|string        $value   Seconds, or an empty string.
	 * @param array<int,string> $choices The choices.
	 * @return int|string
	 */
	private function nearest( $value, array $choices ) {
		if ( '' === $value ) {
			return '';
		}

		$best = '';
		$gap  = PHP_INT_MAX;

		foreach ( array_keys( $choices ) as $seconds ) {
			if ( abs( $seconds - (int) $value ) < $gap ) {
				$gap  = abs( $seconds - (int) $value );
				$best = $seconds;
			}
		}

		return $best;
	}

	// ---------------------------------------------------------------------
	// The hold.
	// ---------------------------------------------------------------------

	/**
	 * The "Hold" card: hold the article, or release the hold.
	 *
	 * @param array<string, mixed> $state What `Hold::state()` returned.
	 * @return string Escaped HTML.
	 */
	private function hold_card( array $state ): string {
		$post = get_post( $this->post_id );

		if ( null !== $state['held'] ) {
			return Card::note(
				'release-hold',
				__( 'Hold', 'estasoft-news-distribution' ),
				Form::note( __( 'While an article is on hold, partner feeds, feed readers and pushes do not carry it. Your own site, the search sitemaps and Google News are not held.', 'estasoft-news-distribution' ) )
				. Form::open(
					$this->url,
					'release_hold',
					$this->post_id,
					Html::tag(
						'div',
						array( 'class' => 'estasoft-nd-ctl-foot' ),
						Button::submit( __( 'Release hold', 'estasoft-news-distribution' ), array( 'variant' => 'primary' ) )
						. Form::note( __( 'Releasing queues what the hold kept back. Pushes go out at once, and feeds carry the article again.', 'estasoft-news-distribution' ) )
					)
				)
			);
		}

		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_status, array( 'publish', 'future' ), true ) ) {
			return Card::note( 'release-hold', __( 'Hold', 'estasoft-news-distribution' ), Form::note( __( 'Only a published or scheduled article can be held.', 'estasoft-news-distribution' ) ) );
		}

		return Card::note(
			'release-hold',
			__( 'Hold', 'estasoft-news-distribution' ),
			Form::note( __( 'Holding keeps the article out of partner feeds, feed readers and pushes until you release it. Your own site, the search sitemaps and Google News are not held.', 'estasoft-news-distribution' ) )
			. Form::open(
				$this->url,
				'hold',
				$this->post_id,
				Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ),
					Html::tag(
						'label',
						array(
							'class' => 'estasoft-nd-label',
							'for'   => 'estasoft-nd-ctl-hold-reason',
						),
						esc_html__( 'Why are you holding it? (required)', 'estasoft-news-distribution' )
					)
					. Html::void(
						'input',
						array(
							'type'         => 'text',
							'id'           => 'estasoft-nd-ctl-hold-reason',
							'name'         => Form::name( 'reason' ),
							'class'        => 'estasoft-nd-field estasoft-nd-w-full',
							'maxlength'    => (string) Hold::REASON_MAX,
							'autocomplete' => 'off',
							'required'     => true,
							'placeholder'  => __( 'For the delivery log', 'estasoft-news-distribution' ),
						)
					)
					. Html::tag( 'div', array(), Button::submit( __( 'Hold article', 'estasoft-news-distribution' ) ) )
				)
			)
		);
	}

	// ---------------------------------------------------------------------
	// The side.
	// ---------------------------------------------------------------------

	/**
	 * "Good to know" and "What it cannot do".
	 *
	 * @return string Escaped HTML.
	 */
	private function side(): string {
		$good = '';

		foreach ( array(
			__( 'An exclusive ends by itself at the time shown.', 'estasoft-news-distribution' ),
			__( 'Your own site always goes first.', 'estasoft-news-distribution' ),
			__( 'A plan counts from the moment the article is published. A change applies to channels that have not received the article yet.', 'estasoft-news-distribution' ),
		) as $line ) {
			$good .= Html::tag( 'li', array( 'class' => 'estasoft-nd-rule' ), Icon::svg( 'check' ) . Html::tag( 'span', array(), esc_html( $line ) ) );
		}

		return Card::note( 'release-good', __( 'Good to know', 'estasoft-news-distribution' ), Html::tag( 'ul', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $good ) )
			. Card::cannot(
				array(
					__( 'It cannot make a platform fetch at a set time. Google, Microsoft Start and the others read your feed when they choose, so the plan only decides when News Distribution adds the article to a feed or sends a ping.', 'estasoft-news-distribution' ),
					__( 'Nothing is sent to a channel that is only In review, such as Apple News.', 'estasoft-news-distribution' ),
				)
			);
	}
}
