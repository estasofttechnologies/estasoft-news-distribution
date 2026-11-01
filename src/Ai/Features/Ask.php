<?php
/**
 * Ask the assistant: questions about why an article is or is not on a channel, answered from the rules and the checks.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Ai\Features;

use Estasoft\NewsDistribution\Ai\Log;
use Estasoft\NewsDistribution\Checks\ArticleChecker;
use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\ArticleService;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\RulePacks;
use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * `POST /ai/suggest/ask` with `post` and `question`.
 *
 * The AI is given facts that News Distribution wrote down: the title, the state of the article, what the stored
 * check found, what each channel did, the application status, and the rule packs. It is asked to answer from those
 * and nothing else, and to name which of them it used. It is not given the article's text or its author.
 *
 * What comes back is held to three rules before anyone sees it:
 *
 * - It must name at least one source that exists in the facts. The "Source" line that people read is written by
 *   this class from the rule pack's own version and name, never copied from the AI.
 * - Every figure in it must be a figure in the facts, or it is dropped.
 * - When it says it cannot answer, or fails either rule, the answer is "I cannot answer that." and the request still counts.
 *
 * The question and the answer are logged. The article text is not in them.
 */
final class Ask extends AbstractFeature {

	/**
	 * Most characters of a question.
	 */
	public const MAX_QUESTION = 300;

	/**
	 * Most characters of facts sent.
	 */
	public const MAX_FACTS = 9000;

	public function name(): string {
		return 'ask';
	}

	public function label(): string {
		return __( 'Ask the assistant', 'estasoft-news-distribution' );
	}

	/**
	 * What the assistant says when it cannot answer.
	 *
	 * @return string
	 */
	public static function cannot(): string {
		return __( 'I cannot answer that.', 'estasoft-news-distribution' );
	}

	public function suggest( int $post_id, array $args ) {
		$question = trim( (string) preg_replace( '/\s+/u', ' ', sanitize_text_field( (string) ( $args['question'] ?? '' ) ) ) );

		if ( '' === $question ) {
			return new \WP_Error( 'estasoft_nd_ai_no_question', __( 'Type a question first.', 'estasoft-news-distribution' ), array( 'status' => 400 ) );
		}

		if ( mb_strlen( $question ) > self::MAX_QUESTION ) {
			$question = mb_substr( $question, 0, self::MAX_QUESTION );
		}

		$article = $this->sendable( $post_id );

		if ( is_wp_error( $article ) ) {
			return $article;
		}

		$facts  = $this->facts( $article, $question );
		$answer = $this->ask(
			array(
				'system'     => $this->system(),
				'prompt'     => "Facts:\n" . $facts['text'] . "\n\nQuestion: " . $question . "\n\nReply with JSON: {\"answer\": \"...\", \"sources\": [\"check\", \"pack:apple-news\"]}. If the facts do not answer the question, reply {\"answer\": \"I cannot answer that\", \"sources\": []}.",
				'max_tokens' => 500,
			)
		);

		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		$read    = $this->json( $answer['text'] );
		$read    = is_array( $read ) ? $read : array();
		$text    = isset( $read['answer'] ) && is_string( $read['answer'] ) ? trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $read['answer'] ) ) ) : '';
		$tags    = isset( $read['sources'] ) && is_array( $read['sources'] ) ? array_values( array_filter( array_map( 'strval', $read['sources'] ) ) ) : array();
		$sources = $this->sources( $tags, $facts );
		$reason  = '';

		if ( '' === $text || 1 === preg_match( '/^\W*i cannot answer that\W*$/iu', $text ) ) {
			$reason = 'unanswered';
		} elseif ( array() === $sources ) {
			$reason = 'no_source';
		} elseif ( ! Grounding::covered( $text, $facts['text'] ) ) {
			$reason = 'figures';
		} elseif ( mb_strlen( $text ) > 900 ) {
			$reason = 'long';
		}

		$grounded = '' === $reason;
		$shown    = $grounded ? $text : self::cannot();
		$source   = $grounded ? $this->source_line( $sources, $facts ) : '';
		$data     = array(
			'finding'  => 'ask',
			'question' => $question,
			'answer'   => $shown,
			'source'   => $source,
			'sources'  => array_column( $sources, 'tag' ),
			'grounded' => $grounded,
			'reason'   => $reason,
		);

		$log = $this->record( $post_id, $answer, $data, $grounded ? Log::ANSWERED : Log::NONE, get_current_user_id() );

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
	 * The questions and answers on an article, oldest first, for one person.
	 *
	 * @param int $post_id The article.
	 * @param int $user_id The person.
	 * @param int $limit   The most to return.
	 * @return array<int, array{log: int, question: string, answer: string, source: string, grounded: bool}>
	 */
	public function history( int $post_id, int $user_id, int $limit = 8 ): array {
		$found = $this->plugin->make( Log::class )->query(
			array(
				'feature' => $this->name(),
				'post_id' => $post_id,
				'limit'   => 40,
			)
		);
		$rows  = array();

		foreach ( $found['items'] as $row ) {
			if ( (int) $row['reviewer_id'] !== $user_id || empty( $row['data']['question'] ) ) {
				continue;
			}

			$rows[] = array(
				'log'      => (int) $row['id'],
				'question' => (string) $row['data']['question'],
				'answer'   => (string) ( $row['data']['answer'] ?? '' ),
				'source'   => (string) ( $row['data']['source'] ?? '' ),
				'grounded' => ! empty( $row['data']['grounded'] ),
			);

			if ( count( $rows ) >= $limit ) {
				break;
			}
		}

		return array_reverse( $rows );
	}

	/**
	 * The rule packs of the channels that are on, as the screen lists them under the chat.
	 *
	 * @param Article $article The article.
	 * @return array<int, array{platform: string, label: string, version: string, newest: string, source: string}>
	 */
	public function packs( Article $article ): array {
		$out   = array();
		$packs = $this->plugin->make( RulePacks::class );

		foreach ( $this->plugin->registry()->enabled() as $channel ) {
			if ( ! $channel->accepts_language( $article->language() ) ) {
				continue;
			}

			$pack = $packs->describe( $channel->id() );

			if ( array() === $pack ) {
				continue;
			}

			$out[] = array(
				'platform' => (string) $pack['platform'],
				'label'    => $channel->label(),
				'version'  => (string) $pack['version'],
				'newest'   => (string) $pack['newest'],
				'source'   => (string) $pack['source'],
			);
		}

		return $out;
	}

	/**
	 * What the provider is told it is.
	 *
	 * @return string
	 */
	private function system(): string {
		return 'You answer questions about why one news article is or is not on a platform, using only the facts you are given. '
			. 'Never use anything else, and never guess. Name every fact you used in "sources". '
			. 'A platform decides what it shows: never say that a platform will accept, list or approve an article. '
			. 'Answer in at most four plain sentences, in the language of the question. Reply with JSON and nothing else.';
	}

	/**
	 * The facts, written down by this class.
	 *
	 * @param Article $article  The article.
	 * @param string  $question The question, to decide which rule packs to include in full.
	 * @return array{text: string, tags: array<string, string>, checked: int}
	 */
	private function facts( Article $article, string $question ): array {
		$post_id = $article->id();
		$service = $this->plugin->make( ArticleService::class );
		$packs   = $this->plugin->make( RulePacks::class );
		$stored  = $this->plugin->make( ArticleChecker::class )->stored( $post_id );
		$check   = $stored ? $service->check( $post_id, false ) : null;
		$zone    = Clock::zone();
		$alt     = trim( (string) get_post_meta( (int) get_post_thumbnail_id( $post_id ), '_wp_attachment_image_alt', true ) );
		$tags    = array();
		$lines   = array();
		$post    = get_post( $post_id );
		$name    = null === $post ? $article->title() : trim( wp_strip_all_tags( (string) $post->post_title ) );

		$lines[] = sprintf(
			'Article: "%1$s" (language %2$s, status %3$s, published %4$s).',
			$name,
			$article->language(),
			$article->status(),
			wp_date( 'j M Y, H:i', $article->published_ts(), $zone )
		);
		$lines[] = sprintf( 'Title length: %d characters.', mb_strlen( $name ) );

		if ( '' === $article->thumbnail_url() ) {
			$lines[] = 'Featured image: none.';
		} else {
			$lines[] = sprintf(
				'Featured image: %1$s x %2$s px, alt text %3$s.',
				number_format_i18n( $article->thumbnail_width() ),
				number_format_i18n( $article->thumbnail_height() ),
				'' === $alt ? 'missing' : 'present'
			);
		}

		$checked = 0;

		if ( null !== $stored ) {
			$checked       = $stored->checked();
			$tags['check'] = 'check';
			$lines[]       = sprintf( '[check] The article was checked at %s. Findings:', wp_date( 'H:i', $checked, $zone ) );

			if ( array() === $stored->findings() ) {
				$lines[] = '- none';
			}

			foreach ( $stored->findings() as $finding ) {
				$lines[] = sprintf(
					'- %1$s (%2$s, %3$s): %4$s',
					(string) $finding['code'],
					(string) $finding['severity'],
					array() === (array) $finding['channels'] ? 'all channels' : implode( ', ', array_map( 'strval', (array) $finding['channels'] ) ),
					(string) $finding['message']
				);
			}
		} else {
			$lines[] = 'The article has not been checked yet.';
		}

		$lines[]   = 'Channels that are on:';
		$results   = null === $check ? array() : (array) $check['results'];
		$lowered   = mb_strtolower( $question );
		$mentioned = array();

		foreach ( $this->plugin->registry()->all() as $channel ) {
			$id = $channel->id();

			if ( false !== strpos( $lowered, mb_strtolower( $channel->label() ) ) || false !== strpos( $lowered, mb_strtolower( str_replace( '-', ' ', $id ) ) ) ) {
				$mentioned[ $id ] = true;
			}
		}

		foreach ( $this->plugin->registry()->enabled() as $channel ) {
			if ( ! $channel->accepts_language( $article->language() ) ) {
				continue;
			}

			$id          = $channel->id();
			$application = $this->plugin->applications()->get( $id );
			$result      = $results[ $id ]['status'] ?? '';
			$line        = sprintf(
				'- [pack:%1$s] %2$s: %3$s%4$s',
				$id,
				$channel->label(),
				'' === $result ? 'no result yet' : 'result ' . Status::word( (string) $result ),
				Status::NOT_REQUESTED === $application['status'] ? '' : '. Application: ' . Status::word( (string) $this->plugin->applications()->display_status( $id ) ) . ( '' === $application['applied_on'] ? '' : ', applied ' . $application['applied_on'] )
			);

			// Only a reason News Distribution wrote itself is passed on; a delivery message can hold a remote server's words.
			if ( Status::SKIPPED === $result && ! empty( $results[ $id ]['message'] ) ) {
				$line .= '. Reason: ' . wp_strip_all_tags( (string) $results[ $id ]['message'] );
			}

			$lines[]               = $line;
			$tags[ 'pack:' . $id ] = $id;
			$mentioned[ $id ]      = true;
		}

		$lines[] = 'Rule packs:';

		foreach ( array_keys( $this->plugin->rules()->all() ) as $platform ) {
			$platform = (string) $platform;
			$pack     = $packs->describe( $platform );

			if ( array() === $pack || ! isset( $mentioned[ $platform ] ) ) {
				continue;
			}

			$limits = $this->plugin->rules()->pack( $platform )['limits'] ?? array();
			$limits = is_array( $limits ) ? $limits : array();
			$text   = array();

			foreach ( $limits as $key => $value ) {
				if ( is_scalar( $value ) ) {
					$text[] = $key . ' ' . $value;
				}
			}

			$lines[] = sprintf(
				'- [pack:%1$s] %2$s, version %3$s, source "%4$s". Limits: %5$s.',
				$platform,
				$pack['label'],
				$pack['version'],
				$pack['source'],
				array() === $text ? 'none' : implode( ', ', $text )
			);

			foreach ( array_slice( $packs->rules_of( $platform ), 0, 40 ) as $rule ) {
				$lines[] = sprintf( '  - %1$s (%2$s): %3$s', $rule['code'], $rule['severity'], wp_strip_all_tags( $rule['note'] ) );
			}

			$tags[ 'pack:' . $platform ] = $platform;
		}

		$lines[] = 'Changes in the last 7 days:';

		foreach ( $this->changes( $post_id ) as $change ) {
			$lines[] = '- ' . $change;
		}

		$text = implode( "\n", $lines );

		if ( mb_strlen( $text ) > self::MAX_FACTS ) {
			$text = mb_substr( $text, 0, self::MAX_FACTS );
		}

		return array(
			'text'    => $text,
			'tags'    => $tags,
			'checked' => $checked,
		);
	}

	/**
	 * What happened to the article in the last week, as short lines without any message text.
	 *
	 * @param int $post_id The article.
	 * @return string[]
	 */
	private function changes( int $post_id ): array {
		$history = $this->plugin->make( ArticleService::class )->history( $post_id );
		$since   = Clock::now() - WEEK_IN_SECONDS;
		$zone    = Clock::zone();
		$lines   = array();

		foreach ( array_slice( (array) $history['delivery'], 0, 20 ) as $row ) {
			$at = strtotime( (string) $row['at'] );

			if ( false !== $at && $at >= $since ) {
				$lines[] = sprintf( '%1$s: %2$s, %3$s, %4$s', wp_date( 'j M, H:i', $at, $zone ), (string) $row['channel'], (string) $row['event'], (string) $row['status'] );
			}
		}

		foreach ( array_slice( (array) $history['versions'], 0, 10 ) as $row ) {
			$at = strtotime( (string) $row['at'] );

			if ( false !== $at && $at >= $since ) {
				$lines[] = sprintf( '%s: the article was saved', wp_date( 'j M, H:i', $at, $zone ) );
			}
		}

		return array() === $lines ? array( 'none' ) : array_slice( $lines, 0, 12 );
	}

	/**
	 * The sources the AI named that exist in the facts.
	 *
	 * @param string[]                                                       $named What the AI wrote in "sources".
	 * @param array{text: string, tags: array<string, string>, checked: int} $facts The facts.
	 * @return array<int, array{tag: string, kind: string, id: string}>
	 */
	private function sources( array $named, array $facts ): array {
		$out = array();

		foreach ( $named as $tag ) {
			$tag = strtolower( trim( $tag ) );

			if ( ! isset( $facts['tags'][ $tag ] ) || isset( $out[ $tag ] ) ) {
				continue;
			}

			$out[ $tag ] = array(
				'tag'  => $tag,
				'kind' => 'check' === $tag ? 'check' : 'pack',
				'id'   => $facts['tags'][ $tag ],
			);
		}

		return array_values( $out );
	}

	/**
	 * The "Source" line: written from the pack's own record, not from what the AI said.
	 *
	 * @param array<int, array{tag: string, kind: string, id: string}>       $sources The sources used.
	 * @param array{text: string, tags: array<string, string>, checked: int} $facts   The facts.
	 * @return string
	 */
	private function source_line( array $sources, array $facts ): string {
		$packs = $this->plugin->make( RulePacks::class );
		$parts = array();
		$newer = '';

		foreach ( $sources as $source ) {
			if ( 'check' === $source['kind'] ) {
				$parts[] = sprintf(
					/* translators: %s: a time such as 14:33. */
					__( 'This article’s check at %s.', 'estasoft-news-distribution' ),
					wp_date( 'H:i', (int) $facts['checked'], Clock::zone() )
				);
				continue;
			}

			$pack = $packs->describe( $source['id'] );

			if ( array() === $pack ) {
				continue;
			}

			$parts[] = sprintf(
				/* translators: 1: a rule pack version such as 2026.09.3, 2: the name of the page the rules come from. */
				__( 'rule pack %1$s, %2$s.', 'estasoft-news-distribution' ),
				$pack['version'],
				$pack['source']
			);

			if ( $pack['version'] !== $pack['newest'] ) {
				$newer = sprintf(
					/* translators: %s: a rule pack version such as 2026.10.1. */
					__( 'A newer pack, %s, is available but not applied yet.', 'estasoft-news-distribution' ),
					$pack['newest']
				);
			}
		}

		if ( array() === $parts ) {
			return '';
		}

		return trim( __( 'Source:', 'estasoft-news-distribution' ) . ' ' . implode( ' ', $parts ) . ( '' === $newer ? '' : ' ' . $newer ) );
	}
}
