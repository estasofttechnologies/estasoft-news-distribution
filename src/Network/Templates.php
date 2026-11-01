<?php
/**
 * Site templates: a few settings saved from one site and applied to others.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Network;

use Estasoft\NewsDistribution\Core\Audit;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * A template holds only these settings, and nothing else can get into one:
 *
 * - Languages and the default language (`languages`: `default`, `off`).
 * - The index-first delay and the feed cache time (`general`: `index_first_delay`, `cache_ttl_minutes`).
 * - Alert rules and recipients (`alerts`: `send_to`, `email_alerts`).
 * - Page markup and image rules (every field of `markup` and of `credits`).
 *
 * Platform accounts, applications and their statuses, secrets, connections, verification tags,
 * IndexNow keys, access rules, privacy and the AI settings are not in the list, so a template can
 * neither carry them nor set them. A field that is a secret or is kept on a person is dropped even
 * if a section adds one later.
 *
 * Applying is two steps. `review()` compares the template with each chosen site and writes nothing.
 * `apply()` writes only the changes the person accepted and only when they are still in a fresh
 * review. A setting the site's owner changed after setup is in the review, unticked. Each application
 * keeps what every site had before, so `undo()` can put it back; it leaves alone anything the site's
 * owner changed since.
 */
final class Templates {

	public const MAX_TEMPLATES = 50;
	public const MAX_APPLIED   = 10;
	public const NAME_MAX      = 80;

	/**
	 * The settings a template may hold: section => field names, or "*" for every field.
	 *
	 * @return array<string, string[]|string>
	 */
	public static function sections(): array {
		return array(
			'languages' => array( 'default', 'off' ),
			'general'   => array( 'index_first_delay', 'cache_ttl_minutes' ),
			'alerts'    => array( 'send_to', 'email_alerts' ),
			'markup'    => '*',
			'credits'   => '*',
		);
	}

	/**
	 * What a template sets, in the words the screen uses.
	 *
	 * @return array<string, string> Section => group heading.
	 */
	public static function groups(): array {
		return array(
			'languages' => __( 'Languages and the default language', 'estasoft-news-distribution' ),
			'general'   => __( 'The index-first delay and cache time', 'estasoft-news-distribution' ),
			'alerts'    => __( 'Alert rules and recipients', 'estasoft-news-distribution' ),
			'markup'    => __( 'Page markup and image rules', 'estasoft-news-distribution' ),
			'credits'   => __( 'Page markup and image rules', 'estasoft-news-distribution' ),
		);
	}

	/**
	 * Every saved template, newest first, without the stored values.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		$list = array();

		foreach ( $this->stored() as $template ) {
			$list[] = $this->view( $template );
		}

		usort(
			$list,
			static function ( array $a, array $b ): int {
				return strcmp( (string) $b['created'], (string) $a['created'] );
			}
		);

		return $list;
	}

	/**
	 * One template as stored.
	 *
	 * @param string $id Template ID.
	 * @return array<string, mixed>|null
	 */
	public function get( string $id ): ?array {
		$all = $this->stored();

		return isset( $all[ $id ] ) && is_array( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Saves a site's settings as a template.
	 *
	 * @param int    $blog_id Site to read.
	 * @param string $name    The template's name.
	 * @return array<string, mixed>|\WP_Error The template, or an error with `fields`.
	 */
	public function save_from_site( int $blog_id, string $name ) {
		$name = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $name ) ) );

		if ( '' === $name ) {
			return $this->invalid( 'name', __( 'Enter a name for this template.', 'estasoft-news-distribution' ) );
		}

		if ( mb_strlen( $name ) > self::NAME_MAX ) {
			return $this->invalid(
				'name',
				sprintf(
					/* translators: %d: the largest number of characters a template name can have. */
					_n( 'A template name can be at most %d character.', 'A template name can be at most %d characters.', self::NAME_MAX, 'estasoft-news-distribution' ),
					self::NAME_MAX
				)
			);
		}

		if ( ! get_site( $blog_id ) instanceof \WP_Site ) {
			return $this->invalid( 'site', __( 'That site does not exist.', 'estasoft-news-distribution' ) );
		}

		$all = $this->stored();

		if ( count( $all ) >= self::MAX_TEMPLATES ) {
			return $this->invalid(
				'name',
				sprintf(
					/* translators: %d: the most templates a network can keep. */
					__( 'A network can keep %d templates. Delete one first.', 'estasoft-news-distribution' ),
					self::MAX_TEMPLATES
				)
			);
		}

		$values = SiteLoop::in_site(
			$blog_id,
			static function (): array {
				return self::read_values( new Settings() );
			}
		);

		$id = 't' . bin2hex( random_bytes( 4 ) );

		$all[ $id ] = array(
			'id'      => $id,
			'name'    => $name,
			'created' => Clock::now(),
			'by'      => get_current_user_id(),
			'source'  => $blog_id,
			'values'  => $values,
		);

		Store::set( 'templates', $all );

		return $this->view( $all[ $id ] );
	}

	/**
	 * Deletes a template. Sites it was applied to keep their values.
	 *
	 * @param string $id Template ID.
	 * @return true|\WP_Error
	 */
	public function delete( string $id ) {
		$all = $this->stored();

		if ( ! isset( $all[ $id ] ) ) {
			return new \WP_Error( 'estasoft_nd_template_not_found', __( 'That template does not exist.', 'estasoft-news-distribution' ), array( 'status' => 404 ) );
		}

		unset( $all[ $id ] );
		Store::set( 'templates', $all );

		return true;
	}

	/**
	 * Compares a template with each chosen site. Writes nothing.
	 *
	 * @param string $template_id Template ID.
	 * @param int[]  $blog_ids    Sites to compare; at most SiteLoop::MAX_SITES.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function review( string $template_id, array $blog_ids ) {
		$template = $this->get( $template_id );

		if ( null === $template ) {
			return new \WP_Error( 'estasoft_nd_template_not_found', __( 'That template does not exist.', 'estasoft-news-distribution' ), array( 'status' => 404 ) );
		}

		$blog_ids = $this->valid_sites( $blog_ids );

		if ( array() === $blog_ids ) {
			return $this->invalid( 'sites', __( 'Choose at least one site.', 'estasoft-news-distribution' ) );
		}

		$target  = (array) ( $template['values'] ?? array() );
		$applied = $this->applied();
		$visit   = SiteLoop::visit(
			$blog_ids,
			function ( int $blog_id ) use ( $target, $applied ): array {
				return $this->compare( $blog_id, $target, $applied );
			}
		);

		$sites   = array();
		$changes = 0;

		foreach ( $blog_ids as $blog_id ) {
			$read = $visit['done'][ $blog_id ] ?? null;

			if ( ! is_array( $read ) ) {
				$sites[] = array(
					'id'      => $blog_id,
					'name'    => $this->site_name( $blog_id ),
					'changes' => array(),
					'error'   => is_wp_error( $read ) ? $read->get_error_message() : __( 'This site was not read.', 'estasoft-news-distribution' ),
				);
				continue;
			}

			$sites[]  = array(
				'id'      => $blog_id,
				'name'    => $read['name'],
				'changes' => $read['changes'],
				'error'   => '',
			);
			$changes += count( $read['changes'] );
		}

		return array(
			'template' => array(
				'id'   => (string) $template['id'],
				'name' => (string) $template['name'],
			),
			'sites'    => $sites,
			'changes'  => $changes,
			'left'     => count( $visit['left'] ),
		);
	}

	/**
	 * Writes the changes a person accepted.
	 *
	 * @param string   $template_id Template ID.
	 * @param string[] $accepted    Change keys from a review, as "site:section.field".
	 * @return array<string, mixed>|\WP_Error What happened: `id`, `sites`, `fields`, `skipped`.
	 */
	public function apply( string $template_id, array $accepted ) {
		$accepted = array_values( array_unique( array_map( 'strval', $accepted ) ) );
		$blogs    = array();

		foreach ( $accepted as $key ) {
			if ( 1 === preg_match( '/^([0-9]+):[a-z_]+\.[a-z_0-9]+$/', $key, $found ) ) {
				$blogs[] = (int) $found[1];
			}
		}

		$review = $this->review( $template_id, $blogs );

		if ( is_wp_error( $review ) ) {
			return $review;
		}

		$todo = array();

		foreach ( $review['sites'] as $site ) {
			foreach ( $site['changes'] as $change ) {
				if ( in_array( $change['key'], $accepted, true ) ) {
					$todo[ $site['id'] ][ $change['section'] ][ $change['field'] ] = $change['to'];
				}
			}
		}

		if ( array() === $todo ) {
			return $this->invalid( 'changes', __( 'There is nothing to apply. Choose at least one change from the review.', 'estasoft-news-distribution' ) );
		}

		$name  = (string) $review['template']['name'];
		$visit = SiteLoop::visit(
			array_keys( $todo ),
			static function ( int $blog_id ) use ( $todo, $name ): array {
				return self::write_site( $todo[ $blog_id ], $name );
			}
		);

		$record  = array();
		$skipped = array();
		$fields  = 0;

		foreach ( $visit['done'] as $blog_id => $result ) {
			if ( is_wp_error( $result ) ) {
				$skipped[] = array(
					'site'    => (int) $blog_id,
					'section' => '',
					'message' => $result->get_error_message(),
				);
				continue;
			}

			foreach ( $result['skipped'] as $item ) {
				$skipped[] = array_merge( array( 'site' => (int) $blog_id ), $item );
			}

			if ( array() !== $result['after'] ) {
				$record[ $blog_id ] = array(
					'before' => $result['before'],
					'after'  => $result['after'],
				);
				$fields            += $result['fields'];
			}
		}

		$id = 'a' . bin2hex( random_bytes( 4 ) );

		if ( array() !== $record ) {
			$applied   = $this->applied();
			$applied[] = array(
				'id'       => $id,
				'at'       => Clock::now(),
				'by'       => get_current_user_id(),
				'template' => (string) $review['template']['id'],
				'name'     => $name,
				'sites'    => $record,
			);

			Store::set( 'applied', array_slice( $applied, -self::MAX_APPLIED ) );
		}

		( new SiteSummaries() )->forget();

		// The sites changed, so the kept summaries are out of date.
		( new SiteSummaries() )->forget();

		return array(
			'id'      => array() === $record ? '' : $id,
			'sites'   => count( $record ),
			'fields'  => $fields,
			'skipped' => $skipped,
		);
	}

	/**
	 * Puts back what the last applications changed, except what a site's owner changed since.
	 *
	 * @param string $apply_id An application's ID.
	 * @return array<string, mixed>|\WP_Error `restored` (fields) and `skipped`.
	 */
	public function undo( string $apply_id ) {
		$applied = $this->applied();
		$entry   = null;

		foreach ( $applied as $index => $candidate ) {
			if ( isset( $candidate['id'] ) && $candidate['id'] === $apply_id ) {
				$entry = $candidate;
				unset( $applied[ $index ] );
				break;
			}
		}

		if ( null === $entry ) {
			return new \WP_Error( 'estasoft_nd_apply_not_found', __( 'There is nothing to undo for that change.', 'estasoft-news-distribution' ), array( 'status' => 404 ) );
		}

		$sites = (array) ( $entry['sites'] ?? array() );
		$visit = SiteLoop::visit(
			array_map( 'intval', array_keys( $sites ) ),
			static function ( int $blog_id ) use ( $sites ): array {
				return self::restore_site( (array) $sites[ $blog_id ] );
			}
		);

		$restored = 0;
		$skipped  = array();

		foreach ( $visit['done'] as $blog_id => $result ) {
			if ( is_wp_error( $result ) ) {
				$skipped[] = array(
					'site'    => (int) $blog_id,
					'section' => '',
					'message' => $result->get_error_message(),
				);
				continue;
			}

			$restored += $result['fields'];

			foreach ( $result['skipped'] as $item ) {
				$skipped[] = array_merge( array( 'site' => (int) $blog_id ), $item );
			}
		}

		Store::set( 'applied', array_values( $applied ) );
		( new SiteSummaries() )->forget();

		( new SiteSummaries() )->forget();

		return array(
			'restored' => $restored,
			'skipped'  => $skipped,
		);
	}

	/**
	 * The most recent application that can still be undone.
	 *
	 * @return array<string, mixed>|null `id`, `at` (ISO), `name`, `sites`.
	 */
	public function latest_applied(): ?array {
		$applied = $this->applied();
		$last    = end( $applied );

		if ( ! is_array( $last ) ) {
			return null;
		}

		return array(
			'id'    => (string) $last['id'],
			'at'    => Clock::iso( (int) $last['at'] ),
			'name'  => (string) $last['name'],
			'sites' => count( (array) $last['sites'] ),
		);
	}

	/**
	 * What every site has that a template holds: section => field => value.
	 *
	 * @param Settings $settings A settings reader for the current site.
	 * @return array<string, array<string, mixed>>
	 */
	private static function read_values( Settings $settings ): array {
		$values = array();

		foreach ( array_keys( self::sections() ) as $section ) {
			foreach ( self::fields_of( $settings, $section ) as $field => $spec ) {
				$values[ $section ][ $field ] = $settings->get( $section . '.' . $field );
			}
		}

		return $values;
	}

	/**
	 * The fields of a section a template may hold.
	 *
	 * @param Settings $settings A settings reader.
	 * @param string   $section  Section name.
	 * @return array<string, array<string, mixed>> Field => spec.
	 */
	private static function fields_of( Settings $settings, string $section ): array {
		$allowed = self::sections()[ $section ] ?? array();
		$object  = $settings->section_object( $section );

		if ( null === $object ) {
			return array();
		}

		$fields = array();

		foreach ( $object->fields() as $field => $spec ) {
			if ( '*' !== $allowed && ! in_array( $field, (array) $allowed, true ) ) {
				continue;
			}

			// Never a secret, never a value kept on a person.
			if ( 'secret' === ( $spec['type'] ?? '' ) || ( isset( $spec['storage'] ) && 0 === strpos( (string) $spec['storage'], 'user_meta:' ) ) ) {
				continue;
			}

			$fields[ (string) $field ] = $spec;
		}

		return $fields;
	}

	/**
	 * Compares the template with the current site. Call it inside `SiteLoop`.
	 *
	 * @param int                                 $blog_id The current site.
	 * @param array<string, array<string, mixed>> $target  The template's values.
	 * @param array<int, array<string, mixed>>    $applied Earlier applications.
	 * @return array{name: string, changes: array<int, array<string, mixed>>}
	 */
	private function compare( int $blog_id, array $target, array $applied ): array {
		$settings = new Settings();
		$changes  = array();
		$groups   = self::groups();

		foreach ( $target as $section => $fields ) {
			$specs = self::fields_of( $settings, (string) $section );

			foreach ( (array) $fields as $field => $to ) {
				if ( ! isset( $specs[ $field ] ) ) {
					continue;
				}

				$from = $settings->get( $section . '.' . $field );

				if ( $this->same( $from, $to ) ) {
					continue;
				}

				$default = $this->default_of( $settings, (string) $section, (string) $field );
				$last    = $this->last_applied( $applied, $blog_id, (string) $section, (string) $field );
				$changed = $last['found'] ? ! $this->same( $from, $last['value'] ) : ! $this->same( $from, $default );

				$changes[] = array(
					'key'           => $blog_id . ':' . $section . '.' . $field,
					'section'       => (string) $section,
					'field'         => (string) $field,
					'group'         => $groups[ $section ] ?? (string) $section,
					'label'         => (string) ( $specs[ $field ]['label'] ?? $field ),
					'from'          => $from,
					'to'            => $to,
					'from_text'     => self::text( $from ),
					'to_text'       => self::text( $to ),
					'owner_changed' => $changed,
				);
			}
		}

		$name = trim( (string) get_option( 'blogname', '' ) );

		return array(
			'name'    => '' !== $name ? $name : (string) $blog_id,
			'changes' => $changes,
		);
	}

	/**
	 * Writes values into the current site. Call it inside `SiteLoop`.
	 *
	 * @param array<string, array<string, mixed>> $todo Section => field => value.
	 * @param string                              $name Template name, for the site's audit trail.
	 * @return array{before: array<string, array<string, mixed>>, after: array<string, array<string, mixed>>, fields: int, skipped: array<int, array<string, string>>}
	 */
	private static function write_site( array $todo, string $name ): array {
		$settings = new Settings();
		$before   = array();
		$after    = array();
		$skipped  = array();
		$fields   = 0;

		foreach ( $todo as $section => $values ) {
			$specs = self::fields_of( $settings, (string) $section );
			$input = array_intersect_key( (array) $values, $specs );

			if ( array() === $input ) {
				continue;
			}

			foreach ( array_keys( $input ) as $field ) {
				$before[ $section ][ $field ] = $settings->get( $section . '.' . $field );
			}

			$saved = $settings->save_section( (string) $section, $input );

			if ( is_wp_error( $saved ) ) {
				unset( $before[ $section ] );
				$skipped[] = array(
					'section' => (string) $section,
					'message' => $saved->get_error_message(),
				);
				continue;
			}

			foreach ( array_keys( $input ) as $field ) {
				$after[ $section ][ $field ] = $saved[ $field ] ?? null;
			}

			$fields += count( $input );
		}

		if ( $fields > 0 && Tables::exists( ( new Audit() )->table() ) ) {
			( new Audit() )->record(
				Audit::SETTINGS,
				0,
				sprintf(
					/* translators: 1: a template name, 2: number of settings. */
					_n( 'Network template “%1$s” applied: %2$d setting changed.', 'Network template “%1$s” applied: %2$d settings changed.', $fields, 'estasoft-news-distribution' ),
					$name,
					$fields
				)
			);
		}

		return array(
			'before'  => $before,
			'after'   => $after,
			'fields'  => $fields,
			'skipped' => $skipped,
		);
	}

	/**
	 * Puts values back in the current site, field by field, where they are still what was applied.
	 *
	 * @param array<string, mixed> $record `before` and `after`, section => field => value.
	 * @return array{fields: int, skipped: array<int, array<string, string>>}
	 */
	private static function restore_site( array $record ): array {
		$settings = new Settings();
		$before   = (array) ( $record['before'] ?? array() );
		$after    = (array) ( $record['after'] ?? array() );
		$skipped  = array();
		$fields   = 0;

		foreach ( $before as $section => $values ) {
			$put = array();

			foreach ( (array) $values as $field => $old ) {
				$now = $settings->get( $section . '.' . $field );

				if ( wp_json_encode( $now ) === wp_json_encode( $after[ $section ][ $field ] ?? null ) ) {
					$put[ $field ] = $old;
					continue;
				}

				$skipped[] = array(
					'section' => (string) $section,
					'message' => sprintf(
						/* translators: %s: a setting's name. */
						__( '“%s” was changed on the site after the template, so it was left as it is.', 'estasoft-news-distribution' ),
						(string) $field
					),
				);
			}

			if ( array() === $put ) {
				continue;
			}

			$saved = $settings->save_section( (string) $section, $put );

			if ( is_wp_error( $saved ) ) {
				$skipped[] = array(
					'section' => (string) $section,
					'message' => $saved->get_error_message(),
				);
				continue;
			}

			$fields += count( $put );
		}

		return array(
			'fields'  => $fields,
			'skipped' => $skipped,
		);
	}

	/**
	 * The default of one setting.
	 *
	 * @param Settings $settings Settings reader.
	 * @param string   $section  Section.
	 * @param string   $field    Field.
	 * @return mixed
	 */
	private function default_of( Settings $settings, string $section, string $field ) {
		$object = $settings->section_object( $section );

		if ( null === $object ) {
			return null;
		}

		$defaults = $object->defaults();

		return $defaults[ $field ] ?? null;
	}

	/**
	 * The value a field had after the last time a template wrote it on a site.
	 *
	 * @param array<int, array<string, mixed>> $applied Earlier applications, oldest first.
	 * @param int                              $blog_id Site.
	 * @param string                           $section Section.
	 * @param string                           $field   Field.
	 * @return array{found: bool, value: mixed}
	 */
	private function last_applied( array $applied, int $blog_id, string $section, string $field ): array {
		foreach ( array_reverse( $applied ) as $entry ) {
			$after = $entry['sites'][ $blog_id ]['after'][ $section ] ?? null;

			if ( is_array( $after ) && array_key_exists( $field, $after ) ) {
				return array(
					'found' => true,
					'value' => $after[ $field ],
				);
			}
		}

		return array(
			'found' => false,
			'value' => null,
		);
	}

	/**
	 * Whether two setting values are the same.
	 *
	 * @param mixed $a One value.
	 * @param mixed $b Another.
	 * @return bool
	 */
	private function same( $a, $b ): bool {
		return wp_json_encode( $a ) === wp_json_encode( $b );
	}

	/**
	 * A setting value as a short plain text.
	 *
	 * @param mixed $value The value.
	 * @return string
	 */
	public static function text( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? __( 'On', 'estasoft-news-distribution' ) : __( 'Off', 'estasoft-news-distribution' );
		}

		if ( is_array( $value ) ) {
			$parts = array_map(
				static function ( $part ): string {
					return is_scalar( $part ) ? (string) $part : (string) wp_json_encode( $part );
				},
				$value
			);

			return array() === $parts ? __( 'None', 'estasoft-news-distribution' ) : implode( ', ', $parts );
		}

		if ( null === $value || '' === $value ) {
			return __( 'Empty', 'estasoft-news-distribution' );
		}

		return is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
	}

	/**
	 * A template without its stored values.
	 *
	 * @param array<string, mixed> $template As stored.
	 * @return array<string, mixed>
	 */
	private function view( array $template ): array {
		$counts = 0;

		foreach ( (array) ( $template['values'] ?? array() ) as $fields ) {
			$counts += count( (array) $fields );
		}

		return array(
			'id'       => (string) ( $template['id'] ?? '' ),
			'name'     => (string) ( $template['name'] ?? '' ),
			'created'  => ! empty( $template['created'] ) ? Clock::iso( (int) $template['created'] ) : '',
			'source'   => (int) ( $template['source'] ?? 0 ),
			'settings' => $counts,
		);
	}

	/**
	 * The stored templates by ID.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function stored(): array {
		$all = Store::get( 'templates', array() );

		return is_array( $all ) ? $all : array();
	}

	/**
	 * The stored applications, oldest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function applied(): array {
		$all = Store::get( 'applied', array() );

		return is_array( $all ) ? array_values( $all ) : array();
	}

	/**
	 * Keeps the IDs of sites that exist.
	 *
	 * @param array<int, mixed> $blog_ids Submitted IDs.
	 * @return int[]
	 */
	private function valid_sites( array $blog_ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $blog_ids ) ) ) );
		$ids = array_slice( $ids, 0, SiteLoop::MAX_SITES );

		return array_values(
			array_filter(
				$ids,
				static function ( int $id ): bool {
					return get_site( $id ) instanceof \WP_Site;
				}
			)
		);
	}

	/**
	 * A site's name without switching to it.
	 *
	 * @param int $blog_id Site ID.
	 * @return string
	 */
	private function site_name( int $blog_id ): string {
		$name = trim( (string) get_blog_option( $blog_id, 'blogname', '' ) );

		return '' !== $name ? $name : (string) $blog_id;
	}

	/**
	 * A "not saved" error that names the field.
	 *
	 * @param string $field   Field.
	 * @param string $message Plain message.
	 * @return \WP_Error
	 */
	private function invalid( string $field, string $message ): \WP_Error {
		return new \WP_Error(
			'estasoft_nd_invalid_value',
			$message,
			array(
				'status' => 400,
				'fields' => array( $field => $message ),
			)
		);
	}
}
