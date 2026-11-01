<?php
/**
 * Fix suggestions: new titles that follow the rules of the platforms that are on.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Ai\Features;

use Estasoft\NewsDistribution\Ai\Log;
use Estasoft\NewsDistribution\Ai\Marker;
use Estasoft\NewsDistribution\Checks\ArticleChecker;
use Estasoft\NewsDistribution\Core\Article;

defined( 'ABSPATH' ) || exit;

/**
 * `POST /ai/suggest/fix` asks the provider for five titles. Each one goes through `RuleCheck::title()`, which runs
 * the article checks and the rule packs on the article read again with that title. Up to three that pass are
 * shown, with the checks that passed. Those that break a rule are counted, never shown.
 *
 * What is sent: the current title, about 400 characters from the start of the article, what the checks say is wrong
 * with the title, and the rules the packs of the platforms that are on give. Not the whole article, not the author.
 *
 * `POST /ai/decision` is the only step that changes anything:
 *
 * - `use_feeds`: the title sent to platforms changes (post meta `_estasoft_nd_feed_title`); the headline on the site stays.
 * - `headline`: the title of the article changes.
 * - `edit`: the person's own wording, checked again, then used as `target` says (`feeds` or `headline`).
 * - `dismiss`: nothing changes.
 * - `clear_feeds`: the feed title is removed so platforms read the headline again.
 */
final class Fix extends AbstractFeature {

	/**
	 * Titles asked for in one request.
	 */
	public const ASKED = 5;

	/**
	 * Most suggestions shown.
	 */
	public const SHOWN = 3;

	/**
	 * Post meta holding a title used in feeds only.
	 */
	public const FEED_TITLE = '_estasoft_nd_feed_title';

	/**
	 * Characters of the article's opening that are sent.
	 */
	public const LEAD = 400;

	public function name(): string {
		return 'fix';
	}

	public function label(): string {
		return __( 'Fix suggestions', 'estasoft-news-distribution' );
	}

	/**
	 * The title platforms read for an article when a person chose one for feeds only.
	 *
	 * @param int $post_id The article.
	 * @return string Empty when none was chosen.
	 */
	public static function feed_title( int $post_id ): string {
		$value = get_post_meta( $post_id, self::FEED_TITLE, true );

		return is_string( $value ) ? trim( $value ) : '';
	}

	public function suggest( int $post_id, array $args ) {
		$article = $this->sendable( $post_id );

		if ( is_wp_error( $article ) ) {
			return $article;
		}

		$post    = get_post( $post_id );
		$current = null === $post ? $article->title() : trim( wp_strip_all_tags( (string) $post->post_title ) );
		$check   = $this->plugin->make( RuleCheck::class );

		$answer = $this->ask(
			array(
				'system'     => $this->system(),
				'prompt'     => $this->prompt( $article, $current, $check ),
				'max_tokens' => 600,
			)
		);

		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		$candidates = array();
		$seen       = array( mb_strtolower( $current ) => true );
		$dropped    = 0;
		$returned   = 0;

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

			$result = $check->title( $article, $text );

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
			'finding'     => 'title',
			'current'     => array(
				'text'  => $current,
				'chars' => mb_strlen( $current ),
			),
			'suggestions' => $candidates,
			'dropped'     => $dropped,
			'asked'       => self::ASKED,
			'returned'    => $returned,
		);

		$log = $this->record( $post_id, $answer, $data );

		return array_merge(
			array(
				'log'     => $log,
				'feature' => $this->name(),
			),
			$data,
			$this->meta( $answer ),
			array( 'message' => array() === $candidates ? $this->none_text( $returned ) : '' )
		);
	}

	public function decide( array $row, array $args, int $user_id ) {
		$post_id  = (int) $row['post_id'];
		$decision = sanitize_key( (string) ( $args['decision'] ?? '' ) );
		$log      = $this->plugin->make( Log::class );

		if ( 'clear_feeds' === $decision ) {
			$previous = self::feed_title( $post_id );
			delete_post_meta( $post_id, self::FEED_TITLE );
			$this->plugin->make( ArticleChecker::class )->check( $post_id, true );
			$log->annotate(
				(int) $row['id'],
				array(
					'feed_title_cleared' => array(
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
				'message'  => __( 'Platforms read the headline of the article again.', 'estasoft-news-distribution' ),
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

		if ( 'edit' === $decision ) {
			$text   = sanitize_text_field( (string) ( $args['text'] ?? '' ) );
			$target = 'headline' === ( $args['target'] ?? '' ) ? 'headline' : 'feeds';
			$edited = true;
		} elseif ( 'use_feeds' === $decision || 'headline' === $decision ) {
			$stored = $this->stored_suggestion( $row, (int) ( $args['index'] ?? -1 ) );

			if ( null === $stored ) {
				return new \WP_Error( 'estasoft_nd_ai_no_suggestion', __( 'That suggestion is not in the record. Ask for new suggestions.', 'estasoft-news-distribution' ), array( 'status' => 404 ) );
			}

			$text   = (string) $stored['text'];
			$target = 'headline' === $decision ? 'headline' : 'feeds';
			$edited = false;
		} else {
			return new \WP_Error( 'estasoft_nd_ai_bad_decision', __( 'Choose Use in feeds only, Use as headline, Edit first or Dismiss.', 'estasoft-news-distribution' ), array( 'status' => 400 ) );
		}

		$article = $this->plugin->reader()->find( $post_id );

		if ( null === $article ) {
			return new \WP_Error( 'estasoft_nd_ai_no_article', __( 'That is not an article News Distribution sends.', 'estasoft-news-distribution' ), array( 'status' => 404 ) );
		}

		// Text a person typed is held to the same rules as a suggestion. A stored suggestion was checked when it was made, and is checked again now because the rules may have changed.
		$result = $this->plugin->make( RuleCheck::class )->title( $article, $text );

		if ( ! $result['pass'] ) {
			return new \WP_Error(
				'estasoft_nd_ai_rule_check',
				__( 'That title breaks a platform rule, so it was not used. Change the wording and try again.', 'estasoft-news-distribution' ),
				array(
					'status' => 422,
					'checks' => $result['lines'],
				)
			);
		}

		$post     = get_post( $post_id );
		$previous = 'headline' === $target ? ( null === $post ? '' : (string) $post->post_title ) : self::feed_title( $post_id );

		if ( 'headline' === $target ) {
			$done = wp_update_post(
				array(
					'ID'         => $post_id,
					'post_title' => wp_slash( $text ),
				),
				true
			);

			if ( is_wp_error( $done ) ) {
				return new \WP_Error( 'estasoft_nd_ai_not_saved', __( 'WordPress could not save the title. Nothing was changed.', 'estasoft-news-distribution' ), array( 'status' => 500 ) );
			}

			// A headline of the person's own makes the feed title they set earlier out of date.
			delete_post_meta( $post_id, self::FEED_TITLE );
		} else {
			update_post_meta( $post_id, self::FEED_TITLE, $text );
		}

		$this->plugin->make( Marker::class )->mark( $post_id, (int) $row['id'], $this->name(), $user_id );
		$this->plugin->make( ArticleChecker::class )->check( $post_id, true );

		if ( $edited ) {
			$outcome = Log::EDITED;
		} else {
			$outcome = 'headline' === $target ? Log::USED : Log::USED_FEEDS;
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

		return array(
			'log'      => (int) $row['id'],
			'decision' => $outcome,
			'applied'  => $target,
			'text'     => $text,
			'message'  => 'headline' === $target
				? __( 'The title of the article changed. The article is marked AI-assisted.', 'estasoft-news-distribution' )
				: __( 'Platforms now read this title. The headline on your site stays as it was. The article is marked AI-assisted.', 'estasoft-news-distribution' ),
		);
	}

	/**
	 * What the provider is told it is.
	 *
	 * @return string
	 */
	private function system(): string {
		return 'You write headlines for a news publisher. Follow the rules you are given exactly. Use only facts that the text you are given states, and never invent names, numbers or places. Write in the language of the article. Reply with a JSON list of strings and nothing else.';
	}

	/**
	 * The request: the title, a little of the article, what is wrong and the rules.
	 *
	 * @param Article   $article The article.
	 * @param string    $current The title now.
	 * @param RuleCheck $check   The rule check, for the numbers.
	 * @return string
	 */
	private function prompt( Article $article, string $current, RuleCheck $check ): string {
		$rules = $check->constraints( $article );
		$lines = array();

		if ( $rules['min'] > 0 && $rules['max'] > 0 ) {
			$lines[] = sprintf( 'Between %1$d and %2$d characters.', $rules['min'], $rules['max'] );
		} elseif ( $rules['max'] > 0 ) {
			$lines[] = sprintf( 'At most %d characters.', $rules['max'] );
		}

		if ( $rules['name'] ) {
			$name    = $this->plugin->settings()->publication_name( $article->language() );
			$lines[] = '' === $name ? 'Do not include the name of the publication.' : sprintf( 'Do not include the name of the publication (%s).', $name );
		}

		if ( $rules['author'] ) {
			$lines[] = 'Do not include an author name or a byline.';
		}

		if ( $rules['date'] ) {
			$lines[] = 'Do not include a date.';
		}

		$problems = array();

		foreach ( $this->plugin->make( ArticleChecker::class )->build( $article )->findings() as $finding ) {
			if ( 0 === strpos( (string) $finding['code'], 'title.' ) ) {
				$problems[] = (string) $finding['message'];
			}
		}

		$text  = "Language of the article: {$article->language()}\n";
		$text .= "Current headline: {$current}\n";
		$text .= 'Start of the article: ' . $this->lead( $article, self::LEAD ) . "\n";

		if ( array() !== $problems ) {
			$text .= 'What is wrong with the current headline: ' . implode( ' ', array_unique( $problems ) ) . "\n";
		}

		$text .= "Rules for the new headline:\n- One line of plain text, no quotes.\n";

		foreach ( $lines as $line ) {
			$text .= '- ' . $line . "\n";
		}

		$text .= sprintf( 'Write %1$d different headlines that say the same thing as the current one. Reply with a JSON list of %1$d strings.', self::ASKED );

		return $text;
	}

	/**
	 * What the panel says when no suggestion passed.
	 *
	 * @param int $returned How many titles came back.
	 * @return string
	 */
	private function none_text( int $returned ): string {
		if ( $returned < 2 ) {
			return __( 'The suggestion did not follow the rule, so it is not shown. Nothing was changed. Write one yourself, or ask again.', 'estasoft-news-distribution' );
		}

		return sprintf(
			/* translators: %d: how many suggestions were checked. */
			__( 'None of the %d suggestions followed the rule, so none is shown. Nothing was changed. Write one yourself, or ask again.', 'estasoft-news-distribution' ),
			$returned
		);
	}
}
