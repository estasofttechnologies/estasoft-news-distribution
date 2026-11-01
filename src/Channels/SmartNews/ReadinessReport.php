<?php
/**
 * The readiness report: what News Distribution's own check found in the SmartNews feeds.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\SmartNews;

use Estasoft\NewsDistribution\Channels\AppleNews\FeedDocument;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Language\Catalog;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * A page the owner can send SmartNews with an application. It lists what the
 * plugin's own check found in the feeds: the publication details, the feed
 * addresses, each rule and whether it passes, and a few real entries. It
 * contains no secrets, no admin screens and no visitor data, and it says in
 * so many words that it is not an approval.
 *
 * `build()` returns plain arrays (no HTML), so the screen, the shared page and
 * the Reports REST route can all use it. Get an instance with
 * `Plugin::instance()->make( ReadinessReport::class )`.
 *
 * The array has these keys:
 *
 *     platform, platform_name, title, publication, generated (Unix time),
 *     rule_pack, summary { total, passed, warnings, problems },
 *     items { total, by_language { code => count } }, languages (codes),
 *     details { name, language_names[], logo { present, width, height, ok },
 *               description { text, length, ok } },
 *     feeds [ { language, url, items, valid, problems[] } ],
 *     checks [ { code, label, status: pass|warning|problem } ],
 *     samples [ { language, title, author, width, height, canonical } ],
 *     contact { name, email }
 */
final class ReadinessReport {

	public const PASS    = 'pass';
	public const WARNING = 'warning';
	public const PROBLEM = 'problem';

	private Plugin $plugin;

	/**
	 * Creates the report builder.
	 *
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Builds the report from the feeds as they are now.
	 *
	 * @param array<string, mixed> $options Optional: `samples` (how many sample entries, default 2),
	 *                                      `contact_name` and `contact_email` (default: the signed-in person).
	 * @return array<string, mixed>
	 */
	public function build( array $options = array() ): array {
		$channel = $this->plugin->registry()->get( 'smartnews' );

		if ( ! $channel instanceof SmartNewsChannel ) {
			return array();
		}

		$feeds     = array();
		$items     = array();
		$counts    = array();
		$languages = array();

		foreach ( $this->plugin->languages()->languages() as $language ) {
			$language = (string) $language;

			if ( ! $channel->accepts_language( $language ) ) {
				continue;
			}

			$languages[]         = $language;
			$check               = $channel->inspect( FeedDocument::body( $this->plugin, $channel, $language ) );
			$counts[ $language ] = $check['live'];
			$feeds[]             = array(
				'language' => $language,
				'url'      => $channel->feed_url( $language ),
				'items'    => $check['live'],
				'valid'    => $check['valid'],
				'problems' => $check['problems'],
				'check'    => $check,
			);

			foreach ( $check['items'] as $item ) {
				$item['language'] = $language;
				$items[]          = $item;
			}
		}

		$checks  = $this->checks( $channel, $feeds, $items );
		$summary = $this->summary( $checks );
		$name    = $this->plugin->settings()->publication_name();
		$details = $this->details( $channel, $languages );

		foreach ( $feeds as $index => $feed ) {
			unset( $feeds[ $index ]['check'] );
		}

		return array(
			'platform'      => 'smartnews',
			'platform_name' => $channel->label(),
			'title'         => sprintf(
				/* translators: 1: the publication name, 2: a platform name such as SmartNews. */
				__( '%1$s: %2$s feed report', 'estasoft-news-distribution' ),
				$name,
				$channel->label()
			),
			'publication'   => $name,
			'generated'     => Clock::now(),
			'rule_pack'     => $this->plugin->rules()->version( 'smartnews' ),
			'summary'       => $summary,
			'items'         => array(
				'total'       => count( $items ),
				'by_language' => $counts,
			),
			'languages'     => $languages,
			'details'       => $details,
			'feeds'         => $feeds,
			'checks'        => $checks,
			'samples'       => $this->samples( $items, max( 0, (int) ( $options['samples'] ?? 2 ) ) ),
			'contact'       => $this->contact( $options ),
		);
	}

	/**
	 * A language's name as a sentence reads it. Kurmanji is shown as Kurdish (Kurmanji),
	 * or as Kurdish when the short form is asked for.
	 *
	 * @param string $code Language code.
	 * @param bool   $long Whether to add the variety, as in "Kurdish (Kurmanji)".
	 * @return string
	 */
	public static function language_label( string $code, bool $long = true ): string {
		if ( 'ku' === Catalog::primary( $code ) ) {
			return $long ? __( 'Kurdish (Kurmanji)', 'estasoft-news-distribution' ) : __( 'Kurdish', 'estasoft-news-distribution' );
		}

		return Catalog::name( $code );
	}

	/**
	 * The nine rule checks, each pass, warning or problem.
	 *
	 * @param SmartNewsChannel                 $channel The channel.
	 * @param array<int, array<string, mixed>> $feeds   Feeds with their check.
	 * @param array<int, array<string, mixed>> $items   Every live item.
	 * @return array<int, array{code: string, label: string, status: string}>
	 */
	private function checks( SmartNewsChannel $channel, array $feeds, array $items ): array {
		$total = count( $items );
		$small = count( FeedCheck::small( $items ) );
		$edge  = $this->plugin->rules()->limit( 'smartnews', 'thumbnail_long_edge_min', 1500 );
		$all   = static function ( string $key ) use ( $items ): bool {
			foreach ( $items as $item ) {
				if ( empty( $item[ $key ] ) ) {
					return false;
				}
			}

			return true;
		};
		$logo  = $this->details( $channel, array() )['logo'];

		return array(
			$this->check(
				'item.fulltext',
				__( 'Full text and the lead image in every item', 'estasoft-news-distribution' ),
				$all( 'has_text' ) && $all( 'lead_in_body' ) ? self::PASS : self::PROBLEM
			),
			$this->check(
				'item.author_thumbnail',
				__( 'An author and a thumbnail on every item', 'estasoft-news-distribution' ),
				$this->every_item_has( $items, array( 'author', 'thumbnail' ) ) ? self::PASS : self::PROBLEM
			),
			$this->check(
				'thumbnail.size',
				0 === $small
					? sprintf(
						/* translators: %s: a size in pixels such as 1,500. */
						__( 'Thumbnail of %s px or more on the long edge', 'estasoft-news-distribution' ),
						number_format_i18n( (int) $edge )
					)
					: sprintf(
						/* translators: 1: a size in pixels such as 1,500, 2: the number of smaller thumbnails, 3: the number of items. */
						_n(
							'Thumbnail of %1$s px or more on the long edge: %2$d of %3$d items is smaller and stays in the feed',
							'Thumbnail of %1$s px or more on the long edge: %2$d of %3$d items are smaller and stay in the feed',
							$small,
							'estasoft-news-distribution'
						),
						number_format_i18n( (int) $edge ),
						$small,
						$total
					),
				0 === $small ? self::PASS : self::WARNING
			),
			$this->check(
				'item.canonical',
				__( 'Canonical addresses, with tracking parameters removed', 'estasoft-news-distribution' ),
				$all( 'canonical_ok' ) ? self::PASS : self::PROBLEM
			),
			$this->check(
				'item.date',
				__( 'Dates in RFC 822 form', 'estasoft-news-distribution' ),
				$all( 'date_ok' ) ? self::PASS : self::PROBLEM
			),
			$this->check(
				'feed.size',
				__( 'Feed kept to about 100 items and under 1 MB', 'estasoft-news-distribution' ),
				$this->within_size( $feeds ) ? self::PASS : self::WARNING
			),
			$this->check(
				'item.deleted',
				__( 'Unpublished articles marked deleted', 'estasoft-news-distribution' ),
				$this->deleted_marked( $channel, $feeds ) ? self::PASS : self::PROBLEM
			),
			$this->check(
				'feed.format',
				__( 'UTF-8 and the required namespaces', 'estasoft-news-distribution' ),
				$this->format_ok( $feeds ) ? self::PASS : self::PROBLEM
			),
			$this->check(
				'logo.size',
				__( 'A logo of 700 × 100', 'estasoft-news-distribution' ),
				$logo['ok'] ? self::PASS : ( $logo['present'] ? self::WARNING : self::PROBLEM )
			),
		);
	}

	/**
	 * One check row.
	 *
	 * @param string $code   Rule code in the pack.
	 * @param string $label  What it says.
	 * @param string $status pass, warning or problem.
	 * @return array{code: string, label: string, status: string}
	 */
	private function check( string $code, string $label, string $status ): array {
		return array(
			'code'   => $code,
			'label'  => $label,
			'status' => $status,
		);
	}

	/**
	 * The totals for a list of checks.
	 *
	 * @param array<int, array{status: string}> $checks Checks.
	 * @return array{total: int, passed: int, warnings: int, problems: int}
	 */
	private function summary( array $checks ): array {
		$summary = array(
			'total'    => count( $checks ),
			'passed'   => 0,
			'warnings' => 0,
			'problems' => 0,
		);

		foreach ( $checks as $check ) {
			if ( self::PASS === $check['status'] ) {
				++$summary['passed'];
			} elseif ( self::WARNING === $check['status'] ) {
				++$summary['warnings'];
			} else {
				++$summary['problems'];
			}
		}

		return $summary;
	}

	/**
	 * The publication details: name, languages, logo and description.
	 *
	 * @param SmartNewsChannel $channel   The channel.
	 * @param string[]         $languages Language codes the feeds carry.
	 * @return array<string, mixed>
	 */
	private function details( SmartNewsChannel $channel, array $languages ): array {
		$settings = $channel->settings();
		$logo_id  = (int) $settings['logo_id'];
		$meta     = $logo_id > 0 ? wp_get_attachment_metadata( $logo_id ) : false;
		$width    = is_array( $meta ) ? (int) ( $meta['width'] ?? 0 ) : 0;
		$height   = is_array( $meta ) ? (int) ( $meta['height'] ?? 0 ) : 0;
		$max      = (int) $this->plugin->rules()->limit( 'smartnews', 'description_recommended_max', 30 );
		$text     = trim( (string) $settings['description'] );
		$names    = array();

		foreach ( $languages as $language ) {
			$names[] = self::language_label( $language, true );
		}

		return array(
			'name'           => $this->plugin->settings()->publication_name(),
			'language_names' => $names,
			'logo'           => array(
				'present' => $logo_id > 0 && $width > 0,
				'width'   => $width,
				'height'  => $height,
				'ok'      => (int) $this->plugin->rules()->limit( 'smartnews', 'logo_width', 700 ) === $width && (int) $this->plugin->rules()->limit( 'smartnews', 'logo_height', 100 ) === $height,
			),
			'description'    => array(
				'text'   => $text,
				'length' => mb_strlen( $text ),
				'max'    => $max,
				'ok'     => '' !== $text && mb_strlen( $text ) < $max,
			),
		);
	}

	/**
	 * Up to a few entries, newest of each language, as the platform receives them.
	 *
	 * @param array<int, array<string, mixed>> $items Every live item.
	 * @param int                              $limit How many to show.
	 * @return array<int, array<string, mixed>>
	 */
	private function samples( array $items, int $limit ): array {
		$seen    = array();
		$samples = array();

		foreach ( $items as $item ) {
			$language = (string) $item['language'];

			if ( isset( $seen[ $language ] ) || count( $samples ) >= $limit ) {
				continue;
			}

			$seen[ $language ] = true;
			$samples[]         = array(
				'language'  => $language,
				'title'     => (string) $item['title'],
				'author'    => (string) $item['author'],
				'width'     => (int) $item['width'],
				'height'    => (int) $item['height'],
				'canonical' => (string) $item['link'],
			);
		}

		return $samples;
	}

	/**
	 * The contact a person chose to share.
	 *
	 * @param array<string, mixed> $options Options given to build().
	 * @return array{name: string, email: string}
	 */
	private function contact( array $options ): array {
		$user  = wp_get_current_user();
		$name  = isset( $options['contact_name'] ) ? (string) $options['contact_name'] : ( $user->exists() ? (string) $user->display_name : '' );
		$email = isset( $options['contact_email'] ) ? (string) $options['contact_email'] : ( $user->exists() ? (string) $user->user_email : '' );

		return array(
			'name'  => sanitize_text_field( $name ),
			'email' => sanitize_email( $email ),
		);
	}

	/**
	 * Whether every item has each of the listed non-empty fields.
	 *
	 * @param array<int, array<string, mixed>> $items  Items.
	 * @param string[]                         $fields Field names.
	 * @return bool
	 */
	private function every_item_has( array $items, array $fields ): bool {
		foreach ( $items as $item ) {
			foreach ( $fields as $field ) {
				if ( '' === trim( (string) ( $item[ $field ] ?? '' ) ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Whether every feed is within the item and size habit.
	 *
	 * @param array<int, array<string, mixed>> $feeds Feeds with their check.
	 * @return bool
	 */
	private function within_size( array $feeds ): bool {
		$items = (int) $this->plugin->rules()->limit( 'smartnews', 'feed_items', 100 );
		$bytes = (int) $this->plugin->rules()->limit( 'smartnews', 'feed_bytes', 1000000 );

		foreach ( $feeds as $feed ) {
			if ( $feed['check']['live'] > $items || $feed['check']['bytes'] >= $bytes ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether every recently removed article appears in its feed as deleted.
	 *
	 * @param SmartNewsChannel                 $channel The channel.
	 * @param array<int, array<string, mixed>> $feeds   Feeds with their check.
	 * @return bool
	 */
	private function deleted_marked( SmartNewsChannel $channel, array $feeds ): bool {
		foreach ( $feeds as $feed ) {
			$wanted = count( Removals::active( (string) $feed['language'], Clock::now(), $channel->deleted_days() ) );

			if ( $feed['check']['deleted'] < $wanted ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether every feed is UTF-8 and declares the namespaces.
	 *
	 * @param array<int, array<string, mixed>> $feeds Feeds with their check.
	 * @return bool
	 */
	private function format_ok( array $feeds ): bool {
		foreach ( $feeds as $feed ) {
			if ( ! $feed['check']['utf8'] || ! $feed['check']['namespaces'] ) {
				return false;
			}
		}

		return true;
	}
}
