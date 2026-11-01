<?php
/**
 * The Takedown tab: channel by channel, what was removed, what was sent and what needs the person.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Control;

use Estasoft\NewsDistribution\Admin\Check\ChannelRows;
use Estasoft\NewsDistribution\Admin\Check\Times;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\Dialog;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Core\Operations\Flash;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Core\Takedown;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Draws what `Core\Takedown` read. It says what News Distribution did, never what a platform shows now:
 * a notice that went through is "Sent", and only what the plugin builds itself is "Removed".
 */
final class TakedownView {

	private Plugin $plugin;

	private int $post_id;

	private string $url;

	/**
	 * @param Plugin $plugin  The running plugin.
	 * @param int    $post_id Article ID.
	 * @param string $url     The tab's own address, where its forms post.
	 */
	public function __construct( Plugin $plugin, int $post_id, string $url ) {
		$this->plugin  = $plugin;
		$this->post_id = $post_id;
		$this->url     = $url;
	}

	/**
	 * The whole tab as escaped HTML.
	 *
	 * @return string
	 */
	public function render(): string {
		$read = $this->plugin->make( Takedown::class )->read( $this->post_id );
		$down = 'down' === $read['status'];
		$post = get_post( $this->post_id );
		$live = $post instanceof \WP_Post && 'publish' === $post->post_status;

		$intro = '';

		if ( 'restored' === $read['status'] ) {
			$intro = Message::text(
				'info',
				sprintf(
					/* translators: %s: a time such as 10:52. */
					__( 'Restored at %s. The article is back in your feeds and sitemaps. Removal notices that were already sent still stand, and a story deleted on a platform stays deleted.', 'estasoft-news-distribution' ),
					Times::at( Times::from_iso( (string) $read['restored'] ) )
				)
			);
		}

		$main = Card::flush(
			'takedown-channels',
			__( 'Channel by channel', 'estasoft-news-distribution' ),
			$down
				? __( 'News Distribution removes the article from everything it builds and sends. Some platforms need you to finish the job.', 'estasoft-news-distribution' )
				: __( 'What taking this article down would do for each channel. Nothing has been removed yet.', 'estasoft-news-distribution' ),
			$this->rows( $read )
		);

		$side = $down ? $this->summary_card( $read ) . $this->post_card( $post, $live ) : $this->start_card( $read, $live );

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-ctl-stack' ),
			Flash::take() . $intro . $this->state_line( $read )
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-split' ),
				Html::tag( 'div', array( 'class' => 'estasoft-nd-split__main' ), $main )
				. Html::tag( 'div', array( 'class' => 'estasoft-nd-split__side estasoft-nd-split__side--wide' ), $side )
			)
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-notes' ),
				Card::cannot(
					array(
						__( 'It cannot make a platform remove an article, delete a story on Medium or in News Publisher, or recall a notice that was already sent. It removes the article from everything News Distribution builds and sends.', 'estasoft-news-distribution' ),
					)
				)
			)
		);
	}

	/**
	 * The line under the heading once a takedown has started: how it stands, and since when.
	 *
	 * @param array<string, mixed> $read What `Takedown::read()` returned.
	 * @return string Escaped HTML, or an empty string.
	 */
	private function state_line( array $read ): string {
		if ( 'down' !== $read['status'] || '' === $read['health'] ) {
			return '';
		}

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8 estasoft-nd-ctl-state' ),
			StatusView::pill( (string) $read['health'] )
			. Html::tag(
				'span',
				array( 'class' => 'estasoft-nd-muted' ),
				esc_html(
					sprintf(
						/* translators: %s: a time such as 10:40. */
						__( 'Taken down at %s', 'estasoft-news-distribution' ),
						Times::at( (int) $read['started_ts'] )
					)
				)
			)
		);
	}

	/**
	 * One row for each channel.
	 *
	 * @param array<string, mixed> $read What `Takedown::read()` returned.
	 * @return string Escaped HTML.
	 */
	private function rows( array $read ): string {
		$html = '';

		foreach ( $read['steps'] as $step ) {
			$status = (string) $step['status'];
			$action = '';

			if ( is_array( $step['link'] ) && '' !== (string) $step['link']['url'] ) {
				$action = Button::link(
					(string) $step['link']['label'],
					(string) $step['link']['url'],
					array(
						'size'  => 'sm',
						'attrs' => array(
							'target' => '_blank',
							'rel'    => 'noopener noreferrer',
						),
					)
				);
			}

			$html .= ChannelRows::row(
				esc_html( (string) $step['label'] ),
				'' === $status ? '' : StatusView::pill( $status ),
				Html::tag( 'p', array(), esc_html( (string) $step['message'] ) ),
				$action
			);
		}

		return '' === $html ? Html::tag( 'p', array( 'class' => 'estasoft-nd-pad estasoft-nd-muted' ), esc_html__( 'No channel is on, so there is nothing to remove the article from.', 'estasoft-news-distribution' ) ) : $html;
	}

	// ---------------------------------------------------------------------
	// Before: start a takedown.
	// ---------------------------------------------------------------------

	/**
	 * The card that starts a takedown.
	 *
	 * @param array<string, mixed> $read What `Takedown::read()` returned.
	 * @param bool                 $live Whether the post is published.
	 * @return string Escaped HTML.
	 */
	private function start_card( array $read, bool $live ): string {
		if ( ! $live ) {
			return Card::note( 'takedown-start', __( 'Take down', 'estasoft-news-distribution' ), Form::note( __( 'Only a published article can be taken down.', 'estasoft-news-distribution' ) ) );
		}

		$title = sprintf(
			/* translators: %s: the article's title. */
			__( 'Take down “%s” from every channel?', 'estasoft-news-distribution' ),
			(string) $read['title']
		);

		return Card::note(
			'takedown-start',
			__( 'Take down', 'estasoft-news-distribution' ),
			Form::note( __( 'The article leaves every feed and sitemap News Distribution builds, and channels that take removal notices are told. The post stays online until you switch it to draft.', 'estasoft-news-distribution' ) )
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-ctl-foot' ),
				Dialog::trigger( 'takedown-start', __( 'Take down from every channel', 'estasoft-news-distribution' ), array( 'variant' => 'primary' ) )
				. Form::note( __( 'You are asked to confirm first. You can restore the article to your feeds later; notices already sent cannot be recalled.', 'estasoft-news-distribution' ) )
			)
		) . Dialog::render(
			'takedown-start',
			$title,
			__( 'It leaves your feeds and sitemaps now and removal notices are sent. Nothing is deleted from your site. Notices that were sent cannot be taken back, and each platform decides when it drops the article.', 'estasoft-news-distribution' ),
			Form::open( $this->url, 'start', $this->post_id, Button::submit( __( 'Take down now', 'estasoft-news-distribution' ), array( 'variant' => 'primary' ) ), array( 'class' => 'estasoft-nd-ctl-inline' ) )
		);
	}

	// ---------------------------------------------------------------------
	// After: what happened.
	// ---------------------------------------------------------------------

	/**
	 * The summary card: counts, who and when, and the way back.
	 *
	 * @param array<string, mixed> $read What `Takedown::read()` returned.
	 * @return string Escaped HTML.
	 */
	private function summary_card( array $read ): string {
		$sum   = $read['summary'];
		$lines = '';

		if ( $sum['removed'] > 0 ) {
			$lines .= $this->line(
				Status::REMOVED,
				sprintf(
					/* translators: %d: how many channels. */
					_n( '%d removed from your feeds and sitemaps', '%d removed from your feeds and sitemaps', (int) $sum['removed'], 'estasoft-news-distribution' ),
					(int) $sum['removed']
				)
			);
		}

		if ( $sum['sent'] > 0 ) {
			$lines .= $this->line(
				Status::SENT,
				sprintf(
					/* translators: %d: how many channels. */
					_n( '%d removal notice sent', '%d removal notices sent', (int) $sum['sent'], 'estasoft-news-distribution' ),
					(int) $sum['sent']
				)
			);
		}

		if ( $sum['attention'] > 0 ) {
			$lines .= $this->line(
				Status::ATTENTION,
				sprintf(
					/* translators: 1: how many channels need the person, 2: their names. */
					_n( '%1$d needs you: %2$s', '%1$d need you: %2$s', (int) $sum['attention'], 'estasoft-news-distribution' ),
					(int) $sum['attention'],
					wp_sprintf( '%l', $sum['needs'] )
				)
			);
		}

		$by   = is_array( $read['by'] ) ? (string) $read['by']['name'] : '';
		$when = Times::at( (int) $read['started_ts'] );
		$text = '' === $by
			? sprintf(
				/* translators: %s: a time such as 10:40. */
				__( 'Started %s.', 'estasoft-news-distribution' ),
				$when
			)
			: sprintf(
				/* translators: 1: a time such as 10:40, 2: a person's name. */
				__( 'Started %1$s by %2$s.', 'estasoft-news-distribution' ),
				$when,
				$by
			);

		$text .= ' ' . __( 'This page shows what News Distribution did. It cannot see what each platform shows now, and each platform decides when it drops the article.', 'estasoft-news-distribution' );

		$retry = $sum['attention'] > 0
			? Form::open( $this->url, 'start', $this->post_id, Button::submit( __( 'Try the notices again', 'estasoft-news-distribution' ) ), array( 'class' => 'estasoft-nd-ctl-inline' ) )
			: '';

		$restore = Form::open( $this->url, 'restore', $this->post_id, Button::submit( __( 'Restore to channels', 'estasoft-news-distribution' ) ), array( 'class' => 'estasoft-nd-ctl-inline' ) );

		return Card::note(
			'takedown-summary',
			__( 'Summary', 'estasoft-news-distribution' ),
			Html::tag( 'ul', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8 estasoft-nd-ctl-sum' ), $lines )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-ctl-gap' ), esc_html( $text ) )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8 estasoft-nd-ctl-gap' ), $retry . $restore )
			. Form::note( __( 'You can undo this: Restore puts the article back in your feeds and sitemaps. It cannot take back notices already sent, or a story you deleted on a platform.', 'estasoft-news-distribution' ) )
		);
	}

	/**
	 * One line of the summary.
	 *
	 * @param string $code A status code, for the icon.
	 * @param string $text Plain text.
	 * @return string Escaped HTML.
	 */
	private function line( string $code, string $text ): string {
		$tone = Status::describe( $code )['tone'];

		return Html::tag(
			'li',
			array( 'class' => 'estasoft-nd-rule estasoft-nd-ctl-line estasoft-nd-ctl-line--' . $tone ),
			Icon::svg( Status::describe( $code )['icon'] ) . Html::tag( 'span', array(), esc_html( $text ) )
		);
	}

	/**
	 * The card about the post itself: it stays online until the person switches it to draft.
	 *
	 * @param \WP_Post|null $post The post.
	 * @param bool          $live Whether it is published.
	 * @return string Escaped HTML.
	 */
	private function post_card( ?\WP_Post $post, bool $live ): string {
		if ( null === $post ) {
			return '';
		}

		if ( ! $live ) {
			return Card::note(
				'takedown-post',
				__( 'The post on your site', 'estasoft-news-distribution' ),
				Form::note( __( 'The post is not published, so readers do not see it.', 'estasoft-news-distribution' ) )
			);
		}

		return Card::note(
			'takedown-post',
			__( 'The post on your site', 'estasoft-news-distribution' ),
			Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'The post is still online. Taking it down here removes it from channels only.', 'estasoft-news-distribution' ) )
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-ctl-gap' ),
				Form::open( $this->url, 'draft', $this->post_id, Button::submit( __( 'Switch the post to draft', 'estasoft-news-distribution' ) ), array( 'class' => 'estasoft-nd-ctl-inline' ) )
			)
			. Form::note( __( 'Readers no longer see the post once it is a draft. It takes effect at once, and you can publish the post again from its editor.', 'estasoft-news-distribution' ) )
		);
	}
}
