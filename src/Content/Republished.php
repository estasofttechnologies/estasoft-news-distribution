<?php
/**
 * Republished content: which articles are copies from another source, and what to do about them.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Content;

use Estasoft\NewsDistribution\Admin\Check\ArticleOptions;
use Estasoft\NewsDistribution\Channels\ChannelInterface;
use Estasoft\NewsDistribution\Channels\Group;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Get it with `Plugin::instance()->make( Republished::class )`.
 *
 * An article is republished content when its options say so (`republished`, set by the
 * editor panel) or when its content label is Syndicated. Where the copy came from is
 * kept in its own post meta, `_estasoft_nd_source`, and not inside `_estasoft_nd_options`,
 * because that meta's cleaner would strip percent-encoded characters from a web address:
 *
 *     array(
 *       'source'   => 'example-wire-service', // the id of a source in the settings, or ''
 *       'name'     => 'Example Wire Service', // used when there is no configured source
 *       'url'      => 'https://wire.example.com/2026/harbor-festival', // the original
 *       'handling' => '',                     // '' follows the default, or one of HANDLINGS
 *     )
 *
 * The handling decides what the page says to search engines. It also decides what the
 * plugin leaves out of its own channels (see skip_reason()). Nothing here calls another server.
 */
final class Republished {

	public const META = '_estasoft_nd_source';

	public const NOINDEX      = 'noindex';
	public const NEWS_NOINDEX = 'news_noindex';
	public const CANONICAL    = 'canonical';
	public const OWN          = 'own';

	public const HANDLINGS = array( self::NOINDEX, self::NEWS_NOINDEX, self::CANONICAL, self::OWN );

	private Plugin $plugin;

	/**
	 * Creates the service.
	 *
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * The four ways to handle a copy, with the wording the screens use.
	 *
	 * `label` is the short name, `title` the line on a radio button, `help` the line under it
	 * in Settings and `editor_help` the line under it in the editor, which may be empty.
	 *
	 * @return array<string, array{label: string, title: string, help: string, editor_help: string}>
	 */
	public static function handling_options(): array {
		return array(
			self::NOINDEX      => array(
				'label'       => __( 'Keep out of search', 'estasoft-news-distribution' ),
				'title'       => __( 'Keep it out of search', 'estasoft-news-distribution' ),
				'help'        => __( 'Adds a noindex tag, so Google and others do not list your copy. Readers on your site still see it. Google’s advice for syndicated content is to block indexing of the copy.', 'estasoft-news-distribution' ),
				'editor_help' => __( 'Default. Adds a noindex tag.', 'estasoft-news-distribution' ),
			),
			self::NEWS_NOINDEX => array(
				'label'       => __( 'Keep out of Google News only', 'estasoft-news-distribution' ),
				'title'       => __( 'Keep it out of Google News only', 'estasoft-news-distribution' ),
				'help'        => __( 'Adds a Googlebot-News noindex tag. Your copy can still appear in regular search.', 'estasoft-news-distribution' ),
				'editor_help' => '',
			),
			self::CANONICAL    => array(
				'label'       => __( 'Point to the source', 'estasoft-news-distribution' ),
				'title'       => __( 'Point to the source', 'estasoft-news-distribution' ),
				'help'        => __( 'Adds a canonical link to the original. Google does not recommend this for syndicated content, because the pages often differ, so use it only when the source asks for it.', 'estasoft-news-distribution' ),
				'editor_help' => __( 'Google does not recommend this for syndicated content.', 'estasoft-news-distribution' ),
			),
			self::OWN          => array(
				'label'       => __( 'Keep my own canonical', 'estasoft-news-distribution' ),
				'title'       => __( 'Keep my own canonical', 'estasoft-news-distribution' ),
				'help'        => __( 'For articles you hold the rights to and want to rank. Choose this only when it is true.', 'estasoft-news-distribution' ),
				'editor_help' => __( 'Only if you hold the rights.', 'estasoft-news-distribution' ),
			),
		);
	}

	/**
	 * The settings of this feature.
	 *
	 * @return array<string, mixed>
	 */
	public function settings(): array {
		return $this->plugin->settings()->section( 'republished' );
	}

	/**
	 * The sources the site uses, in the order they were added.
	 *
	 * @return array<int, array{id: string, name: string, handling: string, site: string}>
	 */
	public function sources(): array {
		$list = $this->settings()['sources'] ?? array();
		$out  = array();

		foreach ( is_array( $list ) ? $list : array() as $row ) {
			if ( ! is_array( $row ) || '' === trim( (string) ( $row['name'] ?? '' ) ) ) {
				continue;
			}

			$out[] = array(
				'id'       => (string) ( $row['id'] ?? '' ),
				'name'     => (string) $row['name'],
				'handling' => (string) ( $row['handling'] ?? '' ),
				'site'     => (string) ( $row['site'] ?? '' ),
			);
		}

		return $out;
	}

	/**
	 * One configured source.
	 *
	 * @param string $id Source id.
	 * @return array{id: string, name: string, handling: string, site: string}|null
	 */
	public function source( string $id ): ?array {
		if ( '' === $id ) {
			return null;
		}

		foreach ( $this->sources() as $source ) {
			if ( $source['id'] === $id ) {
				return $source;
			}
		}

		return null;
	}

	/**
	 * The handling the site uses when an article and its source say nothing.
	 *
	 * @return string One of HANDLINGS.
	 */
	public function default_handling(): string {
		$value = (string) ( $this->settings()['handling'] ?? self::NOINDEX );

		return in_array( $value, self::HANDLINGS, true ) ? $value : self::NOINDEX;
	}

	/**
	 * What was written down about where one article came from, with every key present.
	 *
	 * @param int $post_id Article ID.
	 * @return array{source: string, name: string, url: string, handling: string}
	 */
	public function details( int $post_id ): array {
		$stored = get_post_meta( $post_id, self::META, true );
		$stored = is_array( $stored ) ? $stored : array();

		return self::clean_details( $stored );
	}

	/**
	 * Cleans the details of a source on their way to storage.
	 *
	 * The address is kept whole: it is checked as an address and never run through a text cleaner
	 * that would strip percent-encoded characters from it.
	 *
	 * @param mixed $value What was given.
	 * @return array{source: string, name: string, url: string, handling: string}
	 */
	public static function clean_details( $value ): array {
		$value    = is_array( $value ) ? $value : array();
		$handling = isset( $value['handling'] ) && is_scalar( $value['handling'] ) ? (string) $value['handling'] : '';
		$url      = isset( $value['url'] ) && is_scalar( $value['url'] ) ? trim( (string) $value['url'] ) : '';

		return array(
			'source'   => isset( $value['source'] ) && is_scalar( $value['source'] ) ? sanitize_key( (string) $value['source'] ) : '',
			'name'     => isset( $value['name'] ) && is_scalar( $value['name'] ) ? mb_substr( sanitize_text_field( (string) $value['name'] ), 0, 120 ) : '',
			'url'      => self::valid_url( $url ) ? esc_url_raw( $url, array( 'http', 'https' ) ) : '',
			'handling' => in_array( $handling, self::HANDLINGS, true ) ? $handling : '',
		);
	}

	/**
	 * Whether text is a full web address with an http or https scheme and a host.
	 *
	 * @param string $url The text.
	 * @return bool
	 */
	public static function valid_url( string $url ): bool {
		if ( '' === $url || mb_strlen( $url ) > 2000 ) {
			return false;
		}

		$parts = wp_parse_url( $url );

		return is_array( $parts )
			&& isset( $parts['scheme'], $parts['host'] )
			&& in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true )
			&& '' !== (string) $parts['host'];
	}

	/**
	 * Whether the article is republished content: marked in the editor, or labeled Syndicated.
	 *
	 * @param int $post_id Article ID.
	 * @return bool
	 */
	public function is_republished( int $post_id ): bool {
		$options = ArticleOptions::get( $post_id );

		return ! empty( $options['republished'] ) || 'syndicated' === ( $options['content_label'] ?? '' );
	}

	/**
	 * The name of the source of an article: the configured source's name, else the one typed.
	 *
	 * @param int $post_id Article ID.
	 * @return string Empty when no source was chosen.
	 */
	public function source_name( int $post_id ): string {
		$details = $this->details( $post_id );
		$source  = $this->source( $details['source'] );

		return null !== $source ? $source['name'] : $details['name'];
	}

	/**
	 * What applies to one article that is republished content, or null when it is not.
	 *
	 * `handling` is what is done in the end. `chosen` is the handling the article or its source asked
	 * for. They differ only when the copy was to point to the source and no valid address was
	 * given: the page is kept out of search instead, so it never goes out unprotected, and
	 * `degraded` is true.
	 *
	 * @param int $post_id Article ID.
	 * @return array{handling: string, chosen: string, degraded: bool, source: string, name: string, url: string}|null
	 */
	public function policy( int $post_id ): ?array {
		if ( ! $this->is_republished( $post_id ) ) {
			return null;
		}

		$details = $this->details( $post_id );
		$source  = $this->source( $details['source'] );
		$chosen  = '' !== $details['handling'] ? $details['handling'] : ( null !== $source && '' !== $source['handling'] ? $source['handling'] : $this->default_handling() );
		$final   = $chosen;

		if ( self::CANONICAL === $chosen && '' === $details['url'] ) {
			$final = self::NOINDEX;
		}

		return array(
			'handling' => $final,
			'chosen'   => $chosen,
			'degraded' => $final !== $chosen,
			'source'   => $details['source'],
			'name'     => null !== $source ? $source['name'] : $details['name'],
			'url'      => $details['url'],
		);
	}

	/**
	 * The attribution line for an article: the line of its language, else English, with the source's name put in.
	 *
	 * @param string $name     The source's name.
	 * @param string $language Language code of the article.
	 * @return string Plain text, or an empty string when there is no name.
	 */
	public function attribution( string $name, string $language ): string {
		if ( '' === trim( $name ) ) {
			return '';
		}

		$lines = $this->settings()['attribution'] ?? array();
		$lines = is_array( $lines ) ? $lines : array();
		$short = strtolower( (string) strtok( $language, '-_' ) );
		$line  = '';

		foreach ( array( $language, $short, 'en' ) as $code ) {
			if ( '' !== $code && isset( $lines[ $code ] ) && '' !== trim( (string) $lines[ $code ] ) ) {
				$line = (string) $lines[ $code ];
				break;
			}
		}

		if ( '' === $line ) {
			/* translators: %s: the name of the source of a republished article. */
			return sprintf( __( 'Source: %s', 'estasoft-news-distribution' ), $name );
		}

		return str_replace( '{source}', $name, $line );
	}

	/**
	 * Why one channel leaves a republished article out, or an empty string when it carries it.
	 *
	 * Partner feeds and outlets follow the two switches in Settings. The Google News sitemap is
	 * always left out while the handling is noindex or Google News only, whatever the switch says.
	 * The other search sitemaps leave out a noindex copy. Open readers, the pings and the site itself
	 * are not affected.
	 *
	 * @param array{handling: string} $policy  The article's policy.
	 * @param ChannelInterface        $channel The channel.
	 * @return string A sentence for the delivery log, or an empty string.
	 */
	public function skip_reason( array $policy, ChannelInterface $channel ): string {
		$settings = $this->settings();
		$handling = $policy['handling'];
		$id       = $channel->id();
		$noindex  = in_array( $handling, array( self::NOINDEX, self::NEWS_NOINDEX ), true );

		if ( 'outlets' === $id && ! empty( $settings['exclude_outlets'] ) ) {
			return __( 'Skipped: republished content is kept out of outlets and social posts (Settings, Republished content).', 'estasoft-news-distribution' );
		}

		if ( Group::PARTNER === $channel->group() && ! empty( $settings['exclude_partner'] ) ) {
			return __( 'Skipped: republished content is kept out of partner feeds (Settings, Republished content).', 'estasoft-news-distribution' );
		}

		if ( 'google-news' === $id && ( $noindex || ! empty( $settings['exclude_news'] ) ) ) {
			return __( 'Skipped: republished content is kept out of the Google News sitemap (Settings, Republished content).', 'estasoft-news-distribution' );
		}

		if ( Group::SITEMAP === $channel->group() && 'google-news' !== $id && ChannelInterface::MODE_PULLED === $channel->mode() && self::NOINDEX === $handling ) {
			return __( 'Skipped: a republished article that is kept out of search is not listed in the search sitemaps.', 'estasoft-news-distribution' );
		}

		return '';
	}

	/**
	 * What would happen to an article with a given policy: what is added to the page and where it is skipped.
	 *
	 * Used by the editor's Preview. Only channels that are switched on are named.
	 *
	 * @param array{handling: string, name: string, url: string} $policy   The policy to preview.
	 * @param string                                             $language Language code of the article.
	 * @return array{added: array{code: string, note: string}, skipped: string[], notes: string[], still_on: string[]}
	 */
	public function preview( array $policy, string $language ): array {
		$code = '';
		$note = '';

		switch ( $policy['handling'] ) {
			case self::NOINDEX:
				$code = '<meta name="robots" content="noindex">';
				break;
			case self::NEWS_NOINDEX:
				$code = '<meta name="Googlebot-News" content="noindex, nofollow">';
				$note = __( 'The copy can still appear in regular search.', 'estasoft-news-distribution' );
				break;
			case self::CANONICAL:
				$code = '<link rel="canonical" href="' . ( '' !== $policy['url'] ? $policy['url'] : '…' ) . '">';
				break;
			default:
				$note = __( 'Nothing: your own canonical link stays as it is.', 'estasoft-news-distribution' );
		}

		$line = $this->attribution( $policy['name'], $language );

		if ( '' !== $line ) {
			$note = trim(
				$note . ' ' . sprintf(
					/* translators: %s: the attribution line, such as "Source: Example Wire Service". */
					__( 'And, at the end of the article: “%s”.', 'estasoft-news-distribution' ),
					$line
				)
			);
		}

		$partners     = array();
		$in_review    = array();
		$outlets      = false;
		$search       = false;
		$applications = $this->plugin->applications();

		foreach ( $this->plugin->registry()->enabled() as $channel ) {
			if ( '' === $this->skip_reason( $policy, $channel ) ) {
				continue;
			}

			if ( 'outlets' === $channel->id() ) {
				$outlets = true;
			} elseif ( Group::PARTNER === $channel->group() ) {
				if ( Status::IN_REVIEW === $applications->display_status( $channel->id() ) ) {
					$in_review[] = $channel->label();
				} else {
					$partners[] = $channel->label();
				}
			} else {
				$search = true;
			}
		}

		$skipped = array();
		$notes   = array();

		if ( $search ) {
			$skipped[] = __( 'Search sitemaps and the news sitemap', 'estasoft-news-distribution' );
		}

		if ( array() !== $partners ) {
			$skipped[] = sprintf(
				/* translators: %s: names of partner feeds, such as "Microsoft Start, SmartNews". */
				__( 'Partner feeds: %s', 'estasoft-news-distribution' ),
				implode( ', ', $partners )
			);
		}

		if ( $outlets ) {
			$skipped[] = __( 'Outlets and social posts', 'estasoft-news-distribution' );
		}

		foreach ( $in_review as $label ) {
			$notes[] = sprintf(
				/* translators: %s: the name of a platform, such as "Apple News". */
				__( '%s is In review: this article will not be sent to it when it goes live', 'estasoft-news-distribution' ),
				$label
			);
		}

		return array(
			'added'    => array(
				'code' => $code,
				'note' => $note,
			),
			'skipped'  => $skipped,
			'notes'    => $notes,
			'still_on' => array( __( 'Your site, and your feed for open readers', 'estasoft-news-distribution' ) ),
		);
	}
}
