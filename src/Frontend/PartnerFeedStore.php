<?php
/**
 * The list of partners that read an API-key feed, and their keys.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Frontend;

use Estasoft\NewsDistribution\Core\Audit;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Stored in the option `estasoft_nd_partners` (not autoloaded) as `{ id: record }`.
 * A record holds the name, the address slug, whether the feed is on, the languages,
 * the category filter, the rate limit and, for the key, only a keyed hash, its salt,
 * the last four characters and the time it was made. The key itself is never stored,
 * never logged and never returned by any route after it was made.
 *
 * Making, replacing, revoking and deleting go through here, so the screen and the
 * REST routes behave the same and leave the same audit rows. Revoking, replacing and
 * deleting a key write `Audit::KEY_REVOKED`.
 *
 * The time a partner last fetched is kept apart, in `estasoft_nd_partner_seen`, and
 * written at most once a minute, so a busy feed never rewrites the partner list.
 */
final class PartnerFeedStore {

	public const OPTION      = 'estasoft_nd_partners';
	public const SEEN_OPTION = 'estasoft_nd_partner_seen';

	public const RATE_MIN     = 10;
	public const RATE_MAX     = 600;
	public const RATE_DEFAULT = 60;
	public const NAME_MAX     = 80;

	/**
	 * Seconds between two writes of "last fetch" for one partner.
	 */
	private const SEEN_EVERY = 60;

	private Plugin $plugin;

	/**
	 * The stored list for this request.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $stored = null;

	/**
	 * Creates the store.
	 *
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Every partner as stored, hashes included. For this class and the feed only; screens and routes use views().
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		if ( null === $this->stored ) {
			$raw          = get_option( self::OPTION, array() );
			$this->stored = is_array( $raw ) ? $raw : array();
		}

		return $this->stored;
	}

	/**
	 * Forgets what was read, so the next read goes to the option. Tests call it.
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->stored = null;
	}

	/**
	 * One partner as stored.
	 *
	 * @param string $id Partner id.
	 * @return array<string, mixed>|null
	 */
	public function get( string $id ): ?array {
		$all = $this->all();

		return isset( $all[ $id ] ) && is_array( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * The partner that owns an address slug.
	 *
	 * @param string $slug Slug from the feed address.
	 * @return array<string, mixed>|null
	 */
	public function find_by_slug( string $slug ): ?array {
		foreach ( $this->all() as $partner ) {
			if ( is_array( $partner ) && isset( $partner['slug'] ) && (string) $partner['slug'] === $slug ) {
				return $partner;
			}
		}

		return null;
	}

	/**
	 * The partners in the order they were added, as a screen or a route may show them.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function views(): array {
		$views = array();

		foreach ( $this->all() as $partner ) {
			if ( is_array( $partner ) ) {
				$views[] = $this->view( $partner );
			}
		}

		return $views;
	}

	/**
	 * A partner without anything secret in it.
	 *
	 * @param array<string, mixed> $partner A stored partner.
	 * @return array<string, mixed>
	 */
	public function view( array $partner ): array {
		$id   = (string) ( $partner['id'] ?? '' );
		$hint = (string) ( $partner['key_hint'] ?? '' );
		$set  = '' !== (string) ( $partner['key_hash'] ?? '' );
		$seen = $this->last_seen( $id );

		return array(
			'id'         => $id,
			'name'       => (string) ( $partner['name'] ?? '' ),
			'slug'       => (string) ( $partner['slug'] ?? '' ),
			'enabled'    => ! empty( $partner['enabled'] ),
			'languages'  => array_values( array_map( 'strval', (array) ( $partner['languages'] ?? array() ) ) ),
			'categories' => array_values( array_map( 'strval', (array) ( $partner['categories'] ?? array() ) ) ),
			'rate_limit' => (int) ( $partner['rate_limit'] ?? self::RATE_DEFAULT ),
			'feed_url'   => self::feed_url( (string) ( $partner['slug'] ?? '' ) ),
			'key'        => array(
				'status'  => $set ? 'set' : 'revoked',
				'hint'    => $set ? $hint : '',
				'masked'  => $set ? PartnerFeedKey::mask( $hint ) : '',
				'created' => $set && ! empty( $partner['key_created'] ) ? Clock::iso( (int) $partner['key_created'] ) : null,
			),
			'last_fetch' => $seen > 0 ? Clock::iso( $seen ) : null,
			'created'    => ! empty( $partner['created'] ) ? Clock::iso( (int) $partner['created'] ) : null,
		);
	}

	/**
	 * The address a partner fetches.
	 *
	 * @param string $slug The partner's slug.
	 * @return string
	 */
	public static function feed_url( string $slug ): string {
		return home_url( '/partner-feed/' . $slug . '/' );
	}

	/**
	 * Checks what a person typed and returns the cleaned values or the messages.
	 *
	 * A value outside its range is rejected, never changed. Fields left out keep their value
	 * (`$current`); when there is no current partner they take the defaults.
	 *
	 * @param array<string, mixed>      $input   Submitted values: name, enabled, languages, categories, rate_limit.
	 * @param array<string, mixed>|null $current The stored partner, or null for a new one.
	 * @return array{values: array<string, mixed>, errors: array<string, string>}
	 */
	public function clean( array $input, ?array $current ): array {
		$errors = array();
		$values = array(
			'name'       => (string) ( $current['name'] ?? '' ),
			'enabled'    => null === $current ? true : ! empty( $current['enabled'] ),
			'languages'  => (array) ( $current['languages'] ?? array() ),
			'categories' => (array) ( $current['categories'] ?? array() ),
			'rate_limit' => (int) ( $current['rate_limit'] ?? self::RATE_DEFAULT ),
		);

		if ( array_key_exists( 'name', $input ) || null === $current ) {
			$name = isset( $input['name'] ) && is_scalar( $input['name'] ) ? trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $input['name'] ) ) ) : '';

			if ( '' === $name ) {
				$errors['name'] = __( 'Enter a name for this partner.', 'estasoft-news-distribution' );
			} elseif ( mb_strlen( $name ) > self::NAME_MAX ) {
				$errors['name'] = sprintf(
					/* translators: %d: the largest number of characters a partner name can have. */
					_n( 'A partner name can be at most %d character.', 'A partner name can be at most %d characters.', self::NAME_MAX, 'estasoft-news-distribution' ),
					self::NAME_MAX
				);
			} else {
				$values['name'] = $name;
			}
		}

		if ( array_key_exists( 'enabled', $input ) ) {
			$values['enabled'] = (bool) filter_var( $input['enabled'], FILTER_VALIDATE_BOOLEAN );
		}

		if ( array_key_exists( 'languages', $input ) || null === $current ) {
			$chosen = array_key_exists( 'languages', $input ) ? $input['languages'] : array( (string) $this->plugin->languages()->default_language() );
			$chosen = is_array( $chosen ) ? array_values( array_unique( array_map( 'strval', $chosen ) ) ) : array();
			$known  = array_map( 'strval', $this->plugin->languages()->languages() );
			$bad    = array_values( array_diff( $chosen, $known ) );

			if ( array() === $chosen ) {
				$errors['languages'] = __( 'Choose at least one language.', 'estasoft-news-distribution' );
			} elseif ( array() !== $bad ) {
				$errors['languages'] = sprintf(
					/* translators: %s: a language code the site does not publish in. */
					__( '“%s” is not a language this site publishes in.', 'estasoft-news-distribution' ),
					(string) reset( $bad )
				);
			} else {
				// Keep the site's own order, so the first one is the feed's default.
				$values['languages'] = array_values( array_intersect( $known, $chosen ) );
			}
		}

		if ( array_key_exists( 'categories', $input ) ) {
			$chosen = is_array( $input['categories'] ) ? array_values( array_unique( array_map( 'strval', $input['categories'] ) ) ) : array();
			$bad    = '';

			foreach ( $chosen as $slug ) {
				if ( ! term_exists( $slug, 'category' ) ) {
					$bad = $slug;
					break;
				}
			}

			if ( '' !== $bad ) {
				$errors['categories'] = sprintf(
					/* translators: %s: a category slug the site does not have. */
					__( 'There is no category called “%s” on this site.', 'estasoft-news-distribution' ),
					$bad
				);
			} else {
				$values['categories'] = $chosen;
			}
		}

		if ( array_key_exists( 'rate_limit', $input ) ) {
			$raw = $input['rate_limit'];

			if ( is_string( $raw ) ) {
				$raw = trim( $raw );
			}

			$whole = ( is_int( $raw ) || ( is_string( $raw ) && 1 === preg_match( '/^[0-9]{1,6}$/', $raw ) ) ) ? (int) $raw : null;

			if ( null === $whole || $whole < self::RATE_MIN || $whole > self::RATE_MAX ) {
				$errors['rate_limit'] = sprintf(
					/* translators: 1: the smallest allowed number of requests a minute, 2: the largest. */
					__( 'Enter a number from %1$d to %2$d.', 'estasoft-news-distribution' ),
					self::RATE_MIN,
					self::RATE_MAX
				);
			} else {
				$values['rate_limit'] = $whole;
			}
		}

		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}

	/**
	 * Adds a partner and makes its key.
	 *
	 * @param array<string, mixed> $input Submitted values.
	 * @return array{partner: array<string, mixed>, key: string}|\WP_Error `key` is shown once and cannot be read again.
	 */
	public function create( array $input ) {
		$cleaned = $this->clean( $input, null );

		if ( array() !== $cleaned['errors'] ) {
			return $this->invalid( $cleaned['errors'] );
		}

		$values = $cleaned['values'];
		$made   = PartnerFeedKey::make();
		$id     = $this->new_id();
		$now    = Clock::now();
		$record = array(
			'id'          => $id,
			'name'        => $values['name'],
			'slug'        => $this->new_slug( $values['name'] ),
			'enabled'     => (bool) $values['enabled'],
			'languages'   => $values['languages'],
			'categories'  => $values['categories'],
			'rate_limit'  => $values['rate_limit'],
			'key_hash'    => $made['hash'],
			'key_salt'    => $made['salt'],
			'key_hint'    => $made['hint'],
			'key_created' => $now,
			'created'     => $now,
		);

		$this->write( array_merge( $this->all(), array( $id => $record ) ) );

		$this->plugin->audit()->record(
			Audit::SETTINGS,
			0,
			sprintf(
				/* translators: %s: a partner name. */
				__( 'Partner added: %s. An API key was made.', 'estasoft-news-distribution' ),
				$record['name']
			)
		);

		return array(
			'partner' => $this->view( $record ),
			'key'     => $made['key'],
		);
	}

	/**
	 * Changes a partner's name, switch, languages, categories or rate limit. The slug and the key stay.
	 *
	 * @param string               $id    Partner id.
	 * @param array<string, mixed> $input Submitted values; fields left out keep their value.
	 * @return array<string, mixed>|\WP_Error The partner as a view.
	 */
	public function update( string $id, array $input ) {
		$current = $this->get( $id );

		if ( null === $current ) {
			return $this->missing();
		}

		$cleaned = $this->clean( $input, $current );

		if ( array() !== $cleaned['errors'] ) {
			return $this->invalid( $cleaned['errors'] );
		}

		$record = array_merge( $current, $cleaned['values'] );
		$all    = $this->all();

		$all[ $id ] = $record;
		$this->write( $all );

		return $this->view( $record );
	}

	/**
	 * Makes a new key. The old one stops working at once. This is also how a revoked partner gets a key again.
	 *
	 * @param string $id Partner id.
	 * @return array{partner: array<string, mixed>, key: string}|\WP_Error
	 */
	public function rotate( string $id ) {
		$current = $this->get( $id );

		if ( null === $current ) {
			return $this->missing();
		}

		$had  = '' !== (string) ( $current['key_hash'] ?? '' );
		$made = PartnerFeedKey::make();
		$all  = $this->all();

		$all[ $id ] = array_merge(
			$current,
			array(
				'key_hash'    => $made['hash'],
				'key_salt'    => $made['salt'],
				'key_hint'    => $made['hint'],
				'key_created' => Clock::now(),
			)
		);
		$this->write( $all );

		if ( $had ) {
			$this->plugin->audit()->record(
				Audit::KEY_REVOKED,
				0,
				sprintf(
					/* translators: %s: a partner name. */
					__( 'API key for %s replaced. The old key stopped working.', 'estasoft-news-distribution' ),
					(string) $current['name']
				)
			);
		}

		return array(
			'partner' => $this->view( $all[ $id ] ),
			'key'     => $made['key'],
		);
	}

	/**
	 * Revokes the key. The feed answers 401 until a new key is made.
	 *
	 * @param string $id Partner id.
	 * @return array<string, mixed>|\WP_Error The partner as a view.
	 */
	public function revoke( string $id ) {
		$current = $this->get( $id );

		if ( null === $current ) {
			return $this->missing();
		}

		$all        = $this->all();
		$all[ $id ] = array_merge(
			$current,
			array(
				'key_hash'    => '',
				'key_salt'    => '',
				'key_hint'    => '',
				'key_created' => 0,
			)
		);
		$this->write( $all );

		$this->plugin->audit()->record(
			Audit::KEY_REVOKED,
			0,
			sprintf(
				/* translators: %s: a partner name. */
				__( 'API key for %s revoked. Its feed now answers with an error.', 'estasoft-news-distribution' ),
				(string) $current['name']
			)
		);

		return $this->view( $all[ $id ] );
	}

	/**
	 * Removes a partner. Its feed address stops working, so its key is revoked with it.
	 *
	 * @param string $id Partner id.
	 * @return true|\WP_Error
	 */
	public function delete( string $id ) {
		$current = $this->get( $id );

		if ( null === $current ) {
			return $this->missing();
		}

		$all = $this->all();
		unset( $all[ $id ] );
		$this->write( $all );

		$seen = get_option( self::SEEN_OPTION, array() );

		if ( is_array( $seen ) && isset( $seen[ $id ] ) ) {
			unset( $seen[ $id ] );
			update_option( self::SEEN_OPTION, $seen, false );
		}

		$this->plugin->audit()->record(
			Audit::KEY_REVOKED,
			0,
			sprintf(
				/* translators: %s: a partner name. */
				__( 'Partner %s deleted. Its API key and its feed address stopped working.', 'estasoft-news-distribution' ),
				(string) $current['name']
			)
		);

		return true;
	}

	/**
	 * Notes that a partner fetched its feed, at most once a minute.
	 *
	 * @param string $id Partner id.
	 * @return void
	 */
	public function touch( string $id ): void {
		$seen = get_option( self::SEEN_OPTION, array() );
		$seen = is_array( $seen ) ? $seen : array();
		$now  = Clock::now();

		if ( isset( $seen[ $id ] ) && $now - (int) $seen[ $id ] < self::SEEN_EVERY ) {
			return;
		}

		$seen[ $id ] = $now;
		update_option( self::SEEN_OPTION, $seen, false );
	}

	/**
	 * When a partner last fetched its feed.
	 *
	 * @param string $id Partner id.
	 * @return int Unix time, or 0 when it never has.
	 */
	public function last_seen( string $id ): int {
		$seen = get_option( self::SEEN_OPTION, array() );

		return is_array( $seen ) && isset( $seen[ $id ] ) ? (int) $seen[ $id ] : 0;
	}

	/**
	 * The categories a partner can be limited to: slug => name.
	 *
	 * @return array<string, string>
	 */
	public function categories_available(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
				'number'     => 100,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		$list = array();

		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			if ( $term instanceof \WP_Term ) {
				$list[ (string) $term->slug ] = (string) $term->name;
			}
		}

		return $list;
	}

	/**
	 * Saves the whole list.
	 *
	 * @param array<string, array<string, mixed>> $all The list.
	 * @return void
	 */
	private function write( array $all ): void {
		update_option( self::OPTION, $all, false );
		$this->stored = $all;
	}

	/**
	 * A partner id that is not used yet.
	 *
	 * @return string
	 */
	private function new_id(): string {
		do {
			$id = 'p' . bin2hex( random_bytes( 4 ) );
		} while ( isset( $this->all()[ $id ] ) );

		return $id;
	}

	/**
	 * An address slug from a name: letters and digits, joined by hyphens, never taken by another partner.
	 *
	 * The feed address pattern allows only a to z, 0 to 9 and hyphens, so a name in another script
	 * falls back to "partner" and a number.
	 *
	 * @param string $name The partner's name.
	 * @return string
	 */
	private function new_slug( string $name ): string {
		$base = strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '-', remove_accents( $name ) ) );
		$base = trim( substr( $base, 0, 50 ), '-' );
		$base = '' === $base ? 'partner' : $base;
		$slug = $base;
		$n    = 2;

		while ( null !== $this->find_by_slug( $slug ) ) {
			$slug = $base . '-' . $n;
			++$n;
		}

		return $slug;
	}

	/**
	 * A "no such partner" error.
	 *
	 * @return \WP_Error
	 */
	private function missing(): \WP_Error {
		return new \WP_Error( 'estasoft_nd_partner_not_found', __( 'That partner does not exist.', 'estasoft-news-distribution' ), array( 'status' => 404 ) );
	}

	/**
	 * A "not saved" error that names the fields.
	 *
	 * @param array<string, string> $errors Field => message.
	 * @return \WP_Error
	 */
	private function invalid( array $errors ): \WP_Error {
		return new \WP_Error(
			'estasoft_nd_invalid_value',
			(string) reset( $errors ),
			array(
				'status' => 400,
				'fields' => $errors,
			)
		);
	}
}
