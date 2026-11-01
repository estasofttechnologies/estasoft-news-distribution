<?php
/**
 * SmartNews helper: explains the feed check in plain words and drafts the email to the platform.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Ai\Channels;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\Code;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\PageHeader;
use Estasoft\NewsDistribution\Ai\Features\PlatformHelp;
use Estasoft\NewsDistribution\Ai\Log;
use Estasoft\NewsDistribution\Ai\View\Parts;
use Estasoft\NewsDistribution\Ai\View\Runner;
use Estasoft\NewsDistribution\Channels\SmartNews\ReadinessReport;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Operations\Flash;
use Estasoft\NewsDistribution\Core\Operations\Links;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Core\Trust;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * `admin.php?page=estasoft-nd-channels&channel=smartnews&tab=helper`, added to the SmartNews page by `AiModule`
 * through the `estasoft_nd_internal_channel_tabs` filter. It is present only while the assistant is ready.
 *
 * Nothing here changes a feed or an article. "Explain in plain words" sends the check messages and the titles of the
 * articles they point at. "Draft the email" sends only the facts the person ticked. A person reads the email and sends
 * it from their own mail program; the plugin never sends it.
 */
final class PlatformHelpTab {

	/**
	 * The screen's own name: it keys the form nonce.
	 */
	private const KEY = 'platform-help';

	/**
	 * How long a result stays on the screen, in seconds.
	 */
	private const FRESH = DAY_IN_SECONDS;

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

	public function slug(): string {
		return 'helper';
	}

	public function label(): string {
		return __( 'AI helper', 'estasoft-news-distribution' );
	}

	/**
	 * This tab's own address.
	 *
	 * @return string
	 */
	private function url(): string {
		return add_query_arg( 'tab', $this->slug(), Links::channel( PlatformHelp::PLATFORM ) );
	}

	public function enqueue(): void {
		Assets::screen_style( 'ai' );
		Assets::screen_script( 'ai' );
	}

	public function handle(): void {
		if ( ! Runner::posted( self::KEY ) ) {
			return;
		}

		$this->plugin->access()->require_or_die( Access::MANAGE );
		$this->plugin->make( Runner::class )->handle( self::KEY, $this->url() );
	}

	public function render(): string {
		$feature = $this->plugin->make( PlatformHelp::class );
		$report  = $feature->report();

		return Parts::crumbs( __( 'Channels', 'estasoft-news-distribution' ), Links::channel( PlatformHelp::PLATFORM ), $this->channel_name() )
			. PageHeader::render(
				$this->channel_name(),
				__( 'Explain the feed check and draft the email to SmartNews.', 'estasoft-news-distribution' )
			)
			. Flash::take()
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-ai-grid' ),
				Html::tag( 'div', array( 'class' => 'estasoft-nd-ai-grid__main' ), $this->check_card( $report ) . $this->email_card( $report ) )
				. Html::tag( 'div', array( 'class' => 'estasoft-nd-ai-grid__side' ), $this->sent_card() . $this->cannot_card() )
			);
	}

	/**
	 * The channel's name as the channel page prints it.
	 *
	 * @return string
	 */
	private function channel_name(): string {
		$channel = $this->plugin->registry()->get( PlatformHelp::PLATFORM );

		return null === $channel ? 'SmartNews' : $channel->label();
	}

	/**
	 * The newest answer of one mode that this person got lately.
	 *
	 * @param string   $mode      `explain` or `email`.
	 * @param string[] $decisions The decisions that still show it.
	 * @return array<string, mixed>|null A log row.
	 */
	private function latest( string $mode, array $decisions ): ?array {
		$found = $this->plugin->make( Log::class )->query(
			array(
				'feature' => 'platform-help',
				'from'    => Clock::now() - self::FRESH,
				'limit'   => 20,
			)
		);

		foreach ( $found['items'] as $row ) {
			if ( ( $row['data']['mode'] ?? '' ) === $mode && in_array( $row['decision'], $decisions, true ) && (int) ( $row['data']['requested_by'] ?? 0 ) === get_current_user_id() ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * "Feed check results": what the plugin's own check found, and the button that explains it.
	 *
	 * @param array<string, mixed> $report What `PlatformHelp::report()` returned.
	 * @return string Escaped HTML.
	 */
	private function check_card( array $report ): string {
		$lines = (array) $report['lines'];
		$feeds = count( (array) $report['feeds'] );

		if ( array() === $lines ) {
			return Card::flush(
				'ai-check',
				__( 'Feed check results', 'estasoft-news-distribution' ),
				sprintf(
					/* translators: %s: a phrase such as your feed or your 3 feeds. */
					__( 'News Distribution’s own SmartNews check found no problems in %s. There is nothing to explain.', 'estasoft-news-distribution' ),
					$this->feeds_phrase( $feeds )
				),
				''
			);
		}

		$counts = array();

		if ( $report['errors'] > 0 ) {
			$counts[] = sprintf(
				/* translators: %d: number of errors. */
				_n( '%d error', '%d errors', (int) $report['errors'], 'estasoft-news-distribution' ),
				(int) $report['errors']
			);
		}

		if ( $report['warnings'] > 0 ) {
			$counts[] = sprintf(
				/* translators: %d: number of warnings. */
				_n( '%d warning', '%d warnings', (int) $report['warnings'], 'estasoft-news-distribution' ),
				(int) $report['warnings']
			);
		}

		$text = array();

		foreach ( $lines as $line ) {
			$text[] = sprintf( '%1$s: %2$s: %3$s', $line['label'], $line['severity'], $line['message'] );
		}

		$has_article = false;

		foreach ( $lines as $line ) {
			$has_article = $has_article || (int) $line['post_id'] > 0;
		}

		$action = $has_article
			? Parts::form(
				$this->url(),
				self::KEY,
				'suggest',
				array(
					'estasoft_nd_ai_feature' => 'platform-help',
					'mode'                   => 'explain',
				),
				Button::submit( __( 'Explain in plain words', 'estasoft-news-distribution' ) )
				. Parts::wait( __( 'Explaining the messages. This takes a few seconds.', 'estasoft-news-distribution' ), true )
			)
			: Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'None of these messages points at one of your articles, so there is nothing to explain.', 'estasoft-news-distribution' ) );

		return Card::flush(
			'ai-check',
			__( 'Feed check results', 'estasoft-news-distribution' ),
			sprintf(
				/* translators: 1: a phrase such as your feed or your 3 feeds, 2: counts such as 2 errors and 1 warning. */
				__( 'What News Distribution’s own SmartNews check found in %1$s: %2$s.', 'estasoft-news-distribution' ),
				$this->feeds_phrase( $feeds ),
				implode( ' ' . __( 'and', 'estasoft-news-distribution' ) . ' ', $counts )
			),
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-ai-rows' ),
				Code::block( implode( "\n", $text ), __( 'Feed check results', 'estasoft-news-distribution' ) )
				. $action
				. $this->explanation( $lines )
			)
		);
	}

	/**
	 * "your feed" or "your 3 feeds".
	 *
	 * @param int $count Number of feeds.
	 * @return string Plain text.
	 */
	private function feeds_phrase( int $count ): string {
		if ( $count < 2 ) {
			return __( 'your feed', 'estasoft-news-distribution' );
		}

		return sprintf(
			/* translators: %d: number of feeds. */
			__( 'your %d feeds', 'estasoft-news-distribution' ),
			$count
		);
	}

	/**
	 * The explanations the AI gave, for the messages the check still shows.
	 *
	 * @param array<int, array<string, mixed>> $current The check's lines now.
	 * @return string Escaped HTML, empty when there is no recent explanation.
	 */
	private function explanation( array $current ): string {
		$row = $this->latest( 'explain', array( Log::ANSWERED ) );

		if ( null === $row ) {
			return '';
		}

		$items = '';
		$shown = 0;

		foreach ( (array) ( $row['data']['lines'] ?? array() ) as $line ) {
			if ( ! is_array( $line ) || ! $this->still_there( $line, $current ) ) {
				continue;
			}

			++$shown;

			$edit   = (int) $line['post_id'] > 0 && current_user_can( 'edit_post', (int) $line['post_id'] ) ? (string) get_edit_post_link( (int) $line['post_id'], 'raw' ) : '';
			$head   = mb_strtoupper( mb_substr( (string) $line['label'], 0, 1 ) ) . mb_substr( (string) $line['label'], 1 );
			$items .= Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-ai-item' ),
				Html::tag(
					'strong',
					array( 'dir' => 'auto' ),
					esc_html(
						sprintf(
							/* translators: 1: for example "Item 3", 2: an article title. */
							__( '%1$s, “%2$s”', 'estasoft-news-distribution' ),
							$head,
							(string) $line['title']
						)
					)
				)
				. Html::tag( 'p', array(), esc_html( (string) $line['explanation'] ) )
				. ( '' === $edit ? '' : Html::tag(
					'a',
					array(
						'class' => 'estasoft-nd-strong',
						'href'  => $edit,
					),
					esc_html__( 'Open the article', 'estasoft-news-distribution' )
				) )
			);
		}

		if ( 0 === $shown ) {
			return '';
		}

		$total = max( $shown, (int) ( $row['data']['total'] ?? $shown ) );
		$line  = $shown === $total
			? array(
				'level' => 'pass',
				'text'  => sprintf(
					/* translators: 1: messages explained, 2: messages in all. */
					__( '%1$d of %2$d messages explained, each matched to a real article', 'estasoft-news-distribution' ),
					$shown,
					$total
				),
			)
			: array(
				'level' => 'warn',
				'text'  => sprintf(
					/* translators: 1: messages explained, 2: messages in all. */
					__( '%1$d of %2$d messages explained. The rest did not pass the checks, so no explanation is shown for them', 'estasoft-news-distribution' ),
					$shown,
					$total
				),
			);

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-suggest' ),
			Html::tag( 'div', array(), Parts::badge() )
			. $items
			. Parts::ticks( array( $line ) )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( Parts::made_by( $this->made_by( $row ) ) ) )
		);
	}

	/**
	 * Whether an explained message is still one the check reports.
	 *
	 * @param array<string, mixed>             $line    A line of the stored explanation.
	 * @param array<int, array<string, mixed>> $current The check's lines now.
	 * @return bool
	 */
	private function still_there( array $line, array $current ): bool {
		$k       = (int) ( $line['k'] ?? 0 );
		$message = (string) ( $line['message'] ?? '' );
		$post_id = (int) ( $line['post_id'] ?? 0 );

		foreach ( $current as $now ) {
			if ( isset( $line['explanation'] ) && (int) $now['k'] === $k && (string) $now['message'] === $message && (int) $now['post_id'] === $post_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * What `Parts::made_by()` reads, from a log row.
	 *
	 * @param array<string, mixed> $row A log row.
	 * @return array<string, mixed>
	 */
	private function made_by( array $row ): array {
		return array(
			'provider_name' => Parts::connection_name( $this->plugin, (string) $row['connection'] ),
			'requests'      => 1,
		);
	}

	/**
	 * "Email to SmartNews": the facts it may use, and the draft.
	 *
	 * @param array<string, mixed> $report What `PlatformHelp::report()` returned.
	 * @return string Escaped HTML.
	 */
	private function email_card( array $report ): string {
		$application = $this->plugin->applications()->get( PlatformHelp::PLATFORM );
		$text        = __( 'The AI words the email from facts you choose. A person reads it and sends it.', 'estasoft-news-distribution' );

		if ( Status::NOT_REQUESTED !== $application['status'] ) {
			$applied = '' === $application['applied_on'] ? false : strtotime( $application['applied_on'] . ' 12:00:00 UTC' );
			$text   .= ' ' . ( false === $applied
				? sprintf(
					/* translators: %s: an application status such as In review. */
					__( 'Your SmartNews application is %s.', 'estasoft-news-distribution' ),
					Status::word( $application['status'] )
				)
				: sprintf(
					/* translators: 1: an application status such as In review, 2: a date such as 5 Oct. */
					__( 'Your SmartNews application is %1$s, applied %2$s.', 'estasoft-news-distribution' ),
					Status::word( $application['status'] ),
					gmdate( 'j M', $applied )
				)
			);
		}

		$boxes = '';

		foreach ( $this->facts( $report ) as $key => $fact ) {
			$id     = 'estasoft-nd-ai-fact-' . $key;
			$boxes .= Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-ai-fact' ),
				Html::void(
					'input',
					array(
						'type'     => 'checkbox',
						'id'       => $id,
						'name'     => 'facts[]',
						'value'    => $key,
						'checked'  => $fact['on'],
						'disabled' => $fact['disabled'],
					)
				)
				. Html::tag(
					'label',
					array( 'for' => $id ),
					Html::tag( 'strong', array(), esc_html( $fact['label'] ) )
					. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html( $fact['text'] ) )
				)
			);
		}

		$form = Parts::form(
			$this->url(),
			self::KEY,
			'suggest',
			array(
				'estasoft_nd_ai_feature' => 'platform-help',
				'mode'                   => 'email',
			),
			Html::tag(
				'fieldset',
				array( 'class' => 'estasoft-nd-ai-facts' ),
				Html::tag( 'legend', array( 'class' => 'estasoft-nd-label' ), esc_html__( 'Facts it may use', 'estasoft-news-distribution' ) ) . $boxes
			)
			. Button::submit( __( 'Draft the email', 'estasoft-news-distribution' ) )
			. Parts::wait( __( 'Drafting the email. This takes a few seconds.', 'estasoft-news-distribution' ), true )
		);

		return Card::flush(
			'ai-email',
			sprintf(
				/* translators: %s: a platform name such as SmartNews. */
				__( 'Email to %s', 'estasoft-news-distribution' ),
				$this->channel_name()
			),
			$text,
			Html::tag( 'div', array( 'class' => 'estasoft-nd-ai-rows' ), $form . $this->draft() )
		);
	}

	/**
	 * The facts the email may use, with what each one holds today.
	 *
	 * @param array<string, mixed> $report What `PlatformHelp::report()` returned.
	 * @return array<string, array{label: string, text: string, on: bool, disabled: bool}>
	 */
	private function facts( array $report ): array {
		$names = array();

		foreach ( (array) $report['feeds'] as $feed ) {
			$names[] = (string) $feed['label'];
		}

		$items    = count( (array) $report['lines'] );
		$standard = null !== $this->plugin->make( Trust::class )->chosen( 'ethics_page' );
		$check    = 0 === $items
			? __( 'No problems found. The draft does not say that SmartNews accepts the feeds.', 'estasoft-news-distribution' )
			: sprintf(
				/* translators: %d: number of items to fix. */
				_n( '%d item to fix. The draft says what is wrong and that you are fixing it. It never says the feeds pass while the check shows problems.', '%d items to fix. The draft says what is wrong and that you are fixing it. It never says the feeds pass while the check shows problems.', $items, 'estasoft-news-distribution' ),
				$items
			);

		return array(
			'publication' => array(
				'label'    => __( 'Publication name and languages', 'estasoft-news-distribution' ),
				'text'     => sprintf(
					/* translators: 1: the publication's name, 2: a list of language names. */
					__( '%1$s: %2$s.', 'estasoft-news-distribution' ),
					$this->plugin->settings()->publication_name(),
					wp_sprintf( '%l', $names )
				),
				'on'       => PlatformHelp::FACTS['publication'],
				'disabled' => false,
			),
			'feeds'       => array(
				'label'    => __( 'Feed addresses', 'estasoft-news-distribution' ),
				'text'     => sprintf(
					/* translators: %d: number of feeds. */
					_n( 'The %d SmartFormat feed.', 'The %d SmartFormat feeds.', max( 1, count( $names ) ), 'estasoft-news-distribution' ),
					max( 1, count( $names ) )
				),
				'on'       => PlatformHelp::FACTS['feeds'],
				'disabled' => false,
			),
			'check'       => array(
				'label'    => __( 'Feed check result', 'estasoft-news-distribution' ),
				'text'     => $check,
				'on'       => PlatformHelp::FACTS['check'],
				'disabled' => false,
			),
			'standards'   => array(
				'label'    => __( 'A line about your editorial standards', 'estasoft-news-distribution' ),
				'text'     => $standard
					? __( 'From your Publisher trust settings.', 'estasoft-news-distribution' )
					: __( 'Choose your editorial standards page in Publisher trust settings first.', 'estasoft-news-distribution' ),
				'on'       => PlatformHelp::FACTS['standards'] && $standard,
				'disabled' => ! $standard,
			),
		);
	}

	/**
	 * The email draft made lately: the text, the checks it passed and the buttons.
	 *
	 * @return string Escaped HTML, empty when there is none to show.
	 */
	private function draft(): string {
		$row = $this->latest( 'email', array( Log::PENDING, Log::COPIED ) );

		if ( null === $row || '' === (string) ( $row['data']['body'] ?? '' ) ) {
			return '';
		}

		$data    = (array) $row['data'];
		$body    = (string) $data['body'];
		$subject = (string) ( $data['subject'] ?? '' );
		$copied  = Log::COPIED === $row['decision'];
		$id      = 'estasoft-nd-ai-email-' . (int) $row['id'];
		$mail    = 'mailto:?subject=' . rawurlencode( $subject ) . '&body=' . rawurlencode( $body );
		$open    = Html::tag(
			'a',
			array(
				'class'        => 'estasoft-nd-btn estasoft-nd-btn--sm',
				'href'         => $mail,
				'data-ai-mail' => '#' . $id,
				'data-subject' => $subject,
			),
			esc_html__( 'Open in mail', 'estasoft-news-distribution' )
		);

		$buttons = $copied
			? Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Copied. A person sends it.', 'estasoft-news-distribution' ) ) . $open
			: Parts::decide(
				__( 'Copy email', 'estasoft-news-distribution' ),
				'copy',
				array( 'variant' => 'primary' )
			) . $open . Parts::decide( __( 'Dismiss', 'estasoft-news-distribution' ), 'dismiss' );

		$form = Parts::form(
			$this->url(),
			self::KEY,
			'decide',
			array( 'log' => (int) $row['id'] ),
			Html::tag( 'div', array(), Parts::badge() )
			. Html::tag(
				'textarea',
				array(
					'id'                  => $id,
					'name'                => 'text',
					'class'               => 'estasoft-nd-field estasoft-nd-ai-email',
					'rows'                => '14',
					'lang'                => 'en',
					'aria-label'          => __( 'Email draft', 'estasoft-news-distribution' ),
					'data-ai-copy-source' => '1',
				),
				esc_html( $body )
			)
			. Parts::ticks( $this->check_lines( (array) ( $data['checks'] ?? array() ) ) )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ), $buttons )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( Parts::made_by( $this->made_by( $row ) ) ) )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Open in mail fills in the subject and the message and leaves the To field empty. Use the address SmartNews gave you when you applied.', 'estasoft-news-distribution' ) ),
			'estasoft-nd-suggest'
		);

		return $form;
	}

	/**
	 * The stored checks as tick lines.
	 *
	 * @param array<int, mixed> $checks The checks a draft passed.
	 * @return array<int, array{ok: bool, label: string, text: string}>
	 */
	private function check_lines( array $checks ): array {
		$out = array();

		foreach ( $checks as $check ) {
			if ( is_array( $check ) ) {
				$out[] = array(
					'ok'    => ! empty( $check['ok'] ),
					'label' => (string) ( $check['label'] ?? '' ),
					'text'  => (string) ( $check['text'] ?? '' ),
				);
			}
		}

		return $out;
	}

	/**
	 * "What is sent" beside the cards.
	 *
	 * @return string Escaped HTML.
	 */
	private function sent_card(): string {
		return Card::note(
			'ai-sent',
			__( 'What is sent', 'estasoft-news-distribution' ),
			Html::tag( 'h3', array( 'class' => 'estasoft-nd-label' ), esc_html__( 'Explain in plain words', 'estasoft-news-distribution' ) )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'The check messages and the titles of the articles they point at.', 'estasoft-news-distribution' ) )
			. Html::tag( 'h3', array( 'class' => 'estasoft-nd-label' ), esc_html( sprintf( /* translators: %s: a platform name such as SmartNews. */ __( 'Email to %s', 'estasoft-news-distribution' ), $this->channel_name() ) ) )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Only the facts you tick in the card on the left.', 'estasoft-news-distribution' ) )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Never article text, and never your keys.', 'estasoft-news-distribution' ) )
		);
	}

	/**
	 * "What it cannot do" beside the cards.
	 *
	 * @return string Escaped HTML.
	 */
	private function cannot_card(): string {
		return Card::cannot(
			array(
				__( 'Send an application or any email. A person sends it.', 'estasoft-news-distribution' ),
				__( 'Change your feeds or your articles.', 'estasoft-news-distribution' ),
				__( 'Run SmartNews’s own validator. The check here is News Distribution’s own.', 'estasoft-news-distribution' ),
				__( 'Know what SmartNews will answer. SmartNews decides.', 'estasoft-news-distribution' ),
			)
		);
	}
}
