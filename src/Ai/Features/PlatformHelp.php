<?php
/**
 * Platform helpers: explain a feed check in plain words, and draft the email to a platform.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Ai\Features;

use Estasoft\NewsDistribution\Ai\Log;
use Estasoft\NewsDistribution\Channels\AppleNews\FeedDocument;
use Estasoft\NewsDistribution\Channels\SmartNews\ReadinessReport;
use Estasoft\NewsDistribution\Channels\SmartNews\SmartNewsChannel;
use Estasoft\NewsDistribution\Core\Trust;

defined( 'ABSPATH' ) || exit;

/**
 * `POST /ai/suggest/platform-help` with `mode` set to `explain` or `email`. Only SmartNews has a helper.
 *
 * - `explain`: News Distribution reads its own check of the SmartNews feeds, writes each problem as a line, and
 *   matches each line to the article it is about. The AI is sent those lines and the titles of those articles and
 *   writes one or two plain sentences for each. A line that cannot be matched to an article is not sent.
 * - `email`: the AI words an email to the platform from the facts the person ticked. Nothing is sent by this code:
 *   the person copies the draft or opens it in their mail program and sends it, with the To field empty.
 *
 * Neither changes anything. What the AI wrote is checked before it is shown: an explanation may not say the feed
 * passes or is approved, and may not use a figure that is not in its line. An email may only contain the feed
 * addresses the person chose, no figures or claims about audience, traffic or awards, and may not say the feeds
 * pass while the check shows problems.
 */
final class PlatformHelp extends AbstractFeature {

	public const PLATFORM = 'smartnews';

	/**
	 * Facts an email may use, key => on by default.
	 */
	public const FACTS = array(
		'publication' => true,
		'feeds'       => true,
		'check'       => true,
		'standards'   => false,
	);

	/**
	 * Most problem lines explained in one request.
	 */
	public const MAX_LINES = 8;

	public function name(): string {
		return 'platform-help';
	}

	public function label(): string {
		return __( 'Platform helpers', 'estasoft-news-distribution' );
	}

	public function needs_article(): bool {
		return false;
	}

	/**
	 * What News Distribution's own check found in the feeds, as lines.
	 *
	 * @return array{channel: bool, feeds: array<int, array{language: string, label: string, url: string, items: int, valid: bool}>, lines: array<int, array<string, mixed>>, errors: int, warnings: int}
	 */
	public function report(): array {
		$channel = $this->plugin->registry()->get( self::PLATFORM );
		$out     = array(
			'channel'  => $channel instanceof SmartNewsChannel,
			'feeds'    => array(),
			'lines'    => array(),
			'errors'   => 0,
			'warnings' => 0,
		);

		if ( ! $channel instanceof SmartNewsChannel ) {
			return $out;
		}

		$languages = array();

		foreach ( $this->plugin->languages()->languages() as $language ) {
			if ( $channel->accepts_language( (string) $language ) ) {
				$languages[] = (string) $language;
			}
		}

		$edge  = $channel->minimum_edge();
		$many  = count( $languages ) > 1;
		$count = 0;

		foreach ( $languages as $language ) {
			$check = $channel->inspect( FeedDocument::body( $this->plugin, $channel, $language ) );

			$out['feeds'][] = array(
				'language' => $language,
				'label'    => ReadinessReport::language_label( $language ),
				'url'      => $channel->feed_url( $language ),
				'items'    => (int) $check['live'],
				'valid'    => (bool) $check['valid'],
			);

			foreach ( array_values( $check['items'] ) as $index => $item ) {
				foreach ( $this->item_lines( $item, $edge ) as $line ) {
					++$count;
					$post_id = '' === (string) $item['link'] ? 0 : (int) url_to_postid( (string) $item['link'] );

					$out['lines'][] = array(
						'k'        => $count,
						'n'        => $index + 1,
						'label'    => $many ? sprintf( 'item %1$d (%2$s)', $index + 1, strtoupper( $language ) ) : sprintf( 'item %d', $index + 1 ),
						'severity' => $line['severity'],
						'message'  => $line['message'],
						'title'    => mb_substr( (string) $item['title'], 0, 120 ),
						'post_id'  => $post_id,
					);

					if ( 'error' === $line['severity'] ) {
						++$out['errors'];
					} else {
						++$out['warnings'];
					}
				}
			}
		}

		return $out;
	}

	public function suggest( int $post_id, array $args ) {
		$mode = sanitize_key( (string) ( $args['mode'] ?? '' ) );

		if ( 'explain' === $mode ) {
			return $this->explain();
		}

		if ( 'email' === $mode ) {
			return $this->email( isset( $args['facts'] ) && is_array( $args['facts'] ) ? array_map( 'sanitize_key', array_map( 'strval', $args['facts'] ) ) : array() );
		}

		return new \WP_Error( 'estasoft_nd_ai_bad_mode', __( 'Choose Explain in plain words or Draft the email.', 'estasoft-news-distribution' ), array( 'status' => 400 ) );
	}

	public function decide( array $row, array $args, int $user_id ) {
		$decided = $this->already_decided( $row );

		if ( null !== $decided ) {
			return $decided;
		}

		$decision = sanitize_key( (string) ( $args['decision'] ?? '' ) );

		if ( 'copy' !== $decision && 'dismiss' !== $decision ) {
			return new \WP_Error( 'estasoft_nd_ai_bad_decision', __( 'Choose Copy email, Open in mail or Dismiss.', 'estasoft-news-distribution' ), array( 'status' => 400 ) );
		}

		$outcome = 'copy' === $decision ? Log::COPIED : Log::DISMISSED;

		$this->plugin->make( Log::class )->decide( (int) $row['id'], $outcome, $user_id );

		return array(
			'log'      => (int) $row['id'],
			'decision' => $outcome,
			'applied'  => '',
			'text'     => '',
			'message'  => 'copy' === $decision ? __( 'Recorded as copied. A person sends it.', 'estasoft-news-distribution' ) : __( 'Dismissed.', 'estasoft-news-distribution' ),
		);
	}

	/**
	 * "Explain in plain words".
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function explain() {
		$report = $this->report();

		if ( ! $report['channel'] ) {
			return new \WP_Error( 'estasoft_nd_ai_no_channel', __( 'SmartNews is not available on this site.', 'estasoft-news-distribution' ), array( 'status' => 404 ) );
		}

		$matched = array();

		foreach ( $report['lines'] as $line ) {
			if ( $line['post_id'] > 0 && count( $matched ) < self::MAX_LINES ) {
				$matched[] = $line;
			}
		}

		if ( array() === $matched ) {
			return new \WP_Error( 'estasoft_nd_ai_nothing_to_explain', __( 'The check found nothing to explain, or nothing that points at one of your articles.', 'estasoft-news-distribution' ), array( 'status' => 409 ) );
		}

		$facts = '';

		foreach ( $matched as $line ) {
			$facts .= sprintf( "%1\$d. %2\$s: %3\$s: %4\$s. Article title: \"%5\$s\"\n", $line['k'], $line['label'], $line['severity'], $line['message'], $line['title'] );
		}

		$answer = $this->ask(
			array(
				'system'     => 'You explain the result of a news feed check to an editor who is not technical. Use only the lines you are given. Say what is wrong and what to do about it, in one or two plain sentences for each line. Never say that the feed passes, is valid or is approved, and never say what SmartNews will do. Reply with a JSON list of objects: {"n": the line number, "text": "the explanation"}.',
				'prompt'     => "Lines from the check of a SmartNews feed:\n" . $facts . "\nReply with the JSON list.",
				'max_tokens' => 900,
			)
		);

		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		$read  = $this->json( $answer['text'] );
		$read  = is_array( $read ) ? $read : array();
		$by_k  = array();
		$lines = array();

		foreach ( $matched as $line ) {
			$by_k[ $line['k'] ] = $line;
		}

		foreach ( $read as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['n'], $item['text'] ) || ! is_string( $item['text'] ) || ! isset( $by_k[ (int) $item['n'] ] ) ) {
				continue;
			}

			$line = $by_k[ (int) $item['n'] ];
			$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $item['text'] ) ) );
			$only = $line['label'] . ' ' . $line['message'] . ' ' . $line['title'];

			if ( '' === $text || mb_strlen( $text ) > 500 || ! Grounding::covered( $text, $only ) || 1 === preg_match( '/\b(?:pass(?:es|ed)?|valid|approved|accepted|will (?:list|accept|show)|guarantee[sd]?)\b/iu', $text ) ) {
				continue;
			}

			$lines[ (int) $line['k'] ] = array_merge( $line, array( 'explanation' => $text ) );
		}

		ksort( $lines );

		$data = array(
			'finding'   => 'explain',
			'platform'  => self::PLATFORM,
			'mode'      => 'explain',
			'lines'     => array_values( $lines ),
			'explained' => count( $lines ),
			'total'     => count( $matched ),
		);

		$log = $this->record( 0, $answer, $data, array() === $lines ? Log::NONE : Log::ANSWERED, get_current_user_id() );

		return array_merge(
			array(
				'log'     => $log,
				'feature' => $this->name(),
			),
			$data,
			$this->meta( $answer ),
			array( 'message' => array() === $lines ? __( 'No explanation passed the checks, so none is shown. Nothing was changed. Try again.', 'estasoft-news-distribution' ) : '' )
		);
	}

	/**
	 * "Draft the email".
	 *
	 * @param string[] $wanted The facts the person ticked.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function email( array $wanted ) {
		$report = $this->report();

		if ( ! $report['channel'] ) {
			return new \WP_Error( 'estasoft_nd_ai_no_channel', __( 'SmartNews is not available on this site.', 'estasoft-news-distribution' ), array( 'status' => 404 ) );
		}

		$use         = array_intersect( array_keys( self::FACTS ), $wanted );
		$publication = $this->plugin->settings()->publication_name();
		$user        = wp_get_current_user();
		$facts       = array();
		$addresses   = array();

		if ( in_array( 'publication', $use, true ) ) {
			$names   = array_map(
				static function ( array $feed ): string {
					return $feed['label'];
				},
				$report['feeds']
			);
			$facts[] = sprintf( 'The publication is %1$s. It publishes in: %2$s.', $publication, implode( ', ', $names ) );

			$application = $this->plugin->applications()->get( self::PLATFORM );
			$applied     = '' === $application['applied_on'] ? false : strtotime( $application['applied_on'] . ' 12:00:00 UTC' );

			if ( false !== $applied ) {
				$facts[] = sprintf( 'The publisher applied to the SmartNews partner program on %s.', gmdate( 'j F Y', $applied ) );
			}
		}

		if ( in_array( 'feeds', $use, true ) ) {
			$facts[] = 'The SmartFormat feeds are:';

			foreach ( $report['feeds'] as $feed ) {
				$facts[]     = sprintf( '%1$s: %2$s', $feed['label'], $feed['url'] );
				$addresses[] = Grounding::address( $feed['url'] );
			}
		}

		$problems = $this->problem_kinds( $report['lines'] );

		if ( in_array( 'check', $use, true ) ) {
			if ( array() === $problems ) {
				$facts[] = 'The publisher\'s own check of the feeds found no problems. Do not say that SmartNews accepts the feeds.';
			} else {
				$facts[] = sprintf( 'The publisher\'s own check of the feeds found %1$d items to fix: %2$s. The publisher is fixing them and will say when that is done.', count( $report['lines'] ), implode( ', ', $problems ) );
			}
		}

		if ( in_array( 'standards', $use, true ) ) {
			$page = $this->plugin->make( Trust::class )->chosen( 'ethics_page' );

			if ( null !== $page ) {
				$url         = (string) get_permalink( $page );
				$facts[]     = 'The publisher\'s editorial standards are at ' . $url;
				$addresses[] = Grounding::address( $url );
			}
		}

		if ( array() === $facts ) {
			return new \WP_Error( 'estasoft_nd_ai_no_facts', __( 'Tick at least one fact the email may use.', 'estasoft-news-distribution' ), array( 'status' => 400 ) );
		}

		$fact_text = implode( "\n", $facts );
		$answer    = $this->ask(
			array(
				'system'     => 'You write short, polite emails from a news publisher to a platform, in English and in plain text. Use only the facts you are given. Never make claims about audience, traffic, awards or size. Never say that the feeds pass or are accepted. Do not add web addresses other than those given, no email addresses, no subject line and no signature.',
				'prompt'     => "Write the email. Begin with \"Hello,\" and end with a sentence inviting them to say if they need anything else.\nFacts:\n" . $fact_text,
				'max_tokens' => 600,
			)
		);

		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		$text   = trim( str_replace( "\r", '', wp_strip_all_tags( $answer['text'] ) ) );
		$text   = (string) preg_replace( "/\n{3,}/", "\n\n", $text );
		$checks = $this->email_checks( $text, $fact_text, $addresses, in_array( 'check', $use, true ) && array() !== $problems );
		$pass   = true;

		foreach ( $checks as $check ) {
			$pass = $pass && $check['ok'];
		}

		$signature = trim( $user->display_name . ( '' === $publication ? '' : ', ' . $publication ), ', ' );
		$body      = $text . "\n\nThank you,\n" . $signature;
		$subject   = '' === $publication ? 'SmartNews feeds' : sprintf( '%s: SmartNews feeds', $publication );
		$data      = array(
			'finding'  => 'email',
			'platform' => self::PLATFORM,
			'mode'     => 'email',
			'facts'    => array_values( $use ),
			'subject'  => $subject,
			'body'     => $pass ? $body : '',
			'checks'   => $checks,
			'pass'     => $pass,
		);

		$log = $this->record( 0, $answer, $data, $pass ? Log::PENDING : Log::NONE, get_current_user_id() );

		if ( ! $pass ) {
			return new \WP_Error(
				'estasoft_nd_ai_rule_check',
				__( 'The draft broke a rule, so it is not shown. Nothing was changed. Try again.', 'estasoft-news-distribution' ),
				array(
					'status' => 422,
					'checks' => $checks,
					'log'    => $log,
				)
			);
		}

		return array_merge(
			array(
				'log'     => $log,
				'feature' => $this->name(),
			),
			$data,
			$this->meta( $answer ),
			array( 'message' => '' )
		);
	}

	/**
	 * The checks on an email draft.
	 *
	 * @param string   $text      The AI's text.
	 * @param string   $facts     What it was given.
	 * @param string[] $addresses The addresses it may contain.
	 * @param bool     $problems  Whether the check found problems and the draft is to say so.
	 * @return array<int, array{ok: bool, label: string, text: string}>
	 */
	private function email_checks( string $text, string $facts, array $addresses, bool $problems ): array {
		$stray  = array_values( array_diff( Grounding::addresses( $text ), $addresses ) );
		$mails  = 1 === preg_match( '/[\w.+-]+@[\w-]+\.[\w.-]+/u', $text );
		$passes = 1 === preg_match( '/\b(?:pass(?:es|ed)?|valid|approved|accepted|no (?:errors|problems|issues)|without (?:errors|problems)|error-free)\b/iu', $text );
		$fixing = 1 === preg_match( '/\b(?:fix(?:ing|es)?|correct(?:ing|s)?|resolv(?:e|ing)|repair(?:ing)?)\b/iu', $text );
		$lines  = array(
			array(
				'ok'    => array() === $stray && ! $mails,
				'label' => '',
				'text'  => array() === $stray && ! $mails
					? __( 'Every address in the draft is one of your feed addresses', 'estasoft-news-distribution' )
					: __( 'The draft has an address that is not one of the addresses you chose', 'estasoft-news-distribution' ),
			),
			array(
				'ok'    => ! Grounding::claims( $text ) && Grounding::covered( $text, $facts ),
				'label' => '',
				'text'  => ! Grounding::claims( $text ) && Grounding::covered( $text, $facts )
					? __( 'No numbers or claims about your audience, traffic or awards', 'estasoft-news-distribution' )
					: __( 'The draft has a number or a claim that is not in the facts you chose', 'estasoft-news-distribution' ),
			),
		);

		if ( $problems ) {
			$ok      = $fixing && ! $passes;
			$lines[] = array(
				'ok'    => $ok,
				'label' => '',
				'text'  => $ok
					? __( 'The draft says what the check found and that you are fixing it', 'estasoft-news-distribution' )
					: __( 'The draft does not say what the check found and that you are fixing it, or it says the feeds pass', 'estasoft-news-distribution' ),
			);
		} else {
			$lines[] = array(
				'ok'    => ! $passes,
				'label' => '',
				'text'  => ! $passes
					? __( 'The draft does not say the feeds pass or are accepted', 'estasoft-news-distribution' )
					: __( 'The draft says the feeds pass or are accepted, which only SmartNews can say', 'estasoft-news-distribution' ),
			);
		}

		return $lines;
	}

	/**
	 * The kinds of problem the lines hold, in plain words and without repeats.
	 *
	 * @param array<int, array<string, mixed>> $lines Lines from report().
	 * @return string[]
	 */
	private function problem_kinds( array $lines ): array {
		$words = array(
			'thumbnail_missing' => 'a missing thumbnail',
			'thumbnail_small'   => 'a thumbnail smaller than SmartNews recommends',
			'date'              => 'a date without a time zone',
			'author'            => 'a missing author',
			'link'              => 'a link with tracking parameters',
			'text'              => 'missing article text',
			'guid'              => 'a missing identifier',
			'basics'            => 'a missing title or link',
		);
		$kinds = array();

		foreach ( $lines as $line ) {
			foreach ( array(
				'media:thumbnail is missing' => 'thumbnail_missing',
				'on its long edge'           => 'thumbnail_small',
				'pubDate'                    => 'date',
				'dc:creator'                 => 'author',
				'tracking'                   => 'link',
				'content:encoded'            => 'text',
				'guid'                       => 'guid',
				'title or link'              => 'basics',
			) as $needle => $kind ) {
				if ( false !== strpos( (string) $line['message'], $needle ) ) {
					$kinds[ $kind ] = $words[ $kind ];
				}
			}
		}

		return array_values( $kinds );
	}

	/**
	 * The lines one feed item raises, in the form the check's own messages use.
	 *
	 * @param array<string, mixed> $item The item from the feed check.
	 * @param int                  $edge The shortest long edge SmartNews recommends.
	 * @return array<int, array{severity: string, message: string}>
	 */
	private function item_lines( array $item, int $edge ): array {
		$lines = array();

		if ( '' === (string) $item['title'] || '' === (string) $item['link'] ) {
			$lines[] = array(
				'severity' => 'error',
				'message'  => 'title or link is missing',
			);
		}

		if ( '' === (string) $item['thumbnail'] ) {
			$lines[] = array(
				'severity' => 'error',
				'message'  => 'media:thumbnail is missing',
			);
		} elseif ( ! empty( $item['small'] ) ) {
			$lines[] = array(
				'severity' => 'warning',
				'message'  => sprintf( 'media:thumbnail is %1$s px on its long edge (%2$s px or more recommended)', number_format_i18n( max( (int) $item['width'], (int) $item['height'] ) ), number_format_i18n( $edge ) ),
			);
		}

		if ( empty( $item['date_ok'] ) ) {
			$lines[] = array(
				'severity' => 'error',
				'message'  => 'pubDate is not in RFC 822 form (no time zone)',
			);
		}

		if ( '' === (string) $item['author'] ) {
			$lines[] = array(
				'severity' => 'error',
				'message'  => 'dc:creator is missing',
			);
		}

		if ( empty( $item['has_text'] ) ) {
			$lines[] = array(
				'severity' => 'error',
				'message'  => 'content:encoded has no text',
			);
		}

		if ( empty( $item['has_guid'] ) ) {
			$lines[] = array(
				'severity' => 'error',
				'message'  => 'guid is missing',
			);
		}

		if ( empty( $item['canonical_ok'] ) ) {
			$lines[] = array(
				'severity' => 'warning',
				'message'  => 'link has tracking parameters',
			);
		}

		return $lines;
	}
}
