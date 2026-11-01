<?php
/**
 * Outlets, Telegram.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Channels\Outlets;

use Estasoft\NewsDistribution\Admin\Ui\Field;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Channels\Outlets\AbstractOutlet;
use Estasoft\NewsDistribution\Channels\Outlets\PostText;
use Estasoft\NewsDistribution\Core\Language\Catalog;
use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Four steps: make a bot, add it to the channel, paste its token and name a
 * channel for each language, choose what a post holds. Steps 1 and 2 happen
 * inside Telegram, where News Distribution cannot look; the test message in
 * step 3 is the proof that they worked.
 */
final class TelegramTab extends AbstractTab {

	public function slug(): string {
		return 'telegram';
	}

	public function render(): string {
		$main = $this->connection_card();
		$side = $this->preview_card()
			. $this->side_card(
				__( 'Telegram’s limits', 'estasoft-news-distribution' ),
				$this->rules(
					array(
						__( 'About one message a second in one chat', 'estasoft-news-distribution' ),
						__( 'About 20 a minute in one group. Telegram gives no figure for channels', 'estasoft-news-distribution' ),
						__( 'About 30 a second across all chats', 'estasoft-news-distribution' ),
						__( 'When a bot posts too often, Telegram says how long to wait, and News Distribution waits', 'estasoft-news-distribution' ),
					)
				)
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'News Distribution queues posts, so a burst of articles is spaced out. These are Telegram’s own guidelines, and they can change.', 'estasoft-news-distribution' ) )
			)
			. $this->cannot(
				array(
					__( 'See inside Telegram: whether the bot is an administrator, or whether anyone read a post', 'estasoft-news-distribution' ),
					__( 'Edit or delete a post after it is sent. Do that in Telegram', 'estasoft-news-distribution' ),
					__( 'Post to a channel the bot has not been added to', 'estasoft-news-distribution' ),
				)
			);

		return $this->form( $this->split( $main, $side ), __( 'Telegram connection', 'estasoft-news-distribution' ) );
	}

	/**
	 * The card with the four steps.
	 *
	 * @return string
	 */
	private function connection_card(): string {
		$status = $this->channel->service_status( 'telegram' );
		$is_on  = Status::NOT_SET_UP !== $status['code'];
		$id     = 'estasoft-nd-card-telegram';
		$head   = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-card-head estasoft-nd-outlets-headrow' ),
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-grow' ),
				Html::tag(
					'h2',
					array(
						'class' => 'estasoft-nd-title',
						'id'    => $id,
					),
					esc_html__( 'Telegram connection', 'estasoft-news-distribution' )
				)
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Steps 1 and 2 happen inside Telegram, where News Distribution cannot look. The test message in step 3 is the proof that they worked.', 'estasoft-news-distribution' ) )
				. ( '' !== $status['detail'] ? Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $status['detail'] ) ) : '' )
			)
			. StatusView::pill( $status['code'] )
		);

		$steps  = $this->step(
			1,
			__( 'Create a bot', 'estasoft-news-distribution' ),
			__( 'In Telegram, message @BotFather, send /newbot and follow the steps. It gives you a token.', 'estasoft-news-distribution' ),
			Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'The bot is only a sender. It does not read your channel.', 'estasoft-news-distribution' ) )
		);
		$steps .= $this->step(
			2,
			__( 'Add the bot to your channel', 'estasoft-news-distribution' ),
			__( 'Open the channel’s administrators and add the bot, with permission to post messages.', 'estasoft-news-distribution' )
		);
		$steps .= $this->step(
			3,
			__( 'Paste the token and name each channel', 'estasoft-news-distribution' ),
			__( 'Use @name for a public channel, or the channel’s number for a private one (it starts with -100). Each language can post to its own channel.', 'estasoft-news-distribution' ),
			$this->token_row() . $this->channels_table() . $this->test_row(),
			true
		);
		$steps .= $this->step(
			4,
			__( 'Choose what is posted', 'estasoft-news-distribution' ),
			__( 'A Telegram message holds up to 4,096 characters. News Distribution shortens the summary to fit.', 'estasoft-news-distribution' ),
			$this->content_row( $is_on )
		);

		return Html::tag(
			'section',
			array(
				'class'           => 'estasoft-nd-card estasoft-nd-card--flush',
				'aria-labelledby' => $id,
			),
			$head . $steps
		);
	}

	/**
	 * The token.
	 *
	 * @return string
	 */
	private function token_row(): string {
		$id = Field::id( $this->screen->scope_id(), 'telegram_token' );

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-form-row estasoft-nd-outlets-bare' ),
			Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-form-label',
					'for'   => $id,
				),
				esc_html__( 'Token', 'estasoft-news-distribution' )
			)
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-form-control' ),
				$this->secret_input( 'telegram_token', __( 'Bot token', 'estasoft-news-distribution' ), true, false )
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Stored in your database and never shown again. It is sent only to Telegram.', 'estasoft-news-distribution' ) )
			)
		);
	}

	/**
	 * One channel for each language.
	 *
	 * @return string
	 */
	private function channels_table(): string {
		$head   = Html::tag(
			'div',
			array(
				'class' => 'estasoft-nd-grid-row estasoft-nd-grid-row--head estasoft-nd-outlets-cols-chat',
				'role'  => 'row',
			),
			Html::tag( 'span', array( 'role' => 'columnheader' ), esc_html__( 'Language', 'estasoft-news-distribution' ) )
			. Html::tag( 'span', array( 'role' => 'columnheader' ), esc_html__( 'Channel', 'estasoft-news-distribution' ) )
			. Html::tag( 'span', array( 'role' => 'columnheader' ), esc_html__( 'Status', 'estasoft-news-distribution' ) )
		);
		$body   = '';
		$values = $this->values();

		foreach ( $this->channel->site_languages() as $code ) {
			$field = 'telegram_chat_' . AbstractOutlet::suffix( $code );
			$chat  = trim( (string) ( $values[ $field ] ?? '' ) );

			$status = '' === $chat
				? Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Leave empty to skip', 'estasoft-news-distribution' ) )
				: $this->test_status( 'telegram:' . $code );

			$body .= Html::tag(
				'div',
				array(
					'class' => 'estasoft-nd-grid-row estasoft-nd-outlets-cols-chat',
					'role'  => 'row',
				),
				Html::tag( 'div', array( 'role' => 'cell' ), $this->lang( $code ) )
				. Html::tag(
					'div',
					array( 'role' => 'cell' ),
					$this->text_input(
						$field,
						sprintf(
							/* translators: %s: a language name such as English. */
							__( '%s channel', 'estasoft-news-distribution' ),
							$this->language_name( $code )
						),
						array(
							'maxlength'   => 64,
							'dir'         => 'ltr',
							'placeholder' => '@name',
						)
					)
				)
				. Html::tag(
					'div',
					array(
						'role'  => 'cell',
						'class' => 'estasoft-nd-stack estasoft-nd-gap-4',
					),
					$status
				)
			);
		}

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-scroll' ),
			Html::tag(
				'div',
				array(
					'role'       => 'table',
					'aria-label' => __( 'Telegram channels', 'estasoft-news-distribution' ),
				),
				$head . $body
			)
		);
	}

	/**
	 * The button that posts a test message.
	 *
	 * @return string
	 */
	private function test_row(): string {
		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-12' ),
			$this->action_button( 'telegram_test', __( 'Send a test message', 'estasoft-news-distribution' ) )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small estasoft-nd-outlets-help' ), esc_html__( 'Posts one short test message to each channel you filled in. Subscribers can see it, so try a private test channel first. Each row then shows Test passed or Telegram’s own error.', 'estasoft-news-distribution' ) )
		);
	}

	/**
	 * What a post holds, and the button that saves and turns Telegram on.
	 *
	 * @param bool $is_on Whether Telegram is on now.
	 * @return string
	 */
	private function content_row( bool $is_on ): string {
		$buttons = $is_on
			? $this->action_button( 'save', __( 'Save changes', 'estasoft-news-distribution' ), array( 'variant' => 'primary' ) )
			: $this->action_button( 'telegram_on', __( 'Save and turn on', 'estasoft-news-distribution' ), array( 'variant' => 'primary' ) );

		return $this->checkbox( 'telegram_headline', __( 'The headline', 'estasoft-news-distribution' ) )
			. $this->checkbox( 'telegram_summary', __( 'A short summary', 'estasoft-news-distribution' ), __( 'The article’s excerpt, or its first two sentences when it has no excerpt.', 'estasoft-news-distribution' ) )
			. $this->checkbox( 'telegram_preview', __( 'A link preview', 'estasoft-news-distribution' ), __( 'Telegram shows the article’s image and title from the link.', 'estasoft-news-distribution' ) )
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-12' ),
				$buttons
				. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small estasoft-nd-outlets-help' ), esc_html__( 'Nothing is posted until an article goes live. You can turn Telegram off here at any time.', 'estasoft-news-distribution' ) )
			)
			. ( $is_on ? Html::tag( 'div', array(), $this->action_button( 'telegram_off', __( 'Turn off Telegram', 'estasoft-news-distribution' ), array( 'size' => 'sm' ) ) ) : '' );
	}

	/**
	 * How a post looks, from the newest article and the settings as saved.
	 *
	 * @return string
	 */
	private function preview_card(): string {
		list( $article, $example ) = $this->preview_article();

		$settings = $this->channel->settings();
		$headline = ! empty( $settings['telegram_headline'] ) ? PostText::headline( $article ) : '';
		$url      = $article->permalink();
		$limit    = (int) $this->plugin->rules()->limit( 'outlets', 'telegram_message_max', 4096 );
		$room     = $limit - PostText::utf16( $headline ) - PostText::utf16( $url ) - 8;
		$summary  = ! empty( $settings['telegram_summary'] ) ? PostText::shorten( PostText::summary( $article ), max( 0, $room ), array( PostText::class, 'utf16' ) ) : '';

		$box = ( '' !== $headline ? Html::tag( 'p', array( 'class' => 'estasoft-nd-strong' ), esc_html( $headline ) ) : '' )
			. ( '' !== $summary ? Html::tag( 'p', array(), esc_html( $summary ) ) : '' )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-outlets-link' ), esc_html( $url ) );

		return $this->side_card(
			__( 'How a post looks', 'estasoft-news-distribution' ),
			Html::tag(
				'div',
				array(
					'class' => 'estasoft-nd-outlets-sample',
					'lang'  => $article->language(),
					'dir'   => Catalog::direction( $article->language() ),
				),
				$box
			) . $this->example_tag( $example )
		);
	}
}
