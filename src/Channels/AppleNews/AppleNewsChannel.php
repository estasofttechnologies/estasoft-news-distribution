<?php
/**
 * Apple News feed channel.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\AppleNews;

use Estasoft\NewsDistribution\Channels\AbstractChannel;
use Estasoft\NewsDistribution\Channels\Group;
use Estasoft\NewsDistribution\Channels\ValidationResult;
use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Bylines;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * One RSS 2.0 feed per language at `/feed/apple-news/?lang=xx`, with the full
 * text of each article in content:encoded and an atom:link to the feed's own
 * address. The owner adds the feed addresses in Apple's News Publisher and
 * Apple decides; News Distribution cannot see Apple's answer, so the
 * application is a status the owner sets by hand (Core\Applications).
 *
 * Apple's guide recommends titles of 15 to 60 characters and says there is no
 * minimum, so the title check only warns. Apple reads the author, thumbnail and
 * language from the article pages; the screen checks those tags on request
 * (see PageTags). The rules and where each one comes from are in
 * rules/apple-news.json.
 */
final class AppleNewsChannel extends AbstractChannel {

	/**
	 * Title length Apple recommends, used when the rule pack has no figure.
	 */
	public const TITLE_MIN = 15;

	/**
	 * Title length Apple recommends, used when the rule pack has no figure.
	 */
	public const TITLE_MAX = 60;

	public function id(): string {
		return 'apple-news';
	}

	public function label(): string {
		return __( 'Apple News', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return Group::PARTNER;
	}

	public function order(): int {
		return 30;
	}

	public function mode(): string {
		return self::MODE_PULLED;
	}

	public function content_type(): string {
		return 'application/rss+xml; charset=UTF-8';
	}

	public function gate_note(): string {
		return __( 'Title length, a featured image and the tags Apple reads on your pages.', 'estasoft-news-distribution' );
	}

	public function feed_window(): array {
		return array(
			'max_age' => (int) $this->context->rules->limit( $this->id(), 'window_hours', 168 ) * HOUR_IN_SECONDS,
			'limit'   => (int) $this->context->rules->limit( $this->id(), 'max_items', 100 ),
		);
	}

	public function fields(): array {
		return array(
			'warn_title_length' => array(
				'type'    => 'bool',
				'default' => true,
				'control' => 'checkbox',
				'label'   => __( 'Warn about titles outside 15 to 60 characters', 'estasoft-news-distribution' ),
				'help'    => __( 'Apple’s guide recommends titles of 15 to 60 characters and says there is no minimum. This only warns; the article stays in the feed.', 'estasoft-news-distribution' ),
			),
			'strip_markup'      => array(
				'type'    => 'bool',
				'default' => true,
				'control' => 'checkbox',
				'label'   => __( 'Remove WordPress markup from the feed', 'estasoft-news-distribution' ),
				'help'    => __( 'The hidden notes WordPress adds around blocks, and shortcodes, never reach Apple.', 'estasoft-news-distribution' ),
			),
			'index_first'       => array(
				'type'    => 'bool',
				'default' => true,
				'control' => 'checkbox',
				'label'   => __( 'Wait for the index-first delay', 'estasoft-news-distribution' ),
				'help'    => $this->delay_help(),
			),
		);
	}

	public function validate( Article $article ): ValidationResult {
		$result   = new ValidationResult();
		$settings = $this->settings();
		$title    = trim( wp_specialchars_decode( $article->title(), ENT_QUOTES ) );

		if ( '' === $title ) {
			$result->add( $this->severity( 'title.missing', Status::BLOCK ), 'title.missing', __( 'The article has no title.', 'estasoft-news-distribution' ) );
		}

		if ( '' === $article->permalink() ) {
			$result->add( $this->severity( 'url.missing', Status::BLOCK ), 'url.missing', __( 'The article has no public URL.', 'estasoft-news-distribution' ) );
		}

		if ( ! FeedBody::has_text( $article->content() ) ) {
			$result->add( $this->severity( 'body.empty', Status::BLOCK ), 'body.empty', __( 'The article has no text, so there is nothing to send to Apple.', 'estasoft-news-distribution' ) );
		}

		if ( '' !== $title && ! empty( $settings['warn_title_length'] ) ) {
			$this->check_title_length( $result, mb_strlen( $title ) );
		}

		if ( '' === $article->thumbnail_url() ) {
			$result->add(
				$this->severity( 'page.image_tag', Status::WARN ),
				'image.missing',
				__( 'The article has no featured image. Apple’s guide names the page image tag (og:image) as the source of the feed thumbnail, and it usually comes from the featured image.', 'estasoft-news-distribution' )
			);
		}

		if ( '' === $this->author( $article ) ) {
			$result->add( $this->severity( 'author.missing', Status::WARN ), 'author.missing', __( 'No author name could be found for the article.', 'estasoft-news-distribution' ) );
		}

		return $result;
	}

	public function build( array $articles, string $language, ?int $now = null ): string {
		$now      = null === $now ? Clock::now() : $now;
		$entries  = $this->entries( $articles, $now );
		$name     = FeedBody::xml( $this->context->settings->publication_name( $language ) );
		$strip    = ! empty( $this->settings()['strip_markup'] );
		$newest   = 0;
		$feed_url = FeedBody::xml( $this->feed_url( $language ) );
		$about    = trim( (string) get_bloginfo( 'description' ) );
		$ids      = Plugin::instance()->article_ids();

		foreach ( $entries as $article ) {
			$newest = max( $newest, $article->modified_ts(), $article->published_ts() );
		}

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/">' . "\n";
		$xml .= "\t<channel>\n";
		$xml .= "\t\t<title>{$name}</title>\n";
		$xml .= "\t\t<link>" . FeedBody::xml( home_url( '/' ) ) . "</link>\n";
		$xml .= "\t\t<description>" . FeedBody::xml( '' === $about ? $this->context->settings->publication_name( $language ) : $about ) . "</description>\n";
		$xml .= "\t\t<language>" . FeedBody::xml( FeedBody::language_code( $language ) ) . "</language>\n";
		$xml .= "\t\t<lastBuildDate>" . FeedBody::rfc822( $newest > 0 ? $newest : $now ) . "</lastBuildDate>\n";
		$xml .= "\t\t<atom:link href=\"{$feed_url}\" rel=\"self\" type=\"application/rss+xml\" />\n";

		foreach ( $entries as $article ) {
			$guid = $ids->get( $article->id() );
			$perm = '' === $guid ? 'true' : 'false';
			$guid = '' === $guid ? $article->permalink() : $guid;

			$xml .= "\t\t<item>\n";
			$xml .= "\t\t\t<title>" . FeedBody::xml( $article->title() ) . "</title>\n";
			$xml .= "\t\t\t<link>" . FeedBody::xml( $article->permalink() ) . "</link>\n";
			$xml .= "\t\t\t<guid isPermaLink=\"{$perm}\">" . FeedBody::xml( $guid ) . "</guid>\n";
			$xml .= "\t\t\t<pubDate>" . FeedBody::rfc822( $article->published_ts() ) . "</pubDate>\n";
			$xml .= "\t\t\t<dc:creator>" . FeedBody::xml( $this->author( $article ) ) . "</dc:creator>\n";

			foreach ( $article->categories() as $category ) {
				$xml .= "\t\t\t<category>" . FeedBody::xml( $category ) . "</category>\n";
			}

			$xml .= "\t\t\t<description>" . FeedBody::xml( FeedBody::description( $article ) ) . "</description>\n";
			$xml .= "\t\t\t<content:encoded>" . FeedBody::cdata( FeedBody::html( $article->content(), $strip ) ) . "</content:encoded>\n";
			$xml .= "\t\t</item>\n";
		}

		return $xml . "\t</channel>\n</rss>\n";
	}

	/**
	 * The plugin's own check of a built feed: RSS 2.0 in UTF-8, the feed's own
	 * address in an atom:link, and a title, link and description on every item.
	 *
	 * @param string $body     The document build() returned.
	 * @param string $language Language code.
	 * @return array{valid: bool, entries: int, problems: string[], warnings: string[]}
	 */
	public function check_output( string $body, string $language ): array {
		unset( $language );

		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( $body );
		$problems = array();
		$warnings = array();
		$entries  = 0;

		if ( false === $xml ) {
			foreach ( libxml_get_errors() as $error ) {
				$problems[] = trim( $error->message );
			}
		} elseif ( 'rss' !== $xml->getName() || '2.0' !== (string) $xml['version'] ) {
			$problems[] = __( 'The feed is not RSS 2.0.', 'estasoft-news-distribution' );
		} else {
			$entries = $this->check_channel( $xml, $problems, $warnings );
		}

		if ( false !== $xml && 1 !== preg_match( '/^<\?xml[^>]*encoding=["\']UTF-8["\']/i', ltrim( $body ) ) ) {
			$problems[] = __( 'The feed does not say it is UTF-8.', 'estasoft-news-distribution' );
		}

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return array(
			'valid'    => false !== $xml && array() === $problems,
			'entries'  => $entries,
			'problems' => array_slice( array_values( array_unique( $problems ) ), 0, 10 ),
			'warnings' => array_values( array_unique( $warnings ) ),
		);
	}

	/**
	 * The address of this feed for a language.
	 *
	 * @param string $language Language code.
	 * @return string
	 */
	public function feed_url( string $language ): string {
		return add_query_arg( 'lang', rawurlencode( $language ), home_url( '/feed/' . $this->feed_slug() . '/' ) );
	}

	/**
	 * Until Apple first fetches the feed, the pill shows the status the owner
	 * set by hand (News Distribution cannot see Apple's answer). After that it
	 * is the usual fetch health.
	 *
	 * @return string A Status code.
	 */
	public function health(): string {
		if ( ! $this->is_enabled() ) {
			return Status::NOT_SET_UP;
		}

		$codes = array_column( $this->fetch_states(), 'status' );

		if ( array() === $codes || array( Status::NEVER ) === array_values( array_unique( $codes ) ) ) {
			return $this->context->applications->display_status( $this->id() );
		}

		return parent::health();
	}

	/**
	 * Articles that belong in the feed: in the window, not in the future, past
	 * the index-first delay when that option is on, and not blocked by a rule.
	 * Newest first.
	 *
	 * @param Article[] $articles Candidate articles.
	 * @param int       $now      Unix time to treat as now.
	 * @return Article[]
	 */
	public function entries( array $articles, int $now ): array {
		$window = $this->feed_window();
		$cutoff = $now - $window['max_age'];
		$wait   = ! empty( $this->settings()['index_first'] ) ? $this->context->settings->index_first_delay_seconds() : 0;
		$keep   = array();

		foreach ( $articles as $article ) {
			if ( ! $article instanceof Article || $article->published_ts() < $cutoff || $article->published_ts() > $now - $wait ) {
				continue;
			}

			if ( $this->validate( $article )->is_valid() ) {
				$keep[] = $article;
			}
		}

		usort(
			$keep,
			static function ( Article $a, Article $b ): int {
				return $b->published_ts() <=> $a->published_ts();
			}
		);

		return array_slice( $keep, 0, $window['limit'] );
	}

	/**
	 * The plain-text line under the channel's name on the hub.
	 *
	 * @return string
	 */
	protected function hub_summary(): string {
		$row = $this->context->applications->get( $this->id() );

		if ( in_array( $row['status'], array( Status::REQUESTED, Status::IN_REVIEW ), true ) && '' !== $row['applied_on'] ) {
			return sprintf(
				/* translators: 1: an application status such as In review, 2: a date such as 28 Sep. */
				__( '%1$s since %2$s', 'estasoft-news-distribution' ),
				Status::word( $row['status'] ),
				$this->short_date( $row['applied_on'] )
			);
		}

		return __( 'One full-text feed for each language.', 'estasoft-news-distribution' );
	}

	/**
	 * Checks the channel part and the items of a parsed feed.
	 *
	 * @param \SimpleXMLElement $xml      The parsed feed.
	 * @param string[]          $problems Problems found so far, added to.
	 * @param string[]          $warnings Warnings found so far, added to.
	 * @return int The number of items.
	 */
	private function check_channel( \SimpleXMLElement $xml, array &$problems, array &$warnings ): int {
		$channel = $xml->channel;

		if ( ! isset( $channel->title ) || '' === trim( (string) $channel->title ) || ! isset( $channel->link ) || ! isset( $channel->description ) ) {
			$problems[] = __( 'The feed needs a title, a link and a description for the publication.', 'estasoft-news-distribution' );
		}

		$self = '';

		foreach ( $channel->children( 'http://www.w3.org/2005/Atom' )->link as $link ) {
			$attributes = $link->attributes();

			if ( null !== $attributes && 'self' === (string) $attributes['rel'] ) {
				$self = (string) $attributes['href'];
			}
		}

		if ( '' === $self ) {
			$problems[] = __( 'The feed has no link to its own address (atom:link with rel="self").', 'estasoft-news-distribution' );
		} elseif ( 0 !== strpos( $self, 'https://' ) ) {
			$warnings[] = __( 'The feed address does not start with https://.', 'estasoft-news-distribution' );
		}

		$count = 0;

		foreach ( $channel->item as $item ) {
			++$count;
			$content = (string) $item->children( 'http://purl.org/rss/1.0/modules/content/' )->encoded;

			if ( '' === trim( (string) $item->title ) || '' === trim( (string) $item->link ) || ( '' === trim( (string) $item->description ) && '' === trim( $content ) ) ) {
				$problems[] = __( 'An item is missing its title, link or description.', 'estasoft-news-distribution' );
			}
		}

		return $count;
	}

	/**
	 * Adds the title-length finding when the title is outside Apple's recommended range.
	 *
	 * @param ValidationResult $result Result to add to.
	 * @param int              $length The title length in characters.
	 * @return void
	 */
	private function check_title_length( ValidationResult $result, int $length ): void {
		$min = (int) $this->context->rules->limit( $this->id(), 'title_recommended_min', self::TITLE_MIN );
		$max = (int) $this->context->rules->limit( $this->id(), 'title_recommended_max', self::TITLE_MAX );
		$sev = $this->severity( 'title.length', Status::WARN );

		if ( $length > $max ) {
			$result->add(
				$sev,
				'title.long',
				sprintf(
					/* translators: 1: the number of characters in the title, 2: the shortest recommended length, 3: the longest recommended length. */
					_n(
						'The title has %1$d character. Apple’s guide recommends titles of %2$d to %3$d characters.',
						'The title has %1$d characters. Apple’s guide recommends titles of %2$d to %3$d characters.',
						$length,
						'estasoft-news-distribution'
					),
					$length,
					$min,
					$max
				)
			);
		} elseif ( $length < $min ) {
			$result->add(
				$sev,
				'title.short',
				sprintf(
					/* translators: 1: the number of characters in the title, 2: the shortest recommended length, 3: the longest recommended length. */
					_n(
						'The title has %1$d character. Apple’s guide recommends titles of %2$d to %3$d characters but sets no minimum, so the article stays in the feed.',
						'The title has %1$d characters. Apple’s guide recommends titles of %2$d to %3$d characters but sets no minimum, so the article stays in the feed.',
						$length,
						'estasoft-news-distribution'
					),
					$length,
					$min,
					$max
				)
			);
		}
	}

	/**
	 * The author name a feed item carries.
	 *
	 * @param Article $article The article.
	 * @return string
	 */
	private function author( Article $article ): string {
		$byline = Plugin::instance()->make( Bylines::class )->resolve( $article );

		return (string) $byline['joined'];
	}

	/**
	 * A rule's severity from the rule pack.
	 *
	 * @param string $rule     Rule code in the pack.
	 * @param string $fallback Severity when the pack has none.
	 * @return string
	 */
	private function severity( string $rule, string $fallback ): string {
		return $this->context->rules->severity( $this->id(), $rule, $fallback );
	}

	/**
	 * The help line under the index-first option.
	 *
	 * @return string
	 */
	private function delay_help(): string {
		$minutes = (int) round( $this->context->settings->index_first_delay_seconds() / MINUTE_IN_SECONDS );

		return sprintf(
			/* translators: %d: minutes, such as 15. */
			_n( 'Articles appear in this feed %d minute after publishing.', 'Articles appear in this feed %d minutes after publishing.', $minutes, 'estasoft-news-distribution' ),
			$minutes
		);
	}

	/**
	 * A date as "28 Sep".
	 *
	 * @param string $date A date in Y-m-d form.
	 * @return string
	 */
	private function short_date( string $date ): string {
		$time = strtotime( $date . ' 12:00:00 UTC' );

		return false === $time ? $date : gmdate( 'j M', $time );
	}
}
