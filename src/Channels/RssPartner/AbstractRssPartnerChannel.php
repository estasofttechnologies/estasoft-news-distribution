<?php
/**
 * Shared behaviour of the partner feeds that are plain RSS 2.0.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\RssPartner;

use Estasoft\NewsDistribution\Channels\AbstractChannel;
use Estasoft\NewsDistribution\Channels\SearchSitemap\FetchSummary;
use Estasoft\NewsDistribution\Channels\ValidationResult;
use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\ArticleId;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Support\PartnerFeedCheck;
use Estasoft\NewsDistribution\Support\PartnerRss;

defined( 'ABSPATH' ) || exit;

/**
 * One RSS 2.0 feed per language. A subclass says which articles wait (Yahoo),
 * which fields an item needs (Microsoft Start needs a thumbnail) and what goes
 * into an item beyond the common fields.
 *
 * The articles a feed leaves out are the ones whose `validate()` result has an
 * error, so the feed, the article check and the "left out" counts agree.
 */
abstract class AbstractRssPartnerChannel extends AbstractChannel {

	public function content_type(): string {
		return 'application/rss+xml; charset=UTF-8';
	}

	public function feed_window(): array {
		$hours = (int) $this->context->rules->limit( $this->id(), 'window_hours', 168 );
		$limit = (int) $this->context->rules->limit( $this->id(), 'max_items', 100 );

		return array(
			'max_age' => max( 1, $hours ) * HOUR_IN_SECONDS,
			'limit'   => max( 1, $limit ),
		);
	}

	public function validate( Article $article ): ValidationResult {
		$result = new ValidationResult();

		if ( '' === trim( $article->title() ) ) {
			$this->finding( $result, 'title.missing', Status::BLOCK, __( 'The article has no title, so it is left out of the feed.', 'estasoft-news-distribution' ) );
		}

		if ( '' === $article->permalink() ) {
			$this->finding( $result, 'link.missing', Status::BLOCK, __( 'The article has no public address, so it is left out of the feed.', 'estasoft-news-distribution' ) );
		}

		return $result;
	}

	/**
	 * Adds a finding at the severity the rule pack gives it.
	 *
	 * @param ValidationResult $result  The result to add to.
	 * @param string           $code    Finding code, the key of the rule in the pack.
	 * @param string           $fallback Severity when the pack has no entry.
	 * @param string           $message One line a person can act on.
	 * @return void
	 */
	protected function finding( ValidationResult $result, string $code, string $fallback, string $message ): void {
		$result->add( $this->context->rules->severity( $this->id(), $code, $fallback ), $code, $message );
	}

	/**
	 * Seconds an article waits after publishing before this feed carries it.
	 *
	 * @return int
	 */
	protected function hold_seconds(): int {
		return 0;
	}

	/**
	 * The articles that go into the feed: published, not in the future, not held,
	 * with no blocking finding, newest first, no more than the feed holds.
	 *
	 * @param Article[] $articles Candidate articles.
	 * @param int       $now      Unix time to treat as now.
	 * @return Article[]
	 */
	public function entries( array $articles, int $now ): array {
		$window = $this->feed_window();
		$oldest = $now - (int) $window['max_age'];
		$newest = $now - $this->hold_seconds();
		$keep   = array();

		foreach ( $articles as $article ) {
			if ( ! $article instanceof Article || ! $article->is_public() ) {
				continue;
			}

			if ( $article->published_ts() < $oldest || $article->published_ts() > $newest ) {
				continue;
			}

			if ( ! $this->validate( $article )->is_valid() ) {
				continue;
			}

			$keep[] = $article;
		}

		usort(
			$keep,
			static function ( Article $a, Article $b ): int {
				return $b->published_ts() <=> $a->published_ts();
			}
		);

		return array_slice( $keep, 0, (int) $window['limit'] );
	}

	public function build( array $articles, string $language, ?int $now = null ): string {
		$now      = null === $now ? Clock::now() : $now;
		$entries  = $this->entries( $articles, $now );
		$modified = 0;

		foreach ( $entries as $article ) {
			$modified = max( $modified, $article->modified_ts(), $article->published_ts() );
		}

		$modified    = $modified > 0 ? $modified : $now;
		$description = trim( (string) get_bloginfo( 'description' ) );
		$name        = $this->context->settings->publication_name( $language );

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<rss version="2.0" xmlns:atom="' . PartnerRss::NS_ATOM . '" xmlns:media="' . PartnerRss::NS_MEDIA . '" xmlns:dc="' . PartnerRss::NS_DC . '" xmlns:content="' . PartnerRss::NS_CONTENT . '">' . "\n";
		$xml .= "\t<channel>\n";
		$xml .= "\t\t<title>" . PartnerRss::xml( $name ) . "</title>\n";
		$xml .= "\t\t<link>" . PartnerRss::xml( home_url( '/' ) ) . "</link>\n";
		$xml .= "\t\t<description>" . PartnerRss::xml( '' !== $description ? $description : $name ) . "</description>\n";
		$xml .= "\t\t<language>" . PartnerRss::xml( PartnerRss::language( $language ) ) . "</language>\n";
		$xml .= "\t\t<lastBuildDate>" . PartnerRss::rfc822( $modified ) . "</lastBuildDate>\n";
		$xml .= "\t\t" . '<atom:link rel="self" type="application/rss+xml" href="' . PartnerRss::xml( $this->feed_url( $language ) ) . '"/>' . "\n";

		foreach ( $entries as $article ) {
			$xml .= "\t\t<item>\n" . $this->item( $article, $language ) . "\t\t</item>\n";
		}

		return $xml . "\t</channel>\n</rss>\n";
	}

	/**
	 * The public address of this channel's feed in one language.
	 *
	 * @param string $language Language code.
	 * @return string
	 */
	public function feed_url( string $language ): string {
		return add_query_arg( 'lang', rawurlencode( $language ), home_url( '/feed/' . $this->feed_slug() . '/' ) );
	}

	/**
	 * The inside of one item: the common fields, then the channel's own.
	 *
	 * @param Article $article  The article.
	 * @param string  $language Language code.
	 * @return string Indented XML lines.
	 */
	protected function item( Article $article, string $language ): string {
		// An article that already has its ID is not checked again: the check for a copied ID is a query of its own, and a
		// feed has an item for every article. The ID is made and checked when the article is published.
		$ids = new ArticleId();
		$id  = $ids->get( $article->id() );

		if ( '' === $id ) {
			$id = $ids->ensure( $article->id() );
		}

		$xml  = "\t\t\t<title>" . PartnerRss::xml( $article->title() ) . "</title>\n";
		$xml .= "\t\t\t<link>" . PartnerRss::xml( $article->permalink() ) . "</link>\n";
		$xml .= "\t\t\t" . '<guid isPermaLink="false">' . PartnerRss::xml( $id ) . "</guid>\n";
		$xml .= "\t\t\t<pubDate>" . PartnerRss::rfc822( $article->published_ts() ) . "</pubDate>\n";

		if ( '' !== $article->author_name() ) {
			$xml .= "\t\t\t<dc:creator>" . PartnerRss::xml( $article->author_name() ) . "</dc:creator>\n";
		}

		foreach ( $article->categories() as $category ) {
			$xml .= "\t\t\t<category>" . PartnerRss::xml( $category ) . "</category>\n";
		}

		return $xml . $this->item_extra( $article, $language );
	}

	/**
	 * The fields only this channel writes into an item.
	 *
	 * @param Article $article  The article.
	 * @param string  $language Language code.
	 * @return string Indented XML lines.
	 */
	abstract protected function item_extra( Article $article, string $language ): string;

	/**
	 * Item fields that must be filled for the feed to count as valid, as the
	 * keys PartnerRss::read() returns.
	 *
	 * @return string[]
	 */
	protected function required_fields(): array {
		return array( 'title', 'link', 'guid', 'pubDate' );
	}

	public function check_output( string $body, string $language ): array {
		unset( $language );

		$read     = PartnerRss::read( $body );
		$problems = $read['problems'];
		$names    = array(
			'title'     => __( 'a title', 'estasoft-news-distribution' ),
			'link'      => __( 'an address', 'estasoft-news-distribution' ),
			'guid'      => __( 'a unique ID', 'estasoft-news-distribution' ),
			'pubDate'   => __( 'a publication date', 'estasoft-news-distribution' ),
			'updated'   => __( 'an updated date', 'estasoft-news-distribution' ),
			'thumbnail' => __( 'a thumbnail', 'estasoft-news-distribution' ),
		);

		foreach ( $read['items'] as $index => $item ) {
			foreach ( $this->required_fields() as $field ) {
				if ( '' === ( $item[ $field ] ?? '' ) ) {
					$problems[] = sprintf(
						/* translators: 1: the position of the item in the feed, 2: what is missing, such as "a thumbnail". */
						__( 'Item %1$d has no %2$s.', 'estasoft-news-distribution' ),
						$index + 1,
						$names[ $field ] ?? $field
					);
				}
			}
		}

		return array(
			'valid'    => $read['valid'] && array() === $problems,
			'entries'  => count( $read['items'] ),
			'problems' => array_slice( array_values( array_unique( $problems ) ), 0, 10 ),
		);
	}

	/**
	 * The plugin's own check of the feeds. It builds them and reads them back;
	 * nothing is sent anywhere.
	 *
	 * @return array{ok: bool, code: int, message: string}
	 */
	public function test(): array {
		return PartnerFeedCheck::run( $this, Plugin::instance() );
	}

	/**
	 * A channel that waits for a partner does not need attention just because
	 * the partner has not fetched yet.
	 *
	 * @return string
	 */
	public function health(): string {
		$health = parent::health();

		if ( Status::ATTENTION !== $health ) {
			return $health;
		}

		$codes = array_unique( array_column( $this->fetch_states(), 'status' ) );

		if ( array( Status::NEVER ) === array_values( $codes ) && Status::LISTED !== $this->context->applications->get( $this->id() )['status'] ) {
			return Status::PROCESSING;
		}

		return $health;
	}

	/**
	 * How the platform has fetched each language's feed: the state, when it last
	 * fetched and how many fetches the last 24 hours had.
	 *
	 * @return array<string, array{status: string, last_ts: int, fetches_24h: int, agent: string}> Language => state.
	 */
	public function fetch_report(): array {
		$agents = array_values( array_filter( array_map( 'strval', (array) ( $this->context->rules->pack( $this->id() )['fetch']['agents'] ?? array() ) ) ) );

		return FetchSummary::states(
			array( $this->id() ),
			$agents,
			$this->carried_languages(),
			$this->context->rules->fetch_interval_hours( $this->id() )
		);
	}

	/**
	 * The language codes this feed is built for, in the order the site lists them.
	 *
	 * @return string[]
	 */
	public function carried_languages(): array {
		$out = array();

		foreach ( $this->context->languages->languages() as $language ) {
			if ( $this->accepts_language( (string) $language ) ) {
				$out[] = (string) $language;
			}
		}

		return $out;
	}

	/**
	 * The words about the application and the latest check and fetch, for the hub.
	 *
	 * @return string[] Sentences.
	 */
	protected function summary_parts(): array {
		$parts = array();
		$row   = $this->context->applications->get( $this->id() );
		$code  = $this->context->applications->display_status( $this->id() );

		if ( Status::NOT_REQUESTED === $code ) {
			$parts[] = sprintf(
				/* translators: %s: an application status such as "Not requested". */
				__( 'Application: %s.', 'estasoft-news-distribution' ),
				Status::word( $code )
			);
		} elseif ( '' !== $row['applied_on'] && Status::LISTED === $code ) {
			$parts[] = sprintf(
				/* translators: 1: an application status such as "Listed", 2: the date the person applied, such as "12 Sep". */
				__( '%1$s (applied %2$s).', 'estasoft-news-distribution' ),
				Status::word( $code ),
				$this->short_date( $row['applied_on'] )
			);
		} else {
			$parts[] = Status::word( $code ) . '.';
		}

		$test = (array) ( $this->settings()['last_test'] ?? array() );

		if ( isset( $test['items'] ) ) {
			$parts[] = sprintf(
				/* translators: 1: items in the feeds, 2: articles left out. */
				__( '%1$s items, %2$s skipped.', 'estasoft-news-distribution' ),
				number_format_i18n( (int) $test['items'] ),
				number_format_i18n( (int) ( $test['skipped_total'] ?? 0 ) )
			);
		}

		$last = 0;

		foreach ( $this->fetch_states() as $state ) {
			$last = max( $last, (int) $state['last_ts'] );
		}

		if ( $last > 0 ) {
			$parts[] = sprintf(
				/* translators: %s: how long ago, such as "4 mins". */
				__( 'Fetched %s ago.', 'estasoft-news-distribution' ),
				human_time_diff( $last, Clock::now() )
			);
		} elseif ( $this->is_enabled() ) {
			$parts[] = __( 'Not fetched yet.', 'estasoft-news-distribution' );
		}

		return $parts;
	}

	/**
	 * A day and month from a Y-m-d date, such as "12 Sep".
	 *
	 * @param string $date A date in Y-m-d form.
	 * @return string
	 */
	protected function short_date( string $date ): string {
		$stamp = strtotime( $date . ' 12:00:00 UTC' );

		return false === $stamp ? $date : wp_date( 'j M', $stamp, new \DateTimeZone( 'UTC' ) );
	}
}
