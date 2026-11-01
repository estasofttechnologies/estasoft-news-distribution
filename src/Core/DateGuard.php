<?php
/**
 * The modified-date guard.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Google uses the modified date in a sitemap only when it is consistently
 * accurate. A date that moves on every typo teaches crawlers to stop believing
 * it; a date that stays put when the story changed hides the update.
 *
 * On a save of a published article the guard compares the saved article with
 * the one before it. If nothing the owner counts as important changed (the
 * Dates settings: headline, body text of at least a set size, structured data,
 * featured image, links, and optionally categories and tags) it puts the
 * article's modified date back to the earlier value while WordPress writes the
 * row, so the posts list, the editor, sitemaps, page markup and feeds all show
 * the same date. The published date is never touched.
 *
 * Every decision is stored on the article in `_estasoft_nd_date_guard`: the
 * last decision at the top, and the last 20 in `events`. The last 20 saves of
 * the whole site are kept in the option `estasoft_nd_date_guard_log`.
 *
 * Get it with `Plugin::instance()->make( DateGuard::class )`.
 */
final class DateGuard {

	public const META       = '_estasoft_nd_date_guard';
	public const LOG_OPTION = 'estasoft_nd_date_guard_log';

	public const KEPT  = 'kept';
	public const MOVED = 'moved';

	public const REASON_HEADLINE   = 'headline';
	public const REASON_BODY       = 'body';
	public const REASON_LINKS      = 'links';
	public const REASON_MARKUP     = 'markup';
	public const REASON_IMAGE      = 'image';
	public const REASON_TERMS      = 'terms';
	public const REASON_CORRECTION = 'correction';
	public const REASON_NOTHING    = 'nothing';

	private const MAX_EVENTS = 20;
	private const MAX_LOG    = 20;
	private const MAX_WORDS  = 20000;

	private Plugin $plugin;

	/**
	 * Decisions made while WordPress was writing a post, by post ID, until the save finished.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $pending = array();

	/**
	 * What an article looked like before a save, by post ID: its featured image and terms.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $before = array();

	/**
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Whether the guard is on.
	 *
	 * @return bool
	 */
	public function enabled(): bool {
		return (bool) $this->plugin->settings()->get( 'dates.guard', true );
	}

	/**
	 * Whether a post type is one the site sends to platforms.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public function supports( string $post_type ): bool {
		return in_array( $post_type, $this->plugin->settings()->post_types(), true );
	}

	/**
	 * `wp_insert_post_data`: puts the earlier modified date back when the save changed nothing important.
	 *
	 * The data is slashed at this point, as WordPress passes it.
	 *
	 * @param array<string, mixed> $data        Data WordPress is about to write.
	 * @param array<string, mixed> $postarr     What the caller passed.
	 * @param array<string, mixed> $unsanitized What the caller passed, unsanitized.
	 * @param bool                 $update      Whether this is an update of an existing post.
	 * @return array<string, mixed>
	 */
	public function filter_post_data( $data, $postarr = array(), $unsanitized = array(), $update = false ) {
		if ( ! is_array( $data ) || ! is_array( $postarr ) || ! $update || empty( $postarr['ID'] ) || $this->is_ignored_request() || ! $this->enabled() ) {
			return $data;
		}

		$post_id = (int) $postarr['ID'];
		$old     = get_post( $post_id );

		unset( $this->before[ $post_id ], $this->pending[ $post_id ] );

		if ( ! $old instanceof \WP_Post
			|| wp_is_post_revision( $post_id )
			|| wp_is_post_autosave( $post_id )
			|| ! $this->supports( (string) $old->post_type )
			|| ! $this->supports( (string) ( $data['post_type'] ?? $old->post_type ) )
			|| 'publish' !== $old->post_status
			|| 'publish' !== ( $data['post_status'] ?? '' ) ) {
			return $data;
		}

		$this->before[ $post_id ] = array(
			'thumbnail' => (int) get_post_thumbnail_id( $post_id ),
			'terms'     => $this->terms_of( $post_id ),
		);

		$decision = $this->decide(
			array(
				'title'   => (string) $old->post_title,
				'content' => (string) $old->post_content,
				'author'  => (int) $old->post_author,
				'date'    => (string) $old->post_date_gmt,
			),
			array(
				'title'     => wp_unslash( (string) ( $data['post_title'] ?? '' ) ),
				'content'   => wp_unslash( (string) ( $data['post_content'] ?? '' ) ),
				'author'    => (int) ( $data['post_author'] ?? 0 ),
				'date'      => (string) ( $data['post_date_gmt'] ?? '' ),
				'thumbnail' => $this->posted_thumbnail( $postarr ),
				'terms'     => $this->posted_terms( $postarr, $post_id ),
			),
			$post_id,
			$this->before[ $post_id ]
		);

		$decision['previous_gmt'] = (string) $old->post_modified_gmt;

		if ( self::KEPT === $decision['decision'] && '' !== (string) $old->post_modified_gmt && '0000-00-00 00:00:00' !== $old->post_modified_gmt ) {
			$data['post_modified']     = (string) $old->post_modified;
			$data['post_modified_gmt'] = (string) $old->post_modified_gmt;
		}

		$decision['old_modified']  = (string) $old->post_modified;
		$this->pending[ $post_id ] = $decision;

		return $data;
	}

	/**
	 * `wp_after_insert_post`: writes down the decision made while the post was saved.
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    The saved post.
	 * @return void
	 */
	public function after_insert( $post_id, $post = null ): void {
		$post_id = (int) $post_id;

		if ( ! isset( $this->pending[ $post_id ] ) ) {
			return;
		}

		$decision = $this->pending[ $post_id ];
		unset( $this->pending[ $post_id ] );

		// The date may have moved after the post object was read (see after_rest()), so read the row again.
		$fresh = get_post( $post_id );
		$post  = $fresh instanceof \WP_Post ? $fresh : ( $post instanceof \WP_Post ? $post : null );

		$decision['at']           = Clock::now();
		$decision['modified_gmt'] = $post instanceof \WP_Post ? (string) $post->post_modified_gmt : '';

		$this->store( $post_id, $decision );
	}

	/**
	 * `rest_after_insert_<type>`: the block editor sets the featured image and the
	 * categories and tags after the post row is written. If one of them changed and
	 * the owner counts it, the date moves after all.
	 *
	 * WordPress fires this before `wp_after_insert_post` when a REST request saves a post,
	 * so the decision is usually still waiting to be written down. It is changed there, and
	 * written once. If it was already written, the stored one is replaced.
	 *
	 * @param \WP_Post $post The saved post.
	 * @return void
	 */
	public function after_rest( $post ): void {
		if ( ! $post instanceof \WP_Post || ! isset( $this->before[ (int) $post->ID ] ) ) {
			return;
		}

		$post_id = (int) $post->ID;
		$before  = $this->before[ $post_id ];
		unset( $this->before[ $post_id ] );

		$waiting = isset( $this->pending[ $post_id ] );
		$last    = $waiting ? $this->pending[ $post_id ] : $this->last( $post_id );

		if ( null === $last || self::KEPT !== $last['decision'] ) {
			return;
		}

		$counts = $this->counts();
		$reason = '';

		if ( in_array( 'image', $counts, true ) && (int) get_post_thumbnail_id( $post_id ) !== (int) $before['thumbnail'] ) {
			$reason = self::REASON_IMAGE;
		} elseif ( in_array( 'terms', $counts, true ) && $this->terms_of( $post_id ) !== $before['terms'] ) {
			$reason = self::REASON_TERMS;
		}

		if ( '' === $reason ) {
			return;
		}

		global $wpdb;

		$now_gmt = Clock::to_gmt();
		$now     = get_date_from_gmt( $now_gmt );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->posts,
			array(
				'post_modified'     => $now,
				'post_modified_gmt' => $now_gmt,
			),
			array( 'ID' => $post_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		clean_post_cache( $post_id );

		$last['decision'] = self::MOVED;
		$last['reason']   = $reason;
		$last['chars']    = 0;

		if ( $waiting ) {
			$this->pending[ $post_id ] = $last;

			return;
		}

		$last['modified_gmt'] = $now_gmt;

		$this->store( $post_id, $last, true );
	}

	/**
	 * Decides whether a save moves the modified date.
	 *
	 * Both arguments are arrays with `title`, `content`, `author` and `date` (UTC
	 * database value). The new one may also carry `thumbnail` (an attachment ID, or
	 * null when the save did not say) and `terms` (the shape of terms_of(), or null).
	 *
	 * @param array<string, mixed> $old       The article before the save.
	 * @param array<string, mixed> $saved       The article as saved.
	 * @param int                  $post_id   Post ID, 0 when unknown.
	 * @param array<string, mixed> $snapshot  What the article had before: `thumbnail` and `terms`.
	 * @return array{decision: string, reason: string, chars: int, changes: array<int, array{reason: string, chars: int, counted: bool}>}
	 */
	public function decide( array $old, array $saved, int $post_id = 0, array $snapshot = array() ): array {
		$counts  = $this->counts();
		$min     = max( 1, (int) $this->plugin->settings()->get( 'dates.min_body_chars', 20 ) );
		$changes = array();

		/**
		 * Whether a save always moves the modified date, whatever else changed.
		 *
		 * Internal. A correction note being added or edited answers true. The page
		 * labels feature uses this.
		 *
		 * @param bool                 $always  Default false.
		 * @param array<string, mixed> $old     The article before the save.
		 * @param array<string, mixed> $saved     The article as saved.
		 * @param int                  $post_id Post ID.
		 */
		if ( (bool) apply_filters( 'estasoft_nd_internal_date_guard_always_moves', false, $old, $saved, $post_id ) ) {
			$changes[] = array(
				'reason'  => self::REASON_CORRECTION,
				'chars'   => 0,
				'counted' => true,
			);
		}

		if ( (string) $old['title'] !== (string) $saved['title'] ) {
			$changes[] = array(
				'reason'  => self::REASON_HEADLINE,
				'chars'   => 0,
				'counted' => in_array( 'headline', $counts, true ),
			);
		}

		$chars = $this->body_change( (string) $old['content'], (string) $saved['content'] );

		if ( $chars > 0 ) {
			$changes[] = array(
				'reason'  => self::REASON_BODY,
				'chars'   => $chars,
				'counted' => in_array( 'body', $counts, true ) && $chars >= $min,
			);
		}

		if ( ! empty( $saved['thumbnail'] ) || ( array_key_exists( 'thumbnail', $saved ) && null !== $saved['thumbnail'] ) ) {
			if ( (int) ( $snapshot['thumbnail'] ?? 0 ) !== (int) $saved['thumbnail'] ) {
				$changes[] = array(
					'reason'  => self::REASON_IMAGE,
					'chars'   => 0,
					'counted' => in_array( 'image', $counts, true ),
				);
			}
		}

		if ( $this->link_set( (string) $old['content'] ) !== $this->link_set( (string) $saved['content'] ) ) {
			$changes[] = array(
				'reason'  => self::REASON_LINKS,
				'chars'   => 0,
				'counted' => in_array( 'links', $counts, true ),
			);
		}

		if ( (int) $old['author'] !== (int) $saved['author'] || ( '' !== (string) $saved['date'] && (string) $old['date'] !== (string) $saved['date'] ) ) {
			$changes[] = array(
				'reason'  => self::REASON_MARKUP,
				'chars'   => 0,
				'counted' => in_array( 'markup', $counts, true ),
			);
		}

		if ( isset( $saved['terms'] ) && is_array( $saved['terms'] ) && ( $snapshot['terms'] ?? array() ) !== $saved['terms'] ) {
			$changes[] = array(
				'reason'  => self::REASON_TERMS,
				'chars'   => 0,
				'counted' => in_array( 'terms', $counts, true ),
			);
		}

		foreach ( $changes as $change ) {
			if ( $change['counted'] ) {
				return array(
					'decision' => self::MOVED,
					'reason'   => $change['reason'],
					'chars'    => $change['chars'],
					'changes'  => $changes,
				);
			}
		}

		$first = $changes[0] ?? array(
			'reason' => self::REASON_NOTHING,
			'chars'  => 0,
		);

		return array(
			'decision' => self::KEPT,
			'reason'   => (string) $first['reason'],
			'chars'    => (int) $first['chars'],
			'changes'  => $changes,
		);
	}

	/**
	 * How many characters of the body changed: the larger of the characters added
	 * and the characters taken away, counting whole words, wherever they are. A typo
	 * is a few characters; a new sentence is dozens.
	 *
	 * @param string $old Body before.
	 * @param string $saved Body after.
	 * @return int
	 */
	public function body_change( string $old, string $saved ): int {
		if ( $old === $saved ) {
			return 0;
		}

		$before = $this->words( $old );
		$after  = $this->words( $saved );
		$added  = 0;
		$gone   = 0;

		foreach ( $after as $word => $count ) {
			$extra = $count - ( $before[ $word ] ?? 0 );

			if ( $extra > 0 ) {
				$added += $extra * mb_strlen( (string) $word );
			}
		}

		foreach ( $before as $word => $count ) {
			$missing = $count - ( $after[ $word ] ?? 0 );

			if ( $missing > 0 ) {
				$gone += $missing * mb_strlen( (string) $word );
			}
		}

		return max( $added, $gone );
	}

	/**
	 * The last decision on an article.
	 *
	 * @param int $post_id Article ID.
	 * @return array<string, mixed>|null
	 */
	public function last( int $post_id ): ?array {
		$meta = get_post_meta( $post_id, self::META, true );

		if ( ! is_array( $meta ) || ! isset( $meta['decision'] ) ) {
			return null;
		}

		unset( $meta['events'] );

		return $meta;
	}

	/**
	 * Every stored decision on an article, newest first.
	 *
	 * @param int $post_id Article ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function events( int $post_id ): array {
		$meta = get_post_meta( $post_id, self::META, true );

		return is_array( $meta ) && isset( $meta['events'] ) && is_array( $meta['events'] ) ? array_values( $meta['events'] ) : array();
	}

	/**
	 * The latest saves on the whole site, newest first.
	 *
	 * @param int $limit How many.
	 * @return array<int, array{post_id: int, decision: string, reason: string, chars: int, at: int}>
	 */
	public function recent( int $limit = 5 ): array {
		$log = get_option( self::LOG_OPTION, array() );

		return array_slice( is_array( $log ) ? array_values( $log ) : array(), 0, max( 1, $limit ) );
	}

	/**
	 * The status code for a decision: `date_kept` or `date_moved`.
	 *
	 * @param string $decision `kept` or `moved`.
	 * @return string
	 */
	public static function status_of( string $decision ): string {
		return self::KEPT === $decision ? Status::DATE_KEPT : Status::DATE_MOVED;
	}

	/**
	 * A short plain description of what changed, for the "What changed" column and the History tab.
	 *
	 * @param string $reason A REASON_ constant.
	 * @param int    $chars  Characters changed, for the body.
	 * @return string
	 */
	public static function describe( string $reason, int $chars = 0 ): string {
		switch ( $reason ) {
			case self::REASON_HEADLINE:
				return __( 'Headline changed', 'estasoft-news-distribution' );
			case self::REASON_BODY:
				return sprintf(
					/* translators: %s: a number of characters. */
					_n( 'Body: %s character', 'Body: %s characters', max( 0, $chars ), 'estasoft-news-distribution' ),
					number_format_i18n( $chars )
				);
			case self::REASON_LINKS:
				return __( 'Links in the body changed', 'estasoft-news-distribution' );
			case self::REASON_MARKUP:
				return __( 'Author or dates changed', 'estasoft-news-distribution' );
			case self::REASON_IMAGE:
				return __( 'Featured image replaced', 'estasoft-news-distribution' );
			case self::REASON_TERMS:
				return __( 'Categories or tags changed', 'estasoft-news-distribution' );
			case self::REASON_CORRECTION:
				return __( 'Correction note', 'estasoft-news-distribution' );
		}

		return __( 'Nothing important changed', 'estasoft-news-distribution' );
	}

	/**
	 * The change kinds the owner counts as real.
	 *
	 * @return string[]
	 */
	private function counts(): array {
		$counts = $this->plugin->settings()->get( 'dates.counts', array() );

		return is_array( $counts ) ? array_map( 'strval', $counts ) : array();
	}

	/**
	 * Whether this request is one the guard leaves alone: an autosave or an import.
	 *
	 * @return bool
	 */
	private function is_ignored_request(): bool {
		return ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ( defined( 'WP_IMPORTING' ) && WP_IMPORTING );
	}

	/**
	 * The featured image a save asked for, or null when it did not say.
	 *
	 * @param array<string, mixed> $postarr What the caller passed.
	 * @return int|null
	 */
	private function posted_thumbnail( array $postarr ): ?int {
		if ( isset( $postarr['_thumbnail_id'] ) ) {
			return max( 0, (int) $postarr['_thumbnail_id'] );
		}

		if ( isset( $postarr['meta_input'] ) && is_array( $postarr['meta_input'] ) && isset( $postarr['meta_input']['_thumbnail_id'] ) ) {
			return max( 0, (int) $postarr['meta_input']['_thumbnail_id'] );
		}

		return null;
	}

	/**
	 * The categories and tags a save asked for, in the shape of terms_of(), or null when it did not say.
	 *
	 * @param array<string, mixed> $postarr What the caller passed.
	 * @param int                  $post_id Post ID.
	 * @return array<string, array<int, string>>|null
	 */
	private function posted_terms( array $postarr, int $post_id ): ?array {
		$has_categories = isset( $postarr['post_category'] ) && is_array( $postarr['post_category'] );
		$has_tags       = isset( $postarr['tax_input'] ) && is_array( $postarr['tax_input'] ) && isset( $postarr['tax_input']['post_tag'] );

		if ( ! $has_categories && ! $has_tags ) {
			return null;
		}

		$terms = $this->terms_of( $post_id );

		if ( $has_categories ) {
			$ids = array_values( array_filter( array_map( 'intval', $postarr['post_category'] ) ) );
			sort( $ids );
			$terms['category'] = array_map( 'strval', $ids );
		}

		if ( $has_tags ) {
			$raw  = $postarr['tax_input']['post_tag'];
			$tags = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
			$ids  = array();

			foreach ( $tags as $tag ) {
				$tag = trim( (string) $tag );

				if ( '' === $tag ) {
					continue;
				}

				$term  = is_numeric( $tag ) ? get_term( (int) $tag, 'post_tag' ) : get_term_by( 'name', $tag, 'post_tag' );
				$ids[] = $term instanceof \WP_Term ? (string) $term->term_id : 'new:' . strtolower( $tag );
			}

			sort( $ids );
			$terms['post_tag'] = $ids;
		}

		return $terms;
	}

	/**
	 * The category and tag IDs of a post, sorted, as strings.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, array<int, string>>
	 */
	private function terms_of( int $post_id ): array {
		$out = array();

		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			$ids = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
			$ids = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
			sort( $ids );
			$out[ $taxonomy ] = array_map( 'strval', $ids );
		}

		return $out;
	}

	/**
	 * Every word of a body with how often it appears, as word => count.
	 *
	 * @param string $html Post content.
	 * @return array<string, int>
	 */
	private function words( string $html ): array {
		$text  = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
		$words = preg_split( '/\s+/u', trim( $text ), self::MAX_WORDS + 1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $words ) ) {
			return array();
		}

		return array_count_values( array_slice( $words, 0, self::MAX_WORDS ) );
	}

	/**
	 * The addresses linked from a body, sorted.
	 *
	 * @param string $html Post content.
	 * @return string[]
	 */
	private function link_set( string $html ): array {
		if ( false === stripos( $html, '<a' ) ) {
			return array();
		}

		preg_match_all( '/<a\s[^>]*?href\s*=\s*(["\'])(.*?)\1/is', $html, $matches );

		$links = array_values( array_unique( array_map( 'trim', $matches[2] ) ) );
		sort( $links );

		return $links;
	}

	/**
	 * Writes a decision on the article and into the site's log.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $entry   The decision with `at`.
	 * @param bool                 $replace Replace the newest entry instead of adding one.
	 * @return void
	 */
	private function store( int $post_id, array $entry, bool $replace = false ): void {
		$entry = array(
			'decision'     => (string) $entry['decision'],
			'reason'       => (string) $entry['reason'],
			'chars'        => (int) ( $entry['chars'] ?? 0 ),
			'at'           => (int) $entry['at'],
			'modified_gmt' => (string) ( $entry['modified_gmt'] ?? '' ),
			'previous_gmt' => (string) ( $entry['previous_gmt'] ?? '' ),
		);

		$events = $this->events( $post_id );

		if ( $replace && array() !== $events ) {
			$events[0] = $entry;
		} else {
			array_unshift( $events, $entry );
		}

		update_post_meta( $post_id, self::META, array_merge( $entry, array( 'events' => array_slice( $events, 0, self::MAX_EVENTS ) ) ) );

		$log  = $this->recent( self::MAX_LOG );
		$line = array(
			'post_id'  => $post_id,
			'decision' => $entry['decision'],
			'reason'   => $entry['reason'],
			'chars'    => $entry['chars'],
			'at'       => $entry['at'],
		);

		if ( $replace && array() !== $log && (int) $log[0]['post_id'] === $post_id ) {
			$log[0] = $line;
		} else {
			array_unshift( $log, $line );
		}

		update_option( self::LOG_OPTION, array_slice( $log, 0, self::MAX_LOG ), false );
	}
}
