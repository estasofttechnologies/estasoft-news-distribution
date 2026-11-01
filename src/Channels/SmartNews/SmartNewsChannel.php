<?php
/**
 * SmartNews feed channel.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\SmartNews;

use Estasoft\NewsDistribution\Channels\AbstractChannel;
use Estasoft\NewsDistribution\Channels\AppleNews\FeedBody;
use Estasoft\NewsDistribution\Channels\ChannelImage;
use Estasoft\NewsDistribution\Channels\Group;
use Estasoft\NewsDistribution\Channels\ValidationResult;
use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Bylines;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * One SmartFormat feed per language at `/feed/smartnews/?lang=xx`: RSS 2.0 with
 * the content, dc, media and snf namespaces, the full text and the lead image in
 * content:encoded, a thumbnail and an author on every item, and a short-lived
 * marker for each article that was unpublished or deleted.
 *
 * The thumbnail and the lead image are the copy the image presets made for this channel (a
 * long edge of the size in the rule pack) when the photo has one, and the original otherwise;
 * the `media:thumbnail` carries the copy's own width and height. The lead image the plugin
 * adds to the text has a `figcaption` (the photo's caption, with its credit after it when
 * "Put the credit in the caption" is on), because the text already allows that element.
 * `media:thumbnail` has no place for a credit, so none is written there.
 *
 * SmartNews decides whether to list a publication, and News Distribution cannot
 * see its answer, so the application is a status the owner sets by hand
 * (Core\Applications). The rules and where each one comes from are in
 * rules/smartnews.json: most are News Distribution's own habits, and the
 * namespace and the deletion marker are written from public descriptions of
 * SmartFormat because its specification could not be fetched.
 */
final class SmartNewsChannel extends AbstractChannel {

	public function id(): string {
		return 'smartnews';
	}

	public function label(): string {
		return __( 'SmartNews', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return Group::PARTNER;
	}

	public function order(): int {
		return 40;
	}

	public function mode(): string {
		return self::MODE_PULLED;
	}

	public function content_type(): string {
		return 'application/rss+xml; charset=UTF-8';
	}

	public function gate_note(): string {
		return __( 'A featured image, an author and a logo for the feed.', 'estasoft-news-distribution' );
	}

	public function feed_window(): array {
		return array(
			'max_age' => (int) $this->context->rules->limit( $this->id(), 'window_hours', 168 ) * HOUR_IN_SECONDS,
			'limit'   => (int) $this->context->rules->limit( $this->id(), 'feed_items', 100 ),
		);
	}

	public function fields(): array {
		return array(
			'logo_id'         => array(
				'type'    => 'int',
				'default' => 0,
				'min'     => 0,
				'label'   => __( 'Logo', 'estasoft-news-distribution' ),
				'help'    => __( 'The image SmartNews shows above every article.', 'estasoft-news-distribution' ),
			),
			'dark_logo_id'    => array(
				'type'    => 'int',
				'default' => 0,
				'min'     => 0,
				'label'   => __( 'Dark-mode logo', 'estasoft-news-distribution' ),
				'help'    => __( 'Shown instead of the logo when the reader uses dark mode. SmartNews recommends adding one.', 'estasoft-news-distribution' ),
			),
			'description'     => array(
				'type'    => 'string',
				'default' => '',
				'max'     => 200,
				'label'   => __( 'Site description', 'estasoft-news-distribution' ),
				'help'    => __( 'The short line in the feed that describes your publication.', 'estasoft-news-distribution' ),
			),
			'refresh_minutes' => array(
				'type'    => 'enum',
				'default' => '15',
				'label'   => __( 'Refresh hint', 'estasoft-news-distribution' ),
				'options' => array(
					'1'  => __( '1 minute', 'estasoft-news-distribution' ),
					'5'  => __( '5 minutes', 'estasoft-news-distribution' ),
					'15' => __( '15 minutes', 'estasoft-news-distribution' ),
					'30' => __( '30 minutes', 'estasoft-news-distribution' ),
					'60' => __( '1 hour', 'estasoft-news-distribution' ),
				),
				'help'    => __( 'News Distribution writes this into the feed as a hint. SmartNews decides how often it fetches, so this is not a promise. 1 minute adds load to your server. Technical name: ttl.', 'estasoft-news-distribution' ),
			),
		);
	}

	public function validate( Article $article ): ValidationResult {
		$result = new ValidationResult();
		$title  = trim( wp_specialchars_decode( $article->title(), ENT_QUOTES ) );

		if ( '' === $title ) {
			$result->add( $this->severity( 'title.missing', Status::BLOCK ), 'title.missing', __( 'The article has no title.', 'estasoft-news-distribution' ) );
		}

		if ( '' === $article->permalink() ) {
			$result->add( $this->severity( 'url.missing', Status::BLOCK ), 'url.missing', __( 'The article has no public URL.', 'estasoft-news-distribution' ) );
		}

		if ( ! FeedBody::has_text( $article->content() ) ) {
			$result->add( $this->severity( 'body.empty', Status::BLOCK ), 'body.empty', __( 'The article has no text, so there is nothing to send to SmartNews.', 'estasoft-news-distribution' ) );
		}

		if ( '' === $article->thumbnail_url() ) {
			$result->add(
				$this->severity( 'item.thumbnail', Status::BLOCK ),
				'thumbnail.missing',
				__( 'The article has no featured image. Every SmartNews item carries a thumbnail, so this article is left out of the feed.', 'estasoft-news-distribution' )
			);
		} elseif ( $this->is_small( $article ) ) {
			$result->add(
				$this->severity( 'thumbnail.size', Status::WARN ),
				'thumbnail.small',
				sprintf(
					/* translators: %s: a size in pixels such as 1,500. */
					__( 'The featured image is under %s px on its long edge. The article stays in the feed.', 'estasoft-news-distribution' ),
					number_format_i18n( $this->minimum_edge() )
				)
			);
		}

		if ( '' === $this->author( $article ) ) {
			$result->add( $this->severity( 'item.author', Status::INFO ), 'author.missing', __( 'No author name could be found, so the item has no author.', 'estasoft-news-distribution' ) );
		}

		return $result;
	}

	public function build( array $articles, string $language, ?int $now = null ): string {
		$now      = null === $now ? Clock::now() : $now;
		$entries  = $this->entries( $articles, $now );
		$removed  = $this->removed( $entries, $language, $now );
		$settings = $this->settings();
		$name     = FeedBody::xml( $this->context->settings->publication_name( $language ) );
		$about    = trim( (string) $settings['description'] );
		$newest   = 0;
		$ids      = Plugin::instance()->article_ids();

		if ( '' === $about ) {
			$about = trim( (string) get_bloginfo( 'description' ) );
		}

		if ( '' === $about ) {
			$about = $this->context->settings->publication_name( $language );
		}

		foreach ( $entries as $article ) {
			$newest = max( $newest, $article->modified_ts(), $article->published_ts() );
		}

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<rss version="2.0" xmlns:content="' . FeedCheck::NAMESPACES['content'] . '" xmlns:dc="' . FeedCheck::NAMESPACES['dc'] . '" xmlns:media="' . FeedCheck::NAMESPACES['media'] . '" xmlns:snf="' . FeedCheck::NAMESPACES['snf'] . '">' . "\n";
		$xml .= "\t<channel>\n";
		$xml .= "\t\t<title>{$name}</title>\n";
		$xml .= "\t\t<link>" . FeedBody::xml( home_url( '/' ) ) . "</link>\n";
		$xml .= "\t\t<description>" . FeedBody::xml( $about ) . "</description>\n";
		$xml .= "\t\t<language>" . FeedBody::xml( FeedBody::language_code( $language ) ) . "</language>\n";
		$xml .= "\t\t<pubDate>" . FeedBody::rfc822( $newest > 0 ? $newest : $now ) . "</pubDate>\n";
		$xml .= "\t\t<ttl>" . (int) $settings['refresh_minutes'] . "</ttl>\n";
		$xml .= $this->logo_xml( 'logo', (int) $settings['logo_id'] );
		$xml .= $this->logo_xml( 'darkModeLogo', (int) $settings['dark_logo_id'] );

		foreach ( $entries as $article ) {
			$guid = $ids->get( $article->id() );
			$perm = '' === $guid ? 'true' : 'false';
			$guid = '' === $guid ? $article->permalink() : $guid;

			$picture = ChannelImage::of( $article, $this->id() );

			$xml .= "\t\t<item>\n";
			$xml .= "\t\t\t<title>" . FeedBody::xml( $article->title() ) . "</title>\n";
			$xml .= "\t\t\t<link>" . FeedBody::xml( FeedBody::canonical( $article->permalink() ) ) . "</link>\n";
			$xml .= "\t\t\t<guid isPermaLink=\"{$perm}\">" . FeedBody::xml( $guid ) . "</guid>\n";
			$xml .= "\t\t\t<pubDate>" . FeedBody::rfc822( $article->published_ts() ) . "</pubDate>\n";
			$xml .= "\t\t\t<dc:creator>" . FeedBody::xml( $this->author( $article ) ) . "</dc:creator>\n";

			foreach ( $article->categories() as $category ) {
				$xml .= "\t\t\t<category>" . FeedBody::xml( $category ) . "</category>\n";
			}

			$xml .= "\t\t\t<description>" . FeedBody::xml( FeedBody::description( $article ) ) . "</description>\n";
			$xml .= "\t\t\t<content:encoded>" . FeedBody::cdata( $this->body( $article ) ) . "</content:encoded>\n";
			$xml .= "\t\t\t<media:thumbnail url=\"" . FeedBody::xml( $picture['url'] ) . '" width="' . $picture['width'] . '" height="' . $picture['height'] . "\" />\n";
			$xml .= "\t\t</item>\n";
		}

		foreach ( $removed as $row ) {
			$guid = '' === $row['guid'] ? $row['url'] : $row['guid'];
			$perm = '' === $row['guid'] ? 'true' : 'false';

			$xml .= "\t\t<item>\n";
			$xml .= "\t\t\t<title>" . FeedBody::xml( '' === $row['title'] ? $row['url'] : $row['title'] ) . "</title>\n";
			$xml .= "\t\t\t<link>" . FeedBody::xml( FeedBody::canonical( $row['url'] ) ) . "</link>\n";
			$xml .= "\t\t\t<guid isPermaLink=\"{$perm}\">" . FeedBody::xml( $guid ) . "</guid>\n";
			$xml .= "\t\t\t<pubDate>" . FeedBody::rfc822( $row['at'] ) . "</pubDate>\n";
			$xml .= "\t\t\t<media:status state=\"deleted\" />\n";
			$xml .= "\t\t</item>\n";
		}

		return $xml . "\t</channel>\n</rss>\n";
	}

	/**
	 * The plugin's own check of a built feed against the rules in the pack.
	 *
	 * @param string $body     The document build() returned.
	 * @param string $language Language code.
	 * @return array{valid: bool, entries: int, problems: string[], warnings: string[]}
	 */
	public function check_output( string $body, string $language ): array {
		unset( $language );

		$check = $this->inspect( $body );

		return array(
			'valid'    => $check['valid'],
			'entries'  => $check['entries'],
			'problems' => $check['problems'],
			'warnings' => $check['warnings'],
		);
	}

	/**
	 * Reads a built feed back and reports what it holds.
	 *
	 * @param string $body The document build() returned.
	 * @return array{valid: bool, entries: int, problems: string[], warnings: string[], live: int, deleted: int, bytes: int, has_logo: bool, namespaces: bool, utf8: bool, items: array<int, array<string, mixed>>}
	 */
	public function inspect( string $body ): array {
		$rules = $this->context->rules;

		return FeedCheck::run(
			$body,
			$this->minimum_edge(),
			(int) $rules->limit( $this->id(), 'feed_items', 100 ),
			(int) $rules->limit( $this->id(), 'feed_bytes', 1000000 )
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
	 * How many days a removed article stays in the feed as deleted.
	 *
	 * @return int
	 */
	public function deleted_days(): int {
		return max( 1, (int) $this->context->rules->limit( $this->id(), 'deleted_days', 3 ) );
	}

	/**
	 * The shortest long edge of a thumbnail before it is flagged, in pixels.
	 *
	 * @return int
	 */
	public function minimum_edge(): int {
		return (int) $this->context->rules->limit( $this->id(), 'thumbnail_long_edge_min', 1500 );
	}

	/**
	 * While the application is open, the pill shows the status the owner set by
	 * hand (News Distribution cannot see SmartNews's answer), or Needs attention
	 * when the reminder date has passed. Once the application is listed or not
	 * recorded, it is the usual fetch health.
	 *
	 * @return string A Status code.
	 */
	public function health(): string {
		if ( ! $this->is_enabled() ) {
			return Status::NOT_SET_UP;
		}

		$status = $this->context->applications->display_status( $this->id() );

		if ( in_array( $status, array( Status::REQUESTED, Status::IN_REVIEW, Status::ATTENTION ), true ) ) {
			return $status;
		}

		return parent::health();
	}

	/**
	 * Articles that belong in the feed: in the window, not in the future, and
	 * not blocked by a rule (no thumbnail, no text). Newest first.
	 *
	 * @param Article[] $articles Candidate articles.
	 * @param int       $now      Unix time to treat as now.
	 * @return Article[]
	 */
	public function entries( array $articles, int $now ): array {
		$window = $this->feed_window();
		$cutoff = $now - $window['max_age'];
		$keep   = array();

		foreach ( $articles as $article ) {
			if ( ! $article instanceof Article || $article->published_ts() < $cutoff || $article->published_ts() > $now ) {
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
				/* translators: 1: an application status such as In review, 2: a date such as 5 Oct. */
				__( '%1$s since %2$s', 'estasoft-news-distribution' ),
				Status::word( $row['status'] ),
				$this->short_date( $row['applied_on'] )
			);
		}

		return __( 'One SmartFormat feed for each language.', 'estasoft-news-distribution' );
	}

	/**
	 * The markers of removed articles that belong in a feed: not for an article
	 * that is in the feed again, and none for a language the feed does not carry.
	 *
	 * @param Article[] $entries  The live entries.
	 * @param string    $language Language code.
	 * @param int       $now      Unix time to treat as now.
	 * @return array<int, array{post_id: int, url: string, title: string, language: string, guid: string, at: int}>
	 */
	private function removed( array $entries, string $language, int $now ): array {
		$live = array();

		foreach ( $entries as $article ) {
			$live[ $article->id() ] = true;
		}

		return array_values(
			array_filter(
				Removals::active( $language, $now, $this->deleted_days() ),
				static function ( array $row ) use ( $live ): bool {
					return ! isset( $live[ $row['post_id'] ] );
				}
			)
		);
	}

	/**
	 * The lead image and the cleaned text of an article, as content:encoded carries them.
	 *
	 * @param Article $article The article.
	 * @return string
	 */
	private function body( Article $article ): string {
		$html    = FeedBody::html( $article->content(), true );
		$thumb   = $article->thumbnail_url();
		$picture = ChannelImage::of( $article, $this->id() );

		// The text may already carry the picture, as the original or as this channel's copy.
		if ( '' === $thumb || false !== strpos( $html, $thumb ) || false !== strpos( $html, $picture['url'] ) ) {
			return $html;
		}

		return ChannelImage::figure( $picture['url'], '', ChannelImage::credit( $article )['line'] ) . "\n" . $html;
	}

	/**
	 * A logo element in the snf namespace, or nothing when no image is chosen.
	 *
	 * @param string $element The element name: logo or darkModeLogo.
	 * @param int    $id      The Media Library attachment id.
	 * @return string
	 */
	private function logo_xml( string $element, int $id ): string {
		$url = $id > 0 ? (string) wp_get_attachment_url( $id ) : '';

		if ( '' === $url ) {
			return '';
		}

		return "\t\t<snf:{$element}>\n\t\t\t<url>" . FeedBody::xml( $url ) . "</url>\n\t\t</snf:{$element}>\n";
	}

	/**
	 * Whether the featured image is under the long-edge threshold.
	 *
	 * @param Article $article The article.
	 * @return bool
	 */
	private function is_small( Article $article ): bool {
		$edge = max( $article->thumbnail_width(), $article->thumbnail_height() );

		return $edge > 0 && $edge < $this->minimum_edge();
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
	 * A date as "5 Oct".
	 *
	 * @param string $date A date in Y-m-d form.
	 * @return string
	 */
	private function short_date( string $date ): string {
		$time = strtotime( $date . ' 12:00:00 UTC' );

		return false === $time ? $date : gmdate( 'j M', $time );
	}
}
