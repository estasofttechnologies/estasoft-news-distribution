<?php
/**
 * Summary suggestions: the short text platforms print under a headline, and the search description of a page.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Ai\Features;

use Estasoft\NewsDistribution\Ai\Log;
use Estasoft\NewsDistribution\Ai\Marker;
use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * `POST /ai/suggest/summary` asks the provider for five short texts. `kind` says which: `summary` (the default) is a one or
 * two sentence summary for feeds, and `description` is a search description of up to 155 characters for the page.
 * Each text goes through `RuleCheck::summary()` or `RuleCheck::description()`. Up to three that pass are shown, with the
 * checks that passed. Those that break a rule are counted, never shown.
 *
 * What is sent: the headline, about 600 characters from the start of the article, the language, the length limit and the
 * rules. Not the author, not the whole text. For a draft or a scheduled post nothing is sent unless the site chose
 * "Also drafts and scheduled posts" (and the network allows it).
 *
 * The AI log row carries the feature name `summary` or `description`, and `finding` says the same.
 *
 * `POST /ai/decision` is the only step that changes anything:
 *
 * - `use_feeds`: platforms print this summary (post meta `_estasoft_nd_feed_summary`, read through `Article::summary()`);
 *   the excerpt of the post stays.
 * - `excerpt`: the excerpt of the post changes. A feed-only summary set earlier is removed, so feeds do not keep an older one.
 * - `use_description`: the search description is stored (`_estasoft_nd_meta_description`). `Markup\Description` prints it in the
 *   page head only when no other plugin prints a description.
 * - `edit`: the person's own wording, checked again, then used as `target` says (`feeds`, `excerpt` or `description`).
 * - `dismiss`: nothing changes.
 * - `clear_feeds` and `clear_description`: the stored text is removed.
 */
final class Summary extends AbstractFeature {

	/**
	 * Texts asked for in one request.
	 */
	public const ASKED = 5;

	/**
	 * Most suggestions shown.
	 */
	public const SHOWN = 3;

	/**
	 * Post meta holding a summary used in feeds only.
	 */
	public const FEED_SUMMARY = '_estasoft_nd_feed_summary';

	/**
	 * Post meta holding the search description.
	 */
	public const META_DESCRIPTION = '_estasoft_nd_meta_description';

	/**
	 * Characters of the article's opening that are sent.
	 */
	public const LEAD = 600;

	public function name(): string {
		return 'summary';
	}

	public function label(): string {
		return __( 'Summary and description suggestions', 'estasoft-news-distribution' );
	}

	/**
	 * The summary platforms print for an article when a person chose one for feeds only.
	 *
	 * @param int $post_id The article.
	 * @return string Empty when none was chosen.
	 */
	public static function feed_summary( int $post_id ): string {
		$value = get_post_meta( $post_id, self::FEED_SUMMARY, true );

		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * The search description a person chose for an article.
	 *
	 * @param int $post_id The article.
	 * @return string Empty when none was chosen.
	 */
	public static function meta_description( int $post_id ): string {
		$value = get_post_meta( $post_id, self::META_DESCRIPTION, true );

		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * What the article has now, to show beside a suggestion.
	 *
	 * @param int $post_id The article.
	 * @return array{excerpt: string, feed: string, description: string}
	 */
	public function current( int $post_id ): array {
		$post = get_post( $post_id );

		return array(
			'excerpt'     => $post instanceof \WP_Post ? trim( wp_strip_all_tags( (string) $post->post_excerpt ) ) : '',
			'feed'        => self::feed_summary( $post_id ),
			'description' => self::meta_description( $post_id ),
		);
	}

	/**
	 * The newest suggestions of this feature for an article that still wait for a decision.
	 *
	 * @param int    $post_id The article.
	 * @param string $finding `summary`, `description`, or empty for either.
	 * @return array<string, mixed>|null
	 */
	public function pending_row( int $post_id, string $finding = '' ): ?array {
		$names = in_array( $finding, array( 'summary', 'description' ), true ) ? array( $finding ) : array( 'summary', 'description' );
		$found = $this->plugin->make( Log::class )->query(
			array(
				'feature' => $names,
				'post_id' => $post_id,
				'limit'   => 5,
			)
		);

		foreach ( $found['items'] as $row ) {
			if ( Log::PENDING !== $row['decision'] || Clock::now() - (int) $row['created'] > DAY_IN_SECONDS || ! isset( $row['data']['suggestions'] ) ) {
				continue;
			}

			return $row;
		}

		return null;
	}

	public function suggest( int $post_id, array $args ) {
		$kind    = 'description' === sanitize_key( (string) ( $args['kind'] ?? '' ) ) ? 'description' : 'summary';
		$article = $this->sendable( $post_id );

		if ( is_wp_error( $article ) ) {
			return $article;
		}

		$check = $this->plugin->make( RuleCheck::class );
		$limit = $this->limit( $article, $kind );

		$answer = $this->ask(
			array(
				'system'     => $this->system(),
				'prompt'     => $this->prompt( $article, $kind, $limit['max'] ),
				'max_tokens' => 700,
			)
		);

		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		$now        = $this->current( $post_id );
		$candidates = array();
		$seen       = array();
		$dropped    = 0;
		$returned   = 0;

		foreach ( $now as $value ) {
			if ( '' !== $value ) {
				$seen[ mb_strtolower( $value ) ] = true;
			}
		}

		foreach ( $this->texts( $answer['text'] ) as $text ) {
			$key = mb_strtolower( $text );

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			++$returned;

			if ( $returned > self::ASKED ) {
				break;
			}

			$result = 'description' === $kind ? $check->description( $article, $text ) : $check->summary( $article, $text, $limit['max'] );

			if ( ! $result['pass'] ) {
				++$dropped;
				continue;
			}

			if ( count( $candidates ) < self::SHOWN ) {
				$candidates[] = array(
					'text'   => $text,
					'chars'  => mb_strlen( $text ),
					'checks' => $result['lines'],
					'pass'   => true,
				);
			}
		}

		$data = array(
			'finding'     => $kind,
			'current'     => array(
				'excerpt'     => mb_substr( $now['excerpt'], 0, 300 ),
				'feed'        => $now['feed'],
				'description' => $now['description'],
			),
			'limit'       => $limit,
			'suggestions' => $candidates,
			'dropped'     => $dropped,
			'asked'       => self::ASKED,
			'returned'    => $returned,
		);

		$log = $this->plugin->make( Log::class )->add(
			array(
				'post_id'    => $post_id,
				'feature'    => $kind,
				'connection' => $answer['provider'] . ':' . $answer['model'],
				'decision'   => array() === $candidates ? Log::NONE : Log::PENDING,
				'data'       => array_merge(
					$data,
					array(
						'tokens'       => $answer['tokens'],
						'ms'           => $answer['ms'],
						'requested_by' => get_current_user_id(),
					)
				),
			)
		);

		return array_merge(
			array(
				'log'     => $log,
				'feature' => $kind,
				'kind'    => $kind,
			),
			$data,
			$this->meta( $answer ),
			array( 'message' => array() === $candidates ? $this->none_text( $returned ) : '' )
		);
	}

	public function decide( array $row, array $args, int $user_id ) {
		$post_id  = (int) $row['post_id'];
		$decision = sanitize_key( (string) ( $args['decision'] ?? '' ) );
		$kind     = 'description' === ( $row['data']['finding'] ?? $row['feature'] ) ? 'description' : 'summary';
		$log      = $this->plugin->make( Log::class );

		if ( 'clear_feeds' === $decision || 'clear_description' === $decision ) {
			$key      = 'clear_feeds' === $decision ? self::FEED_SUMMARY : self::META_DESCRIPTION;
			$previous = 'clear_feeds' === $decision ? self::feed_summary( $post_id ) : self::meta_description( $post_id );

			delete_post_meta( $post_id, $key );
			$log->annotate(
				(int) $row['id'],
				array(
					'cleared_' . ( 'clear_feeds' === $decision ? 'feeds' : 'description' ) => array(
						'by'       => $user_id,
						'previous' => $previous,
					),
				)
			);

			return array(
				'log'      => (int) $row['id'],
				'decision' => $decision,
				'applied'  => 'cleared',
				'text'     => '',
				'message'  => 'clear_feeds' === $decision
					? __( 'Platforms read their usual summary of the article again.', 'estasoft-news-distribution' )
					: __( 'The saved search description is removed.', 'estasoft-news-distribution' ),
			);
		}

		$decided = $this->already_decided( $row );

		if ( null !== $decided ) {
			return $decided;
		}

		if ( 'dismiss' === $decision ) {
			$log->decide( (int) $row['id'], Log::DISMISSED, $user_id );

			return array(
				'log'      => (int) $row['id'],
				'decision' => Log::DISMISSED,
				'applied'  => '',
				'text'     => '',
				'message'  => __( 'Dismissed. Nothing was changed.', 'estasoft-news-distribution' ),
			);
		}

		$targets = 'description' === $kind ? array( 'description' ) : array( 'feeds', 'excerpt' );

		if ( 'edit' === $decision ) {
			$text   = sanitize_text_field( (string) ( $args['text'] ?? '' ) );
			$target = sanitize_key( (string) ( $args['target'] ?? '' ) );
			$target = in_array( $target, $targets, true ) ? $target : $targets[0];
			$edited = true;
		} elseif ( ( 'summary' === $kind && in_array( $decision, array( 'use_feeds', 'excerpt' ), true ) ) || ( 'description' === $kind && 'use_description' === $decision ) ) {
			$stored = $this->stored_suggestion( $row, (int) ( $args['index'] ?? -1 ) );

			if ( null === $stored ) {
				return new \WP_Error( 'estasoft_nd_ai_no_suggestion', __( 'That suggestion is not in the record. Ask for new suggestions.', 'estasoft-news-distribution' ), array( 'status' => 404 ) );
			}

			$text   = (string) $stored['text'];
			$target = 'use_feeds' === $decision ? 'feeds' : ( 'excerpt' === $decision ? 'excerpt' : 'description' );
			$edited = false;
		} else {
			return new \WP_Error(
				'estasoft_nd_ai_bad_decision',
				'description' === $kind
					? __( 'Choose Use as search description, Edit first or Dismiss.', 'estasoft-news-distribution' )
					: __( 'Choose Use in feeds only, Use as excerpt, Edit first or Dismiss.', 'estasoft-news-distribution' ),
				array( 'status' => 400 )
			);
		}

		$article = $this->plugin->reader()->find( $post_id );

		if ( null === $article ) {
			return new \WP_Error( 'estasoft_nd_ai_no_article', __( 'That is not an article News Distribution sends.', 'estasoft-news-distribution' ), array( 'status' => 404 ) );
		}

		// Text a person typed is held to the same rules as a suggestion. A stored suggestion was checked when it was made, and is checked again now because the rules may have changed.
		$check  = $this->plugin->make( RuleCheck::class );
		$result = 'description' === $kind ? $check->description( $article, $text ) : $check->summary( $article, $text, $this->limit( $article, $kind )['max'] );

		if ( ! $result['pass'] ) {
			return new \WP_Error(
				'estasoft_nd_ai_rule_check',
				'description' === $kind
					? __( 'That description breaks a rule, so it was not used. Change the wording and try again.', 'estasoft-news-distribution' )
					: __( 'That summary breaks a rule, so it was not used. Change the wording and try again.', 'estasoft-news-distribution' ),
				array(
					'status' => 422,
					'checks' => $result['lines'],
				)
			);
		}

		$post = get_post( $post_id );

		if ( 'excerpt' === $target ) {
			$previous = $post instanceof \WP_Post ? (string) $post->post_excerpt : '';
			$done     = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_excerpt' => wp_slash( $text ),
				),
				true
			);

			if ( is_wp_error( $done ) ) {
				return new \WP_Error( 'estasoft_nd_ai_not_saved', __( 'WordPress could not save the excerpt. Nothing was changed.', 'estasoft-news-distribution' ), array( 'status' => 500 ) );
			}

			// An excerpt of the person's own makes the feed summary they set earlier out of date.
			delete_post_meta( $post_id, self::FEED_SUMMARY );
		} elseif ( 'feeds' === $target ) {
			$previous = self::feed_summary( $post_id );

			update_post_meta( $post_id, self::FEED_SUMMARY, $text );
		} else {
			$previous = self::meta_description( $post_id );

			update_post_meta( $post_id, self::META_DESCRIPTION, $text );
		}

		$this->plugin->make( Marker::class )->mark( $post_id, (int) $row['id'], (string) $row['feature'], $user_id );

		if ( $edited ) {
			$outcome = Log::EDITED;
		} else {
			$outcome = 'feeds' === $target ? Log::USED_FEEDS : Log::USED;
		}

		$log->decide(
			(int) $row['id'],
			$outcome,
			$user_id,
			array(
				'chosen' => array(
					'text'     => $text,
					'target'   => $target,
					'edited'   => $edited,
					'previous' => $previous,
				),
			)
		);

		if ( 'excerpt' === $target ) {
			$message = __( 'The excerpt of the article changed. The article is marked AI-assisted.', 'estasoft-news-distribution' );
		} elseif ( 'feeds' === $target ) {
			$message = __( 'Platforms now print this summary. The excerpt of the article stays as it was. The article is marked AI-assisted.', 'estasoft-news-distribution' );
		} else {
			$message = __( 'The search description is saved. It is printed in the page head only when no other plugin prints one. The article is marked AI-assisted.', 'estasoft-news-distribution' );
		}

		return array(
			'log'      => (int) $row['id'],
			'decision' => $outcome,
			'applied'  => $target,
			'text'     => $text,
			'message'  => $message,
		);
	}

	/**
	 * The longest a text may be, and where that number comes from.
	 *
	 * @param Article $article The article.
	 * @param string  $kind    `summary` or `description`.
	 * @return array{max: int, source: string, platform: string}
	 */
	public function limit( Article $article, string $kind ): array {
		if ( 'description' === $kind ) {
			return array(
				'max'      => RuleCheck::DESCRIPTION_MAX,
				'source'   => 'own',
				'platform' => '',
			);
		}

		return $this->plugin->make( RuleCheck::class )->summary_limit( $article );
	}

	/**
	 * What the provider is told it is.
	 *
	 * @return string
	 */
	private function system(): string {
		return 'You write the short text that news platforms print under a headline, and the description that search engines may show for a page. Follow the rules you are given exactly. Use only facts that the text you are given states, and never invent names, numbers, places or quotes. Write in the language of the article. Do not copy sentences from the article word for word. Reply with a JSON list of strings and nothing else.';
	}

	/**
	 * The request: the headline, a little of the article, the language, the limit and the rules.
	 *
	 * The author is never part of it, and neither is any text beyond the first `LEAD` characters.
	 *
	 * @param Article $article The article.
	 * @param string  $kind    `summary` or `description`.
	 * @param int     $max     The most characters.
	 * @return string
	 */
	private function prompt( Article $article, string $kind, int $max ): string {
		$post     = get_post( $article->id() );
		$headline = $post instanceof \WP_Post ? trim( wp_strip_all_tags( (string) $post->post_title ) ) : $article->title();

		$text  = "Language of the article: {$article->language()}\n";
		$text .= "Headline: {$headline}\n";
		$text .= 'Start of the article: ' . $this->lead( $article, self::LEAD ) . "\n";

		if ( 'description' === $kind ) {
			$text .= "Rules for the search description:\n- One or two sentences of plain text, no quotes, no markup, no web addresses.\n";
			$text .= sprintf( "- At most %d characters.\n", $max );
			$text .= "- Say plainly what the article is about, without promising anything the article does not say.\n";
		} else {
			$text .= "Rules for the summary:\n- One or two sentences of plain text, no quotes, no markup, no web addresses.\n";
			$text .= sprintf( "- At most %d characters.\n", $max );
		}

		$text .= "- Do not copy the opening of the article word for word.\n- Do not include an author name or a byline.\n";
		$text .= sprintf( 'Write %1$d different texts. Reply with a JSON list of %1$d strings.', self::ASKED );

		return $text;
	}

	/**
	 * What the panel says when no suggestion passed.
	 *
	 * @param int $returned How many texts came back.
	 * @return string
	 */
	private function none_text( int $returned ): string {
		if ( $returned < 2 ) {
			return __( 'The suggestion did not follow the rules, so it is not shown. Nothing was changed. Write one yourself, or ask again.', 'estasoft-news-distribution' );
		}

		return sprintf(
			/* translators: %d: how many suggestions were checked. */
			__( 'None of the %d suggestions followed the rules, so none is shown. Nothing was changed. Write one yourself, or ask again.', 'estasoft-news-distribution' ),
			$returned
		);
	}
}
