<?php
/**
 * The WebSub channel page.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Channels;

use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\Field;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Admin\Ui\Tag;
use Estasoft\NewsDistribution\Channels\Ping\AbstractPingChannel;
use Estasoft\NewsDistribution\Channels\WebSub\FeedLinkCheck;
use Estasoft\NewsDistribution\Channels\WebSub\WebSubChannel;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Retry;
use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * `admin.php?page=estasoft-nd-channels&channel=websub`. The page has three steps:
 * whether the open-reader feeds name the hub and themselves, which hub the pings
 * go to, and what is pinged. Under them it lists the latest pings and what the
 * hub answered. WebSub is pushed through the queue: nothing is sent until a hub
 * is entered and the channel is on, and "Send a test ping" is the only request
 * made by a button.
 */
final class WebSubScreen extends AbstractStepScreen {

	public static function channel_id(): string {
		return 'websub';
	}

	public function description(): string {
		return __( 'When an article is published, updated or removed, News Distribution pings your hub through the background queue, so readers that follow your feed through WebSub can fetch it at once. It does not make Google News or any other platform faster.', 'estasoft-news-distribution' );
	}

	public function actions( string $tab ): string {
		return Button::link( __( 'Send a test ping', 'estasoft-news-distribution' ), $this->action_url( 'test' ) );
	}

	protected function known_actions(): array {
		return array(
			'test'  => 'get',
			'check' => 'get',
		);
	}

	protected function perform( string $action, array $input ): array {
		unset( $input );

		$channel = $this->channel instanceof WebSubChannel ? $this->channel : null;

		if ( null === $channel ) {
			return array();
		}

		if ( 'check' === $action ) {
			FeedLinkCheck::run( $channel );

			return array( 'checked' => 1 );
		}

		return $this->finish_test( $channel->test() );
	}

	public function render( string $tab ): string {
		$channel = $this->channel instanceof WebSubChannel ? $this->channel : null;

		if ( null === $channel ) {
			return '';
		}

		$rows = $this->today( $channel );
		$head = $this->notice()
			. $this->off_bar( __( 'Nothing is pinged until you turn it on.', 'estasoft-news-distribution' ) )
			. $this->retry_bars( $rows );
		$main = $this->form( $this->steps_card( $channel ) ) . $this->pings_card( $rows );
		$side = $this->how_card() . $this->helps_card() . $this->rules_card(
			$channel->id(),
			__( 'WebSub', 'estasoft-news-distribution' ),
			array(
				'feed.hub_link'  => __( 'A hub link and a self link in every feed and in its HTTP headers', 'estasoft-news-distribution' ),
				'feed.self_link' => __( 'Each language feed uses its own exact address as its self link, never a redirect', 'estasoft-news-distribution' ),
				'ping.format'    => __( 'A ping on every publish, update and removal, sent through the queue', 'estasoft-news-distribution' ),
				'ping.queue'     => sprintf(
					/* translators: %d: the most attempts a ping gets. */
					__( 'Up to %d attempts, then Failed', 'estasoft-news-distribution' ),
					Retry::MAX_ATTEMPTS
				),
			)
		) . $this->cannot_card( __( 'It cannot make Google News, Bing or any other platform fetch sooner, see who subscribed, or fix a hub that is down. It sends the ping and shows the answer.', 'estasoft-news-distribution' ) );

		return $head . $this->split( $main, $side ) . $this->forms();
	}

	/**
	 * The three steps.
	 *
	 * @param WebSubChannel $channel The channel.
	 * @return string
	 */
	private function steps_card( WebSubChannel $channel ): string {
		$check   = FeedLinkCheck::stored();
		$hubs    = $channel->hubs();
		$passed  = array() !== $check['languages'];
		$checked = array();

		foreach ( $channel->carried_languages() as $language ) {
			$found     = $check['languages'][ $language ] ?? null;
			$checked[] = is_array( $found ) && '' === $this->problem_text( $found, $hubs );

			if ( ! is_array( $found ) ) {
				$passed = false;
			}
		}

		$done = array(
			$passed && ! in_array( false, $checked, true ),
			array() !== $hubs,
			$channel->is_enabled(),
		);
		$now  = array_search( false, $done, true );

		$steps = array(
			array(
				'id'    => 'feeds',
				'title' => __( 'Your feeds name the hub', 'estasoft-news-distribution' ),
				'text'  => __( 'Every open-reader feed carries a hub link and a self link, inside the feed and in the HTTP headers. Each language feed uses its own exact address as its self link.', 'estasoft-news-distribution' ),
				'body'  => $this->feeds_body( $channel, $check, $hubs ),
			),
			array(
				'id'    => 'hub',
				'title' => __( 'Choose your hub', 'estasoft-news-distribution' ),
				'text'  => __( 'News Distribution sends each ping to this address. A hub is a public service that tells subscribers a feed changed. You can add more than one.', 'estasoft-news-distribution' ),
				'body'  => $this->hub_body(),
			),
			array(
				'id'    => 'sent',
				'title' => __( 'Choose what is sent', 'estasoft-news-distribution' ),
				'text'  => __( 'One ping goes out for the language feed of each article that changed.', 'estasoft-news-distribution' ),
				'body'  => $this->toggles( array( 'on_publish', 'on_update', 'on_remove', 'only_enabled_languages' ) ),
			),
		);

		foreach ( $steps as $index => $step ) {
			$steps[ $index ]['state'] = $done[ $index ] ? 'done' : ( $now === $index ? 'now' : 'todo' );
		}

		return $this->steps( 'websub', __( 'Set up WebSub', 'estasoft-news-distribution' ), $steps );
	}

	/**
	 * Step 1: each language feed's address and whether it names the hub.
	 *
	 * @param WebSubChannel                                                    $channel The channel.
	 * @param array{time: int, languages: array<string, array<string, mixed>>} $check   The stored check.
	 * @param string[]                                                         $hubs    The hubs entered.
	 * @return string
	 */
	private function feeds_body( WebSubChannel $channel, array $check, array $hubs ): string {
		$html = '';

		foreach ( $channel->carried_languages() as $language ) {
			$topics = $channel->topics( $language );
			$found  = $check['languages'][ $language ] ?? null;

			if ( array() === $topics ) {
				continue;
			}

			$html .= $this->address_line( $language, (string) $topics[0], __( 'open-reader feed', 'estasoft-news-distribution' ), $this->link_facts( $found, $hubs ) );
		}

		if ( '' === $html ) {
			return Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'No language is turned on for WebSub in the Languages settings.', 'estasoft-news-distribution' ) );
		}

		$when = $check['time'] > 0
			? Html::tag(
				'span',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
				esc_html(
					sprintf(
						/* translators: %s: the time of the last check, such as 14:31. */
						__( 'Checked at %s.', 'estasoft-news-distribution' ),
						Clock::format( $check['time'] )
					)
				)
			)
			: '';

		return Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-12' ), $html )
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-12' ),
				Button::link( __( 'Check again', 'estasoft-news-distribution' ), $this->action_url( 'check' ) ) . $when
			);
	}

	/**
	 * What the last check found for one feed.
	 *
	 * @param mixed    $found The stored result of the language, or null when it was never checked.
	 * @param string[] $hubs  The hubs entered.
	 * @return string Trusted HTML.
	 */
	private function link_facts( $found, array $hubs ): string {
		if ( ! is_array( $found ) ) {
			return StatusView::inline( Status::OFF, __( 'Not checked yet', 'estasoft-news-distribution' ) );
		}

		$problem = $this->problem_text( $found, $hubs );

		if ( '' === $problem ) {
			return StatusView::inline( Status::CLEAN, __( 'Hub and self links found', 'estasoft-news-distribution' ) );
		}

		return StatusView::inline( Status::ATTENTION, $problem );
	}

	/**
	 * What is missing from a checked feed, or an empty string when nothing is.
	 *
	 * @param array<string, mixed> $found The stored result of the language.
	 * @param string[]             $hubs  The hubs entered.
	 * @return string
	 */
	private function problem_text( array $found, array $hubs ): string {
		if ( array() === $hubs ) {
			return __( 'No hub is set, so the feed names none', 'estasoft-news-distribution' );
		}

		if ( (int) ( $found['code'] ?? 0 ) < 1 ) {
			return sprintf(
				/* translators: %s: why the feed could not be fetched. */
				__( 'Could not fetch the feed: %s', 'estasoft-news-distribution' ),
				'' !== (string) ( $found['error'] ?? '' ) ? (string) $found['error'] : __( 'no answer', 'estasoft-news-distribution' )
			);
		}

		if ( 200 !== (int) $found['code'] ) {
			return sprintf(
				/* translators: %d: the HTTP code the feed answered. */
				__( 'The feed answered %d', 'estasoft-news-distribution' ),
				(int) $found['code']
			);
		}

		$missing = array();

		foreach ( array(
			'body_hub'    => __( 'hub link in the feed', 'estasoft-news-distribution' ),
			'header_hub'  => __( 'hub link in the HTTP headers', 'estasoft-news-distribution' ),
			'body_self'   => __( 'self link in the feed', 'estasoft-news-distribution' ),
			'header_self' => __( 'self link in the HTTP headers', 'estasoft-news-distribution' ),
		) as $key => $label ) {
			if ( empty( $found[ $key ] ) ) {
				$missing[] = $label;
			}
		}

		if ( array() === $missing ) {
			return '';
		}

		return sprintf(
			/* translators: %s: the links that are missing, such as "hub link in the feed, self link in the HTTP headers". */
			__( 'Missing: %s', 'estasoft-news-distribution' ),
			implode( ', ', $missing )
		);
	}

	/**
	 * Step 2: the hub addresses.
	 *
	 * @return string
	 */
	private function hub_body(): string {
		$html = $this->hub_field( 'hub', false ) . $this->hub_field( 'hub_2', true ) . $this->hub_field( 'hub_3', true );

		return Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-12' ), $html )
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-cluster estasoft-nd-hub-add' ),
				Button::button( __( 'Add another hub', 'estasoft-news-distribution' ), array( 'attrs' => array( 'data-add-hub' => true ) ) )
			);
	}

	/**
	 * One hub address with its label. The second and third stay out of the way
	 * until the person adds them, when a script is there to show them.
	 *
	 * @param string $name  Field name.
	 * @param bool   $extra Whether this is an extra hub.
	 * @return string
	 */
	private function hub_field( string $name, bool $extra ): string {
		$spec   = $this->channel->fields()[ $name ];
		$values = $this->values();
		$errors = $this->errors();
		$value  = trim( (string) ( $values[ $name ] ?? '' ) );

		if ( ! $extra ) {
			$spec['help'] = __( 'Use the address your hub gives you. The address must start with https://. Nothing is sent until you save and a feed changes, or you send a test ping.', 'estasoft-news-distribution' );
		}

		$shown = ! $extra || '' !== $value || isset( $errors[ $name ] );

		return Html::tag(
			'div',
			array(
				'class' => Html::classes( 'estasoft-nd-stack', 'estasoft-nd-gap-8', $extra ? 'estasoft-nd-hub-extra' : '', $shown ? 'is-shown' : '' ),
			),
			Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-strong',
					'for'   => Field::id( $this->scope(), $name ),
				),
				esc_html( (string) $spec['label'] )
			)
			. Field::render( $this->scope(), $name, $spec, $value, (string) ( $errors[ $name ] ?? '' ) )
		);
	}

	/**
	 * The pings of the last 24 hours, newest first.
	 *
	 * @param WebSubChannel $channel The channel.
	 * @return array<int, array<string, mixed>>
	 */
	private function today( WebSubChannel $channel ): array {
		$since = Clock::now() - DAY_IN_SECONDS;
		$rows  = array();

		foreach ( $channel->recent_rows( 100 ) as $row ) {
			if ( (int) $row['created_ts'] >= $since ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * Bars for pings that are being tried again and pings that gave up.
	 *
	 * @param array<int, array<string, mixed>> $rows The pings of the last 24 hours.
	 * @return string
	 */
	private function retry_bars( array $rows ): string {
		$state    = $this->newest( $rows );
		$retrying = $state['retrying'];
		$failed   = $state['failed'];

		$html = '';
		$log  = Button::link( __( 'Open delivery log', 'estasoft-news-distribution' ), $this->log_url() );

		if ( array() !== $retrying ) {
			$row  = $retrying[0];
			$lead = sprintf(
				/* translators: %s: number of pings. */
				_n( '%s ping is retrying.', '%s pings are retrying.', count( $retrying ), 'estasoft-news-distribution' ),
				number_format_i18n( count( $retrying ) )
			);
			$text = sprintf(
				/* translators: 1: what the hub answered, a sentence that ends with a full stop, such as "503 Service Unavailable.", 2: the time of the answer, 3: the number of the attempt, 4: the most attempts, 5: the time of the next try. */
				__( 'Latest answer at %2$s: %1$s This is attempt %3$d of %4$d and the next try is at %5$s. After the last attempt the ping shows Failed.', 'estasoft-news-distribution' ),
				rtrim( $this->answer_words( $row ), '.' ) . '.',
				Clock::format( (int) $row['created_ts'] ),
				(int) $row['attempt'],
				Retry::MAX_ATTEMPTS,
				Clock::format( (int) $row['run_at_ts'] )
			);
			$html .= Message::bar( 'warning', Html::tag( 'strong', array(), esc_html( $lead ) ) . ' ' . esc_html( $text ), $log );
		}

		if ( array() !== $failed ) {
			$lead = sprintf(
				/* translators: %s: number of pings. */
				_n( '%s ping failed after every attempt.', '%s pings failed after every attempt.', count( $failed ), 'estasoft-news-distribution' ),
				number_format_i18n( count( $failed ) )
			);
			$html .= Message::bar( 'danger', Html::tag( 'strong', array(), esc_html( $lead ) ) . ' ' . esc_html__( 'The hub’s answer is in the delivery log. Fix the hub address, or ask the hub’s owner, then save a change to send again.', 'estasoft-news-distribution' ), $log );
		}

		return $html;
	}

	/**
	 * "Recent pings".
	 *
	 * @param array<int, array<string, mixed>> $rows The pings of the last 24 hours.
	 * @return string
	 */
	private function pings_card( array $rows ): string {
		$shown = array_slice( $rows, 0, 10 );
		$total = count( $rows );
		$cells = array();

		foreach ( $shown as $row ) {
			$title = $this->row_title( $row );
			$name  = '' !== $title
				? Html::tag( 'span', array(), esc_html( $title ) ) . ( '' !== (string) $row['language'] ? Tag::lang( (string) $row['language'] ) : '' )
				: Html::tag( 'span', array(), esc_html__( 'Test ping', 'estasoft-news-distribution' ) );

			$cells[] = array(
				esc_html( Clock::format( (int) $row['created_ts'] ) ),
				Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ), $name ),
				esc_html( AbstractPingChannel::event_label( (string) $row['event'] ) ),
				$this->result_cell( $row ),
				esc_html( $this->answer_text( $row ) ),
			);
		}

		return $this->rows_card(
			'pings',
			__( 'Recent pings', 'estasoft-news-distribution' ),
			sprintf(
				/* translators: 1: number of pings shown, 2: number of pings in the last 24 hours. */
				_n( 'Every ping is kept in the delivery log. Showing %1$s of %2$s ping from the last 24 hours.', 'Every ping is kept in the delivery log. Showing %1$s of %2$s pings from the last 24 hours.', $total, 'estasoft-news-distribution' ),
				number_format_i18n( count( $shown ) ),
				number_format_i18n( $total )
			),
			'estasoft-nd-cols-pings',
			array(
				__( 'Time', 'estasoft-news-distribution' ),
				__( 'Article', 'estasoft-news-distribution' ),
				__( 'Event', 'estasoft-news-distribution' ),
				__( 'Result', 'estasoft-news-distribution' ),
				__( 'Hub answer', 'estasoft-news-distribution' ),
			),
			$cells,
			Button::link( __( 'Open delivery log', 'estasoft-news-distribution' ), $this->log_url(), array( 'size' => 'sm' ) ),
			__( 'No pings yet', 'estasoft-news-distribution' ),
			__( 'A ping appears here when an article is published, updated or removed, or when you send a test ping.', 'estasoft-news-distribution' )
		);
	}

	/**
	 * "How a ping works".
	 *
	 * @return string
	 */
	private function how_card(): string {
		$steps = array(
			__( 'An article is published, changed or removed.', 'estasoft-news-distribution' ),
			__( 'The queue pings your hub, never inside the save.', 'estasoft-news-distribution' ),
			__( 'The hub tells subscribers to fetch the feed.', 'estasoft-news-distribution' ),
		);
		$items = '';

		foreach ( $steps as $text ) {
			$items .= Html::tag( 'li', array(), esc_html( $text ) );
		}

		$foot = sprintf(
			/* translators: %s: a link to the alert settings, the words "Settings, Alerts". */
			esc_html__( 'A failed ping is tried again after 1, 5 and 15 minutes. After the fourth attempt it shows Failed, and an alert goes to the address in %s.', 'estasoft-news-distribution' ),
			Html::tag(
				'a',
				array(
					'href' => add_query_arg(
						array(
							'page'    => 'estasoft-nd-settings',
							'section' => 'alerts',
						),
						admin_url( 'admin.php' )
					),
				),
				esc_html__( 'Settings, Alerts', 'estasoft-news-distribution' )
			)
		);

		return Card::note(
			'how',
			__( 'How a ping works', 'estasoft-news-distribution' ),
			Html::tag( 'ol', array( 'class' => 'estasoft-nd-numbered estasoft-nd-stack estasoft-nd-gap-8' ), $items )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), $foot )
		);
	}

	/**
	 * "Who it helps".
	 *
	 * @return string
	 */
	private function helps_card(): string {
		return $this->paragraph_card(
			'helps',
			__( 'Who it helps', 'estasoft-news-distribution' ),
			__( 'Readers and tools that follow your feed through WebSub. Some readers fetch the feed on their own schedule and ignore the hub, and News Distribution cannot see who subscribed.', 'estasoft-news-distribution' )
		);
	}
}
