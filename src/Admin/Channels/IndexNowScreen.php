<?php
/**
 * The IndexNow channel page.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Channels;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Ui\AddressRow;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\Grid;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Channels\IndexNow\Catchup;
use Estasoft\NewsDistribution\Channels\IndexNow\IndexNowChannel;
use Estasoft\NewsDistribution\Channels\Ping\AbstractPingChannel;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Language\Catalog;
use Estasoft\NewsDistribution\Core\Retry;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * `admin.php?page=estasoft-nd-channels&channel=indexnow`. Two tabs.
 *
 * Settings: the key, the key file and what is sent, then the latest submissions.
 *
 * Catch-up: a person-started run that sends the addresses of articles that were
 * never sent, in batches through the queue. Nothing is sent until the button is
 * pressed, and a run can be paused or cancelled; what was sent stays sent.
 */
final class IndexNowScreen extends AbstractStepScreen {

	public static function channel_id(): string {
		return 'indexnow';
	}

	public function tabs(): array {
		return array(
			'settings' => __( 'Settings', 'estasoft-news-distribution' ),
			'catchup'  => __( 'Catch-up', 'estasoft-news-distribution' ),
		);
	}

	/**
	 * Whether the catch-up tab is open. The frame asks for the heading before it
	 * hands over the tab, so the screen reads the address.
	 *
	 * @return bool
	 */
	private function on_catchup(): bool {
		return Query::is( 'tab', 'catchup' );
	}

	public function heading(): string {
		return $this->on_catchup() ? __( 'Catch-up submission', 'estasoft-news-distribution' ) : parent::heading();
	}

	public function shows_status(): bool {
		return ! $this->on_catchup();
	}

	public function description(): string {
		return $this->on_catchup()
			? __( 'Tell search engines about the articles you already have.', 'estasoft-news-distribution' )
			: __( 'News Distribution tells search engines the moment an article is published, updated or removed.', 'estasoft-news-distribution' );
	}

	public function actions( string $tab ): string {
		if ( 'catchup' === $tab ) {
			return '';
		}

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4 estasoft-nd-test-action' ),
			Button::link( __( 'Send a test', 'estasoft-news-distribution' ), $this->action_url( 'test' ) )
			. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Sends your home page address once and shows the answer.', 'estasoft-news-distribution' ) )
		);
	}

	public function enqueue( string $tab ): void {
		parent::enqueue( $tab );

		$catchup = Catchup::of( $this->plugin );

		if ( 'catchup' === $tab && null !== $catchup && 'running' === $catchup->run()['state'] ) {
			$run = $catchup->run();

			Assets::screen_script(
				'indexnow-catchup',
				array(
					'job'      => $run['job'],
					'sent'     => $run['sent'],
					'interval' => 5000,
				)
			);
		}
	}

	protected function known_actions(): array {
		return array(
			'test'           => 'get',
			'check_key'      => 'get',
			'regenerate_key' => 'post',
			'start_catchup'  => 'post',
			'pause_catchup'  => 'post',
			'resume_catchup' => 'post',
			'cancel_catchup' => 'post',
		);
	}

	protected function step_input(): array {
		check_admin_referer( $this->page_nonce() );

		return array( 'days' => isset( $_POST['days'] ) ? absint( wp_unslash( $_POST['days'] ) ) : 0 );
	}

	protected function perform( string $action, array $input ): array {
		$channel = $this->channel instanceof IndexNowChannel ? $this->channel : null;
		$catchup = Catchup::of( $this->plugin );

		if ( null === $channel || null === $catchup ) {
			return array();
		}

		switch ( $action ) {
			case 'test':
				return $this->finish_test( $channel->test() );

			case 'check_key':
				$channel->check_key_file();

				return array();

			case 'regenerate_key':
				$key = $channel->regenerate_key();

				return is_wp_error( $key )
					? $this->remember( false, $key->get_error_message() )
					: $this->remember( true, __( 'A new key was created. The old key file no longer answers. Check the new key file below.', 'estasoft-news-distribution' ) );

			case 'start_catchup':
				$started = $catchup->start( absint( $input['days'] ?? 0 ) );

				return is_wp_error( $started )
					? $this->remember( false, $started->get_error_message() )
					: $this->remember( true, __( 'The catch-up started. The first batch is sent from the background queue.', 'estasoft-news-distribution' ) );

			case 'pause_catchup':
				$changed = $catchup->pause();

				return is_wp_error( $changed )
					? $this->remember( false, $changed->get_error_message() )
					: $this->remember( true, __( 'Paused. Nothing is sent until you resume.', 'estasoft-news-distribution' ) );

			case 'resume_catchup':
				$changed = $catchup->resume();

				return is_wp_error( $changed )
					? $this->remember( false, $changed->get_error_message() )
					: $this->remember( true, __( 'Resumed. The next batch is sent from the background queue.', 'estasoft-news-distribution' ) );

			case 'cancel_catchup':
				$changed = $catchup->cancel();

				return is_wp_error( $changed )
					? $this->remember( false, $changed->get_error_message() )
					: $this->remember( true, __( 'The catch-up was cancelled. The addresses already sent stay sent.', 'estasoft-news-distribution' ) );
		}

		return array();
	}

	public function render( string $tab ): string {
		$channel = $this->channel instanceof IndexNowChannel ? $this->channel : null;

		if ( null === $channel ) {
			return '';
		}

		return 'catchup' === $tab ? $this->render_catchup( $channel ) : $this->render_settings( $channel );
	}

	/**
	 * The questions a link asks when no script is there to open the dialog.
	 *
	 * @return string
	 */
	private function questions(): string {
		return $this->question(
			array(
				'regenerate-key' => array(
					'title'   => __( 'Regenerate the key?', 'estasoft-news-distribution' ),
					'text'    => __( 'The old key file is removed at once and cannot come back. Search engines check the new file the next time you send a notice.', 'estasoft-news-distribution' ),
					'confirm' => __( 'Regenerate key', 'estasoft-news-distribution' ),
					'action'  => 'regenerate_key',
				),
				'cancel-catchup' => array(
					'title'   => __( 'Cancel this catch-up?', 'estasoft-news-distribution' ),
					'text'    => __( 'The addresses already sent stay sent, and a new run skips them.', 'estasoft-news-distribution' ),
					'confirm' => __( 'Stop the catch-up', 'estasoft-news-distribution' ),
					'action'  => 'cancel_catchup',
					'tab'     => 'catchup',
				),
			)
		);
	}

	/* ---------- Settings tab ---------- */

	/**
	 * The settings tab.
	 *
	 * @param IndexNowChannel $channel The channel.
	 * @return string
	 */
	private function render_settings( IndexNowChannel $channel ): string {
		$rows  = $this->today( $channel );
		$head  = $this->notice() . $this->questions()
			. $this->off_bar( __( 'Nothing is sent until you turn it on.', 'estasoft-news-distribution' ) )
			. $this->retry_bars( $rows );
		$main  = $this->form( $this->steps_card( $channel ) ) . $this->submissions_card( $rows );
		$names = array(
			__( 'Amazon', 'estasoft-news-distribution' ),
			__( 'Bing', 'estasoft-news-distribution' ),
			__( 'Naver', 'estasoft-news-distribution' ),
			__( 'Seznam.cz', 'estasoft-news-distribution' ),
			__( 'Yandex', 'estasoft-news-distribution' ),
			__( 'Yep', 'estasoft-news-distribution' ),
		);
		$list  = '';

		foreach ( $names as $name ) {
			$list .= Html::tag( 'span', array( 'class' => 'estasoft-nd-strong' ), esc_html( $name ) );
		}

		$side = Card::note(
			'receives',
			__( 'Who receives it', 'estasoft-news-distribution' ),
			Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'News Distribution sends one request to IndexNow. Participating engines share it with each other.', 'estasoft-news-distribution' ) )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-names' ), $list )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Google is not on IndexNow’s list of engines, so keep your Google News sitemap.', 'estasoft-news-distribution' ) )
		) . Card::note(
			'know',
			__( 'Good to know', 'estasoft-news-distribution' ),
			$this->icon_list(
				'info',
				array(
					__( 'If the key file is missing or does not match, the engines refuse the notice. The answer, such as 403 or 422, appears in the delivery log.', 'estasoft-news-distribution' ),
					__( 'Only addresses on your own site are accepted.', 'estasoft-news-distribution' ),
					__( 'A notice is a hint to crawl. It does not promise indexing, and News Distribution cannot see what an engine does with it.', 'estasoft-news-distribution' ),
				),
				false
			)
		);

		return $head . $this->split( $main, $side ) . $this->forms();
	}

	/**
	 * The three steps.
	 *
	 * @param IndexNowChannel $channel The channel.
	 * @return string
	 */
	private function steps_card( IndexNowChannel $channel ): string {
		$key   = $channel->key();
		$check = $channel->last_key_check();
		$done  = array(
			'' !== $key,
			null !== $check && $check['ok'],
			'' !== $key && $channel->is_enabled(),
		);
		$now   = array_search( false, $done, true );

		$steps = array(
			array(
				'id'    => 'key',
				'title' => __( 'Create a key', 'estasoft-news-distribution' ),
				'text'  => __( 'News Distribution made a random key for your site. Search engines use it to check that a notice really comes from you.', 'estasoft-news-distribution' ),
				'body'  => $this->key_body( $key ),
			),
			array(
				'id'    => 'file',
				'title' => __( 'News Distribution serves the key file', 'estasoft-news-distribution' ),
				'text'  => __( 'The key must be a text file at the root of your site that contains nothing but the key.', 'estasoft-news-distribution' ),
				'body'  => $this->file_body( $channel, $check ),
			),
			array(
				'id'    => 'sent',
				'title' => __( 'Choose what is sent', 'estasoft-news-distribution' ),
				'text'  => sprintf(
					/* translators: %s: the most addresses one request can carry, such as 10,000. */
					__( 'Each change sends the article’s address. One request can carry up to %s addresses.', 'estasoft-news-distribution' ),
					number_format_i18n( $channel->max_urls() )
				),
				'body'  => $this->toggles( array( 'on_publish', 'on_update', 'on_remove', 'only_enabled_languages' ) ),
			),
		);

		foreach ( $steps as $index => $step ) {
			$steps[ $index ]['state'] = $done[ $index ] ? 'done' : ( $now === $index ? 'now' : 'todo' );
		}

		return $this->steps( 'indexnow', __( 'Set up IndexNow', 'estasoft-news-distribution' ), $steps );
	}

	/**
	 * Step 1: the key, shortened, and Regenerate.
	 *
	 * @param string $key The key, or an empty string.
	 * @return string
	 */
	private function key_body( string $key ): string {
		if ( '' === $key ) {
			return Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'No key yet. Turn the channel on to create one.', 'estasoft-news-distribution' ) );
		}

		$short = strlen( $key ) > 20 ? substr( $key, 0, 8 ) . '…' . substr( $key, -8 ) : $key;

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-keyline' ),
			Html::tag(
				'code',
				array(
					'class'      => 'estasoft-nd-url estasoft-nd-mono',
					'dir'        => 'ltr',
					'tabindex'   => '0',
					'aria-label' => __( 'Your IndexNow key, shortened', 'estasoft-news-distribution' ),
				),
				esc_html( $short )
			)
			. $this->confirmable(
				'regenerate-key',
				__( 'Regenerate', 'estasoft-news-distribution' ),
				__( 'Regenerate the key?', 'estasoft-news-distribution' ),
				__( 'The old key file is removed at once and cannot come back. Search engines check the new file the next time you send a notice.', 'estasoft-news-distribution' ),
				__( 'Regenerate key', 'estasoft-news-distribution' ),
				'regenerate_key'
			)
		) . Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
			esc_html__( 'Regenerate asks you to confirm first. It cannot be undone: the old key file is removed at once, and the engines check the new file the next time you send a notice.', 'estasoft-news-distribution' )
		);
	}

	/**
	 * Step 2: the key file address and what the last check found.
	 *
	 * @param IndexNowChannel                                             $channel The channel.
	 * @param array{ok: bool, code: int, message: string, time: int}|null $check   The last key file check.
	 * @return string
	 */
	private function file_body( IndexNowChannel $channel, ?array $check ): string {
		$url = $channel->key_url();

		if ( '' === $url ) {
			return Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'The key file is served once there is a key.', 'estasoft-news-distribution' ) );
		}

		if ( null === $check ) {
			$facts = StatusView::inline( Status::OFF, __( 'Not checked yet', 'estasoft-news-distribution' ) );
		} else {
			$facts = StatusView::inline( $check['ok'] ? Status::CLEAN : Status::ATTENTION, $check['message'] )
				. Html::tag(
					'span',
					array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
					esc_html(
						sprintf(
							/* translators: %s: the time of the last check, such as 14:31. */
							__( 'Checked at %s.', 'estasoft-news-distribution' ),
							Clock::format( $check['time'] )
						)
					)
				);
		}

		$plain = '' === (string) get_option( 'permalink_structure' )
			? Message::text( 'warning', __( 'Your site uses plain permalinks, so WordPress cannot serve a file at the root of the site. Choose another permalink structure in Settings, Permalinks, then check again.', 'estasoft-news-distribution' ) )
			: '';

		return $plain . AddressRow::value( __( 'Key file', 'estasoft-news-distribution' ), $url )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ), $facts )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster' ), Button::link( __( 'Check again', 'estasoft-news-distribution' ), $this->action_url( 'check_key' ) ) );
	}

	/**
	 * The submissions of the last 24 hours, newest first.
	 *
	 * @param IndexNowChannel $channel The channel.
	 * @return array<int, array<string, mixed>>
	 */
	private function today( IndexNowChannel $channel ): array {
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
	 * Bars for notices that are being tried again and notices that gave up.
	 *
	 * @param array<int, array<string, mixed>> $rows The submissions of the last 24 hours.
	 * @return string
	 */
	private function retry_bars( array $rows ): string {
		$state = $this->newest( $rows );
		$html  = '';
		$log   = Button::link( __( 'Open delivery log', 'estasoft-news-distribution' ), $this->log_url() );

		if ( array() !== $state['retrying'] ) {
			$row  = $state['retrying'][0];
			$lead = sprintf(
				/* translators: %s: number of notices. */
				_n( '%s notice is retrying.', '%s notices are retrying.', count( $state['retrying'] ), 'estasoft-news-distribution' ),
				number_format_i18n( count( $state['retrying'] ) )
			);
			$text = sprintf(
				/* translators: 1: what IndexNow answered, a sentence that ends with a full stop, such as "429 Too Many Requests.", 2: the number of the attempt, 3: the most attempts, 4: the time of the next try. */
				__( 'Latest answer: %1$s This is attempt %2$d of %3$d and the next try is at %4$s. After the last attempt the notice shows Failed.', 'estasoft-news-distribution' ),
				rtrim( $this->answer_words( $row ), '.' ) . '.',
				(int) $row['attempt'],
				Retry::MAX_ATTEMPTS,
				Clock::format( (int) $row['run_at_ts'] )
			);
			$html .= Message::bar( 'warning', Html::tag( 'strong', array(), esc_html( $lead ) ) . ' ' . esc_html( $text ), $log );
		}

		if ( array() !== $state['failed'] ) {
			$lead = sprintf(
				/* translators: %s: number of notices. */
				_n( '%s notice failed after every attempt.', '%s notices failed after every attempt.', count( $state['failed'] ), 'estasoft-news-distribution' ),
				number_format_i18n( count( $state['failed'] ) )
			);
			$html .= Message::bar( 'danger', Html::tag( 'strong', array(), esc_html( $lead ) ) . ' ' . esc_html__( 'The answer is in the delivery log. A key file that is missing or does not match is the usual cause.', 'estasoft-news-distribution' ), $log );
		}

		return $html;
	}

	/**
	 * "Recent submissions".
	 *
	 * @param array<int, array<string, mixed>> $rows The submissions of the last 24 hours.
	 * @return string
	 */
	private function submissions_card( array $rows ): string {
		$shown = array_slice( $rows, 0, 10 );
		$total = count( $rows );
		$cells = array();

		foreach ( $shown as $row ) {
			$cells[] = $this->submission_cells( $row );
		}

		return $this->rows_card(
			'submissions',
			__( 'Recent submissions', 'estasoft-news-distribution' ),
			sprintf(
				/* translators: 1: number of requests shown, 2: number of requests in the last 24 hours. */
				_n( 'Every request is kept in the delivery log. Showing %1$s of %2$s request from the last 24 hours.', 'Every request is kept in the delivery log. Showing %1$s of %2$s requests from the last 24 hours.', $total, 'estasoft-news-distribution' ),
				number_format_i18n( count( $shown ) ),
				number_format_i18n( $total )
			),
			'estasoft-nd-cols-submissions',
			array(
				__( 'Time', 'estasoft-news-distribution' ),
				__( 'URLs', 'estasoft-news-distribution' ),
				__( 'Request', 'estasoft-news-distribution' ),
				__( 'Answer', 'estasoft-news-distribution' ),
			),
			$cells,
			Button::link( __( 'Open delivery log', 'estasoft-news-distribution' ), $this->log_url(), array( 'size' => 'sm' ) ),
			__( 'No submissions yet', 'estasoft-news-distribution' ),
			__( 'A request appears here when an article is published, updated or removed, when you send a test, or when a catch-up sends a batch.', 'estasoft-news-distribution' )
		);
	}

	/**
	 * The cells of one submission.
	 *
	 * @param array<string, mixed> $row A delivery log row.
	 * @return array<int, string>
	 */
	private function submission_cells( array $row ): array {
		$event = (string) $row['event'];
		$count = 1;

		if ( 'catchup' === $event ) {
			$count          = 1 === preg_match( '/^(\d+)/', (string) $row['message'], $found ) ? (int) $found[1] : 0;
			$row['message'] = (string) preg_replace( '/^\d+[^.]*\.\s*/u', '', (string) $row['message'] );
			$request        = __( 'Catch-up batch', 'estasoft-news-distribution' );
		} elseif ( 'test' === $event ) {
			$request = __( 'Test: your home page address', 'estasoft-news-distribution' );
		} else {
			$title   = $this->row_title( $row );
			$request = sprintf(
				/* translators: 1: what happened to the article, such as "Updated", 2: the article's title. */
				__( '%1$s: %2$s', 'estasoft-news-distribution' ),
				AbstractPingChannel::event_label( $event ),
				'' !== $title ? $title : __( 'an article', 'estasoft-news-distribution' )
			);
		}

		$status = (string) $row['status'];

		if ( Status::SENT === $status || Status::SKIPPED === $status ) {
			$answer = StatusView::inline( $status, '' !== $this->answer_words( $row ) ? $this->answer_words( $row ) : null );
		} else {
			$answer = $this->result_cell( $row ) . Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $this->answer_text( $row ) ) );
			$answer = Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ), $answer );
		}

		return array(
			esc_html( Clock::format( (int) $row['created_ts'] ) ),
			esc_html( $count > 0 ? number_format_i18n( $count ) : '–' ),
			esc_html( $request ),
			$answer,
		);
	}

	/* ---------- Catch-up tab ---------- */

	/**
	 * The catch-up tab.
	 *
	 * @param IndexNowChannel $channel The channel.
	 * @return string
	 */
	private function render_catchup( IndexNowChannel $channel ): string {
		$catchup = Catchup::of( $this->plugin );

		if ( null === $catchup ) {
			return '';
		}

		$run    = $catchup->run();
		$active = $catchup->is_active();
		$head   = $this->notice() . $this->questions() . $this->off_bar( __( 'A catch-up cannot start until you turn it on.', 'estasoft-news-distribution' ) );

		if ( '' === $channel->key() ) {
			$head .= Message::bar(
				'warning',
				Html::tag( 'strong', array(), esc_html__( 'There is no key yet.', 'estasoft-news-distribution' ) ) . ' ' . esc_html__( 'Create the key on the Settings tab before sending a catch-up.', 'estasoft-news-distribution' ),
				Button::link( __( 'Open Settings', 'estasoft-news-distribution' ), $this->url( 'settings' ) )
			);
		}

		$main = '';

		if ( 'idle' !== $run['state'] ) {
			$main .= $this->facts_card( $run, $active ) . $this->run_card( $run, $catchup );
		}

		if ( ! $active ) {
			$main .= $this->start_card( $channel, $catchup );
		}

		if ( ! $active && ! $this->plugin->queue()->is_available() ) {
			$head .= Message::text( 'warning', __( 'The background queue is not available, so a catch-up cannot run.', 'estasoft-news-distribution' ) );
		}

		$side = Card::note(
			'reaches',
			__( 'Who a catch-up reaches', 'estasoft-news-distribution' ),
			Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Amazon, Bing, Naver, Seznam.cz, Yandex and Yep take part in IndexNow, and they share what you send. Google is not on IndexNow’s list of engines, so a catch-up does not reach it.', 'estasoft-news-distribution' ) )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Google decides when to read a sitemap again. You can submit the sitemap again in Search Console.', 'estasoft-news-distribution' ) )
		) . Card::note(
			'how',
			__( 'How it works', 'estasoft-news-distribution' ),
			$this->icon_list(
				'check',
				array(
					__( 'Each address is sent once, even if you run it again.', 'estasoft-news-distribution' ),
					sprintf(
						/* translators: 1: addresses in one batch, such as 1,000, 2: the most addresses IndexNow takes in one request, such as 10,000. */
						__( 'Batches of %1$s. IndexNow accepts up to %2$s in one request.', 'estasoft-news-distribution' ),
						number_format_i18n( $catchup->batch_size() ),
						number_format_i18n( $channel->max_urls() )
					),
					__( 'News Distribution waits between batches.', 'estasoft-news-distribution' ),
					__( 'Pausing or cancelling keeps what was already sent.', 'estasoft-news-distribution' ),
				),
				true
			)
		) . $this->cannot_card( __( 'It cannot make an engine index anything, and it cannot reach an engine that does not use IndexNow. It sends no address twice, so a second run only sends what is new.', 'estasoft-news-distribution' ) );

		return $head . $this->split( $main, $side ) . $this->forms();
	}

	/**
	 * What the catch-up is about to do or did: how far back, which languages and how many addresses.
	 *
	 * @param array<string, mixed> $run    The run.
	 * @param bool                 $active Whether the run is going or paused.
	 * @return string
	 */
	private function facts_card( array $run, bool $active ): string {
		$names = array();

		foreach ( (array) $run['languages'] as $code ) {
			$names[] = Catalog::name( (string) $code );
		}

		$rows = array(
			__( 'How far back', 'estasoft-news-distribution' ) => sprintf(
				/* translators: %d: number of days. */
				_n( 'Last %d day', 'Last %d days', (int) $run['days'], 'estasoft-news-distribution' ),
				(int) $run['days']
			),
			__( 'Languages', 'estasoft-news-distribution' )    => wp_sprintf( '%l', $names ),
			__( 'Addresses', 'estasoft-news-distribution' )    => sprintf(
				/* translators: %s: number of addresses, such as 1,420. */
				_n( '%s found', '%s found', (int) $run['total'], 'estasoft-news-distribution' ),
				number_format_i18n( (int) $run['total'] )
			),
		);
		$html = '';

		foreach ( $rows as $label => $value ) {
			$html .= Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-fact' ),
				Html::tag( 'dt', array(), esc_html( (string) $label ) ) . Html::tag( 'dd', array(), esc_html( (string) $value ) )
			);
		}

		return Card::flush(
			'this-catchup',
			$active ? __( 'This catch-up', 'estasoft-news-distribution' ) : __( 'Last catch-up', 'estasoft-news-distribution' ),
			$active
				? __( 'Sends your recent articles to IndexNow once. Another run can start when this one ends.', 'estasoft-news-distribution' )
				: __( 'What the last run was asked to send.', 'estasoft-news-distribution' ),
			Html::tag( 'dl', array( 'class' => 'estasoft-nd-facts' ), $html )
		);
	}

	/**
	 * How the run is going: a bar, the words, the buttons and the batches.
	 *
	 * @param array<string, mixed> $run     The run.
	 * @param Catchup              $catchup The catch-up.
	 * @return string
	 */
	private function run_card( array $run, Catchup $catchup ): string {
		$state   = (string) $run['state'];
		$total   = (int) $run['total'];
		$sent    = (int) $run['sent'];
		$percent = 'done' === $state ? 100 : ( $total > 0 ? (int) min( 100, floor( $sent / $total * 100 ) ) : 0 );
		$titles  = array(
			'running'   => __( 'Running', 'estasoft-news-distribution' ),
			'paused'    => __( 'Paused', 'estasoft-news-distribution' ),
			'done'      => __( 'Finished', 'estasoft-news-distribution' ),
			'cancelled' => __( 'Cancelled', 'estasoft-news-distribution' ),
			'failed'    => __( 'Stopped', 'estasoft-news-distribution' ),
		);

		if ( 'running' === $state ) {
			$intro = __( 'You can leave this page. It carries on in the background.', 'estasoft-news-distribution' );
		} elseif ( 'paused' === $state ) {
			$intro = __( 'Nothing is sent until you resume.', 'estasoft-news-distribution' );
		} elseif ( 'done' === $state ) {
			$intro = sprintf(
				/* translators: %s: number of addresses, such as 1,420. */
				_n( 'The %s address was sent.', 'All %s addresses were sent.', $sent, 'estasoft-news-distribution' ),
				number_format_i18n( $sent )
			);
		} else {
			$intro = (string) $run['message'];
		}

		$words = sprintf(
			/* translators: 1: addresses sent so far, 2: addresses in the run, 3: percent done. */
			_n( '%1$s of %2$s address sent (%3$d%%)', '%1$s of %2$s addresses sent (%3$d%%)', $total, 'estasoft-news-distribution' ),
			number_format_i18n( $sent ),
			number_format_i18n( $total ),
			$percent
		);
		$body = Html::tag(
			'span',
			array(
				'class'            => Html::classes( 'estasoft-nd-progress', 'done' === $state ? 'estasoft-nd-progress--done' : '' ),
				'role'             => 'progressbar',
				'aria-valuemin'    => '0',
				'aria-valuemax'    => '100',
				'aria-valuenow'    => (string) $percent,
				'aria-label'       => __( 'Catch-up progress', 'estasoft-news-distribution' ),
				'data-catchup-bar' => true,
			),
			Html::tag( 'span', array( 'style' => 'width:' . $percent . '%' ), '' )
		) . Html::tag( 'div', array( 'class' => 'estasoft-nd-run__words' ), Html::tag( 'span', array( 'data-catchup-words' => true ), esc_html( $words ) ) );

		if ( in_array( $state, array( 'running', 'paused' ), true ) ) {
			$first = 'running' === $state
				? $this->post_button( 'pause_catchup', __( 'Pause', 'estasoft-news-distribution' ), array( 'tab' => 'catchup' ) )
				: $this->post_button(
					'resume_catchup',
					__( 'Resume', 'estasoft-news-distribution' ),
					array(
						'tab'     => 'catchup',
						'variant' => 'primary',
					)
				);
			$body .= Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-run__buttons' ),
				$first . $this->confirmable(
					'cancel-catchup',
					__( 'Cancel', 'estasoft-news-distribution' ),
					__( 'Cancel this catch-up?', 'estasoft-news-distribution' ),
					__( 'The addresses already sent stay sent, and a new run skips them.', 'estasoft-news-distribution' ),
					__( 'Stop the catch-up', 'estasoft-news-distribution' ),
					'cancel_catchup',
					'catchup'
				)
			) . Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
				esc_html(
					sprintf(
						/* translators: %s: number of addresses already sent, such as 1,000. */
						_n( 'Pause keeps the run and lets you resume it. Cancel asks you to confirm, then stops the run. Either way the %s address already sent stays sent, and a new run skips it.', 'Pause keeps the run and lets you resume it. Cancel asks you to confirm, then stops the run. Either way the %s addresses already sent stay sent, and a new run skips them.', $sent, 'estasoft-news-distribution' ),
						number_format_i18n( $sent )
					)
				)
			);
		}

		$batches = $this->batch_cells( $run, $catchup );

		return Card::flush(
			'run',
			$titles[ $state ] ?? $titles['failed'],
			$intro,
			Html::tag( 'div', array( 'class' => 'estasoft-nd-run' ), $body )
			. ( array() !== $batches
				? Grid::render(
					'estasoft-nd-cols-batches',
					array(
						__( 'Batch', 'estasoft-news-distribution' ),
						__( 'Addresses', 'estasoft-news-distribution' ),
						__( 'Answer', 'estasoft-news-distribution' ),
					),
					$batches,
					__( 'Batches of this catch-up', 'estasoft-news-distribution' )
				)
				: '' )
		);
	}

	/**
	 * The rows of the batch table: each batch that was tried, then the one that is next.
	 *
	 * @param array<string, mixed> $run     The run.
	 * @param Catchup              $catchup The catch-up.
	 * @return array<int, array<int, string>>
	 */
	private function batch_cells( array $run, Catchup $catchup ): array {
		$cells = array();
		$last  = '';

		foreach ( (array) $run['batches'] as $batch ) {
			$state = (string) $batch['state'];
			$words = $this->answer_words(
				array(
					'response_code' => (int) $batch['code'],
					'message'       => (string) $batch['text'],
				)
			);
			$last  = $state;

			if ( 'sent' === $state || 'skipped' === $state ) {
				$answer = StatusView::inline( $state, '' !== $words ? $words : null );
			} else {
				$answer = Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
					StatusView::inline( $state ) . Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $words ) )
				);
			}

			$cells[] = array(
				esc_html( (string) (int) $batch['n'] ),
				esc_html( number_format_i18n( (int) $batch['count'] ) ),
				$answer,
			);
		}

		if ( 'running' === $run['state'] && 'retry' !== $last && (int) $run['sent'] < (int) $run['total'] ) {
			$seconds = $catchup->seconds_to_next();
			$cells[] = array(
				esc_html( (string) ( (int) $run['rounds'] + 1 ) ),
				esc_html( number_format_i18n( max( 1, min( $catchup->batch_size(), (int) $run['total'] - (int) $run['sent'] ) ) ) ),
				StatusView::inline(
					Status::QUEUED,
					sprintf(
						/* translators: %d: seconds until the next batch starts. */
						_n( 'Next batch starts in about %d second', 'Next batch starts in about %d seconds', max( 1, $seconds ), 'estasoft-news-distribution' ),
						max( 1, $seconds )
					)
				),
			);
		}

		return $cells;
	}

	/**
	 * The form that starts a run.
	 *
	 * @param IndexNowChannel $channel The channel.
	 * @param Catchup         $catchup The catch-up.
	 * @return string
	 */
	private function start_card( IndexNowChannel $channel, Catchup $catchup ): string {
		$options = '';
		$any     = false;
		$names   = array();

		foreach ( $channel->carried_languages() as $code ) {
			$names[] = Catalog::name( $code );
		}

		foreach ( Catchup::RANGES as $days ) {
			$found    = $catchup->count( $days );
			$any      = $any || $found > 0;
			$options .= Html::tag(
				'option',
				array(
					'value'    => (string) $days,
					'selected' => 30 === $days,
				),
				esc_html(
					sprintf(
						/* translators: 1: how far back, such as "Last 30 days", 2: how many addresses that is, such as "1,420 addresses". */
						__( '%1$s: %2$s', 'estasoft-news-distribution' ),
						sprintf(
							/* translators: %d: number of days. */
							_n( 'Last %d day', 'Last %d days', $days, 'estasoft-news-distribution' ),
							$days
						),
						sprintf(
							/* translators: %s: number of addresses, such as 1,420. */
							_n( '%s address', '%s addresses', $found, 'estasoft-news-distribution' ),
							number_format_i18n( $found )
						)
					)
				)
			);
		}

		$can  = $channel->is_enabled() && '' !== $channel->key() && $any && $this->plugin->queue()->is_available();
		$form = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ),
			Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-strong',
					'for'   => 'estasoft-nd-catchup-days',
				),
				esc_html__( 'How far back', 'estasoft-news-distribution' )
			)
			. Html::tag(
				'select',
				array(
					'id'   => 'estasoft-nd-catchup-days',
					'name' => 'days',
				),
				$options
			)
		)
			. Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
				esc_html(
					sprintf(
						/* translators: %s: the languages that are sent, such as "English (US) and Arabic". */
						__( 'Languages: %s. An address that was already sent is left out.', 'estasoft-news-distribution' ),
						wp_sprintf( '%l', $names )
					)
				)
			)
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-cluster' ),
				Button::submit(
					__( 'Start catch-up', 'estasoft-news-distribution' ),
					array(
						'variant' => 'primary',
						'attrs'   => $can ? array() : array( 'disabled' => true ),
					)
				)
			);
		$body = Html::tag( 'div', array( 'class' => 'estasoft-nd-start' ), $this->post_form( 'start_catchup', 'catchup', $form ) );

		return Card::flush(
			'start',
			__( 'Send a catch-up', 'estasoft-news-distribution' ),
			__( 'Sends your recent articles to IndexNow once, in batches. Nothing is sent until you press Start.', 'estasoft-news-distribution' ),
			$body
		);
	}
}
