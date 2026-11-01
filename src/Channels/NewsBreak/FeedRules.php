<?php
/**
 * The rules that decide which articles go into the News Break feed.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\NewsBreak;

use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * An article goes into the feed only when it passes every rule. The rules use
 * what WordPress already holds about the article: its categories, tags, author,
 * post type and language, and whether it has a featured image. They cannot read
 * an article's topic. Your own site is never affected.
 *
 * Each failure has a rule code that matches an entry in rules/news-break.json,
 * so the pack decides how serious it is.
 */
final class FeedRules {

	/**
	 * The channel's saved settings.
	 *
	 * @var array<string, mixed>
	 */
	private array $settings;

	/**
	 * Seconds a new article waits before it joins a feed.
	 *
	 * @var int
	 */
	private int $delay;

	/**
	 * Creates the evaluator.
	 *
	 * @param array<string, mixed> $settings The channel's settings.
	 * @param int                  $delay    The index-first delay in seconds.
	 */
	public function __construct( array $settings, int $delay ) {
		$this->settings = $settings;
		$this->delay    = max( 0, $delay );
	}

	/**
	 * Every rule an article fails, each once.
	 *
	 * @param Article $article The article.
	 * @return array<int, array{rule: string, text: string}>
	 */
	public function failures( Article $article ): array {
		$found   = array();
		$matched = $this->category_ids( $article );
		$include = $this->ids( 'categories_include' );
		$exclude = $this->ids( 'categories_exclude' );

		if ( array() !== $include && array() === array_intersect( $include, $matched ) ) {
			$found[] = $this->fail( 'rules.category_not_included', __( 'Not in a category the channel includes', 'estasoft-news-distribution' ) );
		}

		$hit = array_values( array_intersect( $exclude, $matched ) );

		if ( array() !== $hit ) {
			$found[] = $this->fail(
				'rules.category_excluded',
				sprintf(
					/* translators: %s: a category name such as Sport. */
					__( 'Category %s is excluded', 'estasoft-news-distribution' ),
					$this->category_name( (int) $hit[0] )
				)
			);
		}

		$tag = $this->excluded_tag( $article );

		if ( '' !== $tag ) {
			$found[] = $this->fail(
				'rules.tag_excluded',
				sprintf(
					/* translators: %s: a tag such as sponsored. */
					__( 'Tag %s is excluded', 'estasoft-news-distribution' ),
					$tag
				)
			);
		}

		if ( 'chosen' === (string) ( $this->settings['authors_mode'] ?? 'everyone' ) && ! in_array( $article->author_id(), $this->ids( 'authors' ), true ) ) {
			$found[] = $this->fail( 'rules.author_not_listed', __( 'The author is not one of the chosen authors', 'estasoft-news-distribution' ) );
		}

		$types = array_map( 'strval', (array) ( $this->settings['post_types'] ?? array() ) );

		if ( ! in_array( $article->post_type(), $types, true ) ) {
			$found[] = $this->fail( 'rules.post_type_off', __( 'This post type is not ticked', 'estasoft-news-distribution' ) );
		}

		if ( in_array( $article->language(), array_map( 'strval', (array) ( $this->settings['languages_off'] ?? array() ) ), true ) ) {
			$found[] = $this->fail( 'rules.language_off', __( 'This language is not ticked', 'estasoft-news-distribution' ) );
		}

		if ( ! empty( $this->settings['require_image'] ) && '' === $article->thumbnail_url() ) {
			$found[] = $this->fail( 'rules.image_missing', __( 'No featured image', 'estasoft-news-distribution' ) );
		}

		/**
		 * Lets an add-on say whether an article is local. Return false to leave the
		 * article out; return null (the default) when nothing is known. Nothing in
		 * News Distribution sets it on its own.
		 *
		 * @param bool|null $local   Whether the article is local, or null when unknown.
		 * @param Article   $article The article.
		 */
		$local = apply_filters( 'estasoft_nd_news_break_local', null, $article );

		if ( false === $local ) {
			$found[] = $this->fail( 'rules.not_local', __( 'Not classified as local', 'estasoft-news-distribution' ) );
		}

		return $found;
	}

	/**
	 * What would happen to an article now: in the feed, waiting for the
	 * index-first delay, or skipped with the reasons.
	 *
	 * @param Article $article The article.
	 * @param int     $now     Unix time to treat as now.
	 * @return array{status: string, text: string, detail: string, joins_at: int, rules: string[]}
	 */
	public function result( Article $article, int $now ): array {
		$failures = $this->failures( $article );

		if ( array() !== $failures ) {
			return array(
				'status'   => Status::SKIPPED,
				'text'     => Status::word( Status::SKIPPED ),
				'detail'   => implode( '. ', array_column( $failures, 'text' ) ),
				'joins_at' => 0,
				'rules'    => array_column( $failures, 'rule' ),
			);
		}

		$joins  = $article->published_ts() + $this->delay;
		$reason = $this->matched( $article );

		if ( $this->delay > 0 && $joins > $now ) {
			return array(
				'status'   => Status::QUEUED,
				'text'     => '',
				'detail'   => $reason,
				'joins_at' => $joins,
				'rules'    => array(),
			);
		}

		return array(
			'status'   => Status::IN_FEED,
			'text'     => Status::word( Status::IN_FEED ),
			'detail'   => $reason,
			'joins_at' => 0,
			'rules'    => array(),
		);
	}

	/**
	 * Counts for a set of results.
	 *
	 * @param array<int, array{status: string}> $results Results from result().
	 * @return array{total: int, match: int, in_feed: int, waiting: int, skipped: int}
	 */
	public static function counts( array $results ): array {
		$counts = array(
			'total'   => count( $results ),
			'match'   => 0,
			'in_feed' => 0,
			'waiting' => 0,
			'skipped' => 0,
		);

		foreach ( $results as $row ) {
			if ( Status::IN_FEED === $row['status'] ) {
				++$counts['in_feed'];
				++$counts['match'];
			} elseif ( Status::QUEUED === $row['status'] ) {
				++$counts['waiting'];
				++$counts['match'];
			} else {
				++$counts['skipped'];
			}
		}

		return $counts;
	}

	/**
	 * A rule failure.
	 *
	 * @param string $rule A rule code in the pack.
	 * @param string $text What went wrong, in a few words.
	 * @return array{rule: string, text: string}
	 */
	private function fail( string $rule, string $text ): array {
		return array(
			'rule' => $rule,
			'text' => $text,
		);
	}

	/**
	 * The category an article joined the feed through, as "Category Local".
	 *
	 * @param Article $article The article.
	 * @return string
	 */
	private function matched( Article $article ): string {
		$include = $this->ids( 'categories_include' );
		$matched = array_values( array_intersect( $include, $this->category_ids( $article ) ) );

		if ( array() !== $matched ) {
			return sprintf(
				/* translators: %s: a category name such as Local. */
				__( 'Category %s', 'estasoft-news-distribution' ),
				$this->category_name( (int) $matched[0] )
			);
		}

		$names = $article->categories();

		if ( array() === $names ) {
			return '';
		}

		return sprintf(
			/* translators: %s: a category name such as Local. */
			__( 'Category %s', 'estasoft-news-distribution' ),
			(string) $names[0]
		);
	}

	/**
	 * The category ids of an article and of the categories above them.
	 *
	 * @param Article $article The article.
	 * @return int[]
	 */
	private function category_ids( Article $article ): array {
		$ids = array();

		foreach ( (array) wp_get_post_categories( $article->id() ) as $id ) {
			$id    = (int) $id;
			$ids[] = $id;

			foreach ( (array) get_ancestors( $id, 'category', 'taxonomy' ) as $parent ) {
				$ids[] = (int) $parent;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * The name of a category, or its number when it no longer exists.
	 *
	 * @param int $id Category id.
	 * @return string
	 */
	private function category_name( int $id ): string {
		$term = get_term( $id, 'category' );

		return $term instanceof \WP_Term ? $term->name : (string) $id;
	}

	/**
	 * The first excluded tag the article has, as the owner typed it, or an empty string.
	 *
	 * @param Article $article The article.
	 * @return string
	 */
	private function excluded_tag( Article $article ): string {
		$has = array();

		foreach ( $article->tags() as $name ) {
			$has[ sanitize_title( (string) $name ) ] = true;
		}

		foreach ( (array) ( $this->settings['tags_exclude'] ?? array() ) as $listed ) {
			if ( isset( $has[ sanitize_title( (string) $listed ) ] ) ) {
				return (string) $listed;
			}
		}

		return '';
	}

	/**
	 * A list setting as whole numbers.
	 *
	 * @param string $name Setting name.
	 * @return int[]
	 */
	private function ids( string $name ): array {
		return array_values( array_map( 'intval', (array) ( $this->settings[ $name ] ?? array() ) ) );
	}
}
