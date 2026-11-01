<?php
/**
 * Plugin settings: one option, one key per section.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

use Estasoft\NewsDistribution\Core\Settings\Input;
use Estasoft\NewsDistribution\Core\Settings\SecretInput;
use Estasoft\NewsDistribution\Core\Settings\Section;
use Estasoft\NewsDistribution\Core\Settings\Validator;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Support\Discovery;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and saves the settings option.
 *
 * `estasoft_nd_settings` holds one key per section (general, access, ...).
 * Each section saves only its own key, so two people saving two pages never
 * overwrite each other. Fields are declared by the section classes in
 * src/Core/Settings/Sections/, which are found by folder.
 *
 * Pass an array of section => values to the constructor to use fixed values,
 * for example in unit tests.
 */
final class Settings {

	public const OPTION = 'estasoft_nd_settings';

	/**
	 * Fixed values for tests, or null to read the option.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $injected;

	/**
	 * The stored option for this request.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $stored = null;

	/**
	 * Section objects by name.
	 *
	 * @var array<string, Section>|null
	 */
	private ?array $sections = null;

	/**
	 * Resolved values (defaults plus stored) by section.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $resolved = array();

	/**
	 * Creates the settings reader.
	 *
	 * @param array<string, array<string, mixed>>|null $values Fixed values keyed by section, or null to read the option.
	 */
	public function __construct( ?array $values = null ) {
		$this->injected = $values;
	}

	/**
	 * The section objects, keyed by name.
	 *
	 * @return array<string, Section>
	 */
	public function sections(): array {
		if ( null === $this->sections ) {
			$this->sections = array();

			foreach ( Discovery::classes( 'Core/Settings/Sections' ) as $class ) {
				if ( ! class_exists( $class ) || ! is_subclass_of( $class, Section::class ) ) {
					continue;
				}

				$section = new $class();

				if ( '' !== $section->name() ) {
					$this->sections[ $section->name() ] = $section;
				}
			}
		}

		return $this->sections;
	}

	/**
	 * One section object.
	 *
	 * @param string $name Section name.
	 * @return Section|null Null when no section has that name.
	 */
	public function section_object( string $name ): ?Section {
		$sections = $this->sections();
		return $sections[ $name ] ?? null;
	}

	/**
	 * The values of one section, with defaults filled in.
	 *
	 * @param string $name Section name.
	 * @return array<string, mixed>
	 */
	public function section( string $name ): array {
		if ( isset( $this->resolved[ $name ] ) ) {
			return $this->resolved[ $name ];
		}

		$object   = $this->section_object( $name );
		$defaults = null === $object ? array() : $object->defaults();
		$stored   = $this->stored_section( $name );

		// Unknown keys are dropped, so an old or edited option cannot add fields.
		$values = $defaults;
		foreach ( $stored as $key => $value ) {
			if ( null === $object || array_key_exists( $key, $defaults ) ) {
				$values[ $key ] = $value;
			}
		}

		$this->resolved[ $name ] = $values;

		return $values;
	}

	/**
	 * One setting, by dotted path.
	 *
	 * @param string $path    Section and field, such as "general.cache_ttl_minutes". Deeper keys are allowed.
	 * @param mixed  $fallback Returned when the path does not exist.
	 * @return mixed
	 */
	public function get( string $path, $fallback = null ) {
		$parts   = explode( '.', $path );
		$section = array_shift( $parts );
		$value   = $this->section( (string) $section );

		foreach ( $parts as $part ) {
			if ( ! is_array( $value ) || ! array_key_exists( $part, $value ) ) {
				return $fallback;
			}
			$value = $value[ $part ];
		}

		return $value;
	}

	/**
	 * Validates and saves one section.
	 *
	 * Fields missing from the input keep their stored value. A value outside
	 * its range is not saved and the error names the field and the allowed range.
	 *
	 * @param string               $name  Section name.
	 * @param array<string, mixed> $input Submitted values.
	 * @return array<string, mixed>|\WP_Error The saved section, or an error with `fields` in its data.
	 */
	public function save_section( string $name, array $input ) {
		$object = $this->section_object( $name );

		if ( null === $object ) {
			return new \WP_Error(
				'estasoft_nd_unknown_section',
				__( 'That settings section does not exist.', 'estasoft-news-distribution' ),
				array( 'status' => 404 )
			);
		}

		$previous = $this->section( $name );
		$fields   = $object->fields();
		$input    = Input::normalise( $fields, $object->prepare_input( $input ) );

		// Fields saved on the person, and secrets, are handled separately from the site's option.
		$site_fields   = array();
		$user_fields   = array();
		$secret_fields = array();
		foreach ( $fields as $field => $spec ) {
			if ( 'secret' === ( $spec['type'] ?? '' ) ) {
				$secret_fields[ $field ] = $spec;
			} elseif ( isset( $spec['storage'] ) && 0 === strpos( (string) $spec['storage'], 'user_meta:' ) ) {
				$user_fields[ $field ] = $spec;
			} else {
				$site_fields[ $field ] = $spec;
			}
		}

		// A section can keep its secrets for a stricter permission than the section itself (Alerts: administrators).
		$secret_rule = $object->secret_permission();

		if ( '' !== $secret_rule && SecretInput::touches( $secret_fields, $input ) && ! Plugin::instance()->access()->can( $secret_rule ) ) {
			return new \WP_Error(
				'estasoft_nd_secrets_need_admin',
				__( 'Only an administrator can change a saved key, token or address. Ask one to enter it.', 'estasoft-news-distribution' ),
				array( 'status' => 403 )
			);
		}

		$cleaned = Validator::clean( $site_fields, $input, $previous );
		$errors  = $cleaned['errors'];

		if ( array() === $errors ) {
			$errors = $object->cross_check( $cleaned['values'] );
		}

		$user_values = array();
		if ( array() === $errors && array() !== $user_fields ) {
			$user_current = array();
			foreach ( $user_fields as $field => $spec ) {
				$user_current[ $field ] = self::user_value( $spec );
			}
			$user_clean  = Validator::clean( $user_fields, $input, $user_current );
			$errors      = $user_clean['errors'];
			$user_values = $user_clean['values'];
		}

		$secret_changes = array();
		if ( array() === $errors ) {
			foreach ( $secret_fields as $field => $spec ) {
				$label = (string) ( $spec['label'] ?? $field );
				$max   = (int) ( $spec['max'] ?? 500 );

				if ( ! empty( $input[ $field . '__remove' ] ) ) {
					$secret_changes[ $field ] = '';
					continue;
				}

				if ( ! array_key_exists( $field, $input ) || '' === $input[ $field ] || null === $input[ $field ] ) {
					continue;
				}

				if ( ! is_scalar( $input[ $field ] ) ) {
					$errors[ $field ] = sprintf(
						/* translators: %s: the name of a setting. */
						__( '%s must be text.', 'estasoft-news-distribution' ),
						$label
					);
					continue;
				}

				$value = trim( (string) $input[ $field ] );

				if ( mb_strlen( $value ) > $max ) {
					$errors[ $field ] = sprintf(
						/* translators: 1: the name of a setting, 2: the largest allowed number of characters. */
						_n( '%1$s can be at most %2$d character.', '%1$s can be at most %2$d characters.', $max, 'estasoft-news-distribution' ),
						$label,
						$max
					);
					continue;
				}

				$secret_changes[ $field ] = $value;
			}

			$errors = array_merge( $errors, $object->check_secrets( array_filter( $secret_changes, 'strlen' ) ) );
		}

		if ( array() !== $errors ) {
			return new \WP_Error(
				'estasoft_nd_invalid_value',
				(string) reset( $errors ),
				array(
					'status' => 400,
					'fields' => $errors,
				)
			);
		}

		foreach ( $user_fields as $field => $spec ) {
			$key = substr( (string) $spec['storage'], strlen( 'user_meta:' ) );
			if ( get_current_user_id() > 0 ) {
				update_user_meta( get_current_user_id(), $key, $user_values[ $field ] );
			}
		}

		$secrets = new Secrets();
		foreach ( $secret_changes as $field => $value ) {
			$secrets->set( $name . '_' . $field, $value );
		}

		$stored          = $this->stored_all();
		$stored[ $name ] = $cleaned['values'];
		update_option( self::OPTION, $stored, false );

		$this->stored = $stored;
		unset( $this->resolved[ $name ] );

		$saved = $this->section( $name );
		$saved = array_merge( $saved, $user_values );

		if ( $object->affects_output() ) {
			/**
			 * Fires when a saved setting changes what feeds and sitemaps print.
			 *
			 * The output cache listens to this and drops its copies.
			 *
			 * @param string $name The section that changed.
			 */
			do_action( 'estasoft_nd_output_changed', $name );
		}

		$object->saved( $saved, $previous );

		/**
		 * Fires after a settings section was saved.
		 *
		 * @param string               $name     Section name.
		 * @param array<string, mixed> $saved    Saved values.
		 * @param array<string, mixed> $previous Values before the save.
		 */
		do_action( 'estasoft_nd_settings_saved', $name, $saved, $previous );

		return $saved;
	}

	/**
	 * The section as the screen needs it: stored values plus the values kept on the person.
	 *
	 * @param string $name Section name.
	 * @return array<string, mixed>
	 */
	public function section_for_screen( string $name ): array {
		$values = $this->section( $name );
		$object = $this->section_object( $name );

		if ( null !== $object ) {
			$secrets = new Secrets();

			foreach ( $object->fields() as $field => $spec ) {
				if ( 'secret' === ( $spec['type'] ?? '' ) ) {
					// A saved secret is never sent back. The screen gets a mask and a flag.
					$values[ $field ]             = $secrets->masked( $name . '_' . $field );
					$values[ $field . '_is_set' ] = $secrets->has( $name . '_' . $field );
				} elseif ( isset( $spec['storage'] ) && 0 === strpos( (string) $spec['storage'], 'user_meta:' ) ) {
					$values[ $field ] = self::user_value( $spec );
				}
			}
		}

		return $values;
	}

	/**
	 * Puts one section back to its defaults.
	 *
	 * @param string $name Section name.
	 * @return bool False when the section does not exist.
	 */
	public function reset_section( string $name ): bool {
		$object = $this->section_object( $name );

		if ( null === $object ) {
			return false;
		}

		$stored = $this->stored_all();
		unset( $stored[ $name ] );
		update_option( self::OPTION, $stored, false );

		$this->stored = $stored;
		unset( $this->resolved[ $name ] );

		do_action( 'estasoft_nd_output_changed', $name );
		do_action( 'estasoft_nd_settings_saved', $name, $this->section( $name ), array() );

		return true;
	}

	/**
	 * Every stored section, as saved. Used by the backup.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function export(): array {
		$out = array();

		foreach ( array_keys( $this->sections() ) as $name ) {
			$out[ $name ] = $this->section( $name );
		}

		return $out;
	}

	/**
	 * Forgets values read in this request, for example after an import.
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->stored   = null;
		$this->resolved = array();
	}

	/**
	 * Publication name for a language, falling back to the main name, then the site title.
	 *
	 * Google News requires the name to match the one shown on news.google.com.
	 *
	 * @param string $language Language code, or an empty string for the main name.
	 * @return string
	 */
	public function publication_name( string $language = '' ): string {
		if ( '' !== $language ) {
			$names = $this->get( 'general.names', array() );
			$name  = is_array( $names ) && isset( $names[ $language ] ) ? trim( (string) $names[ $language ] ) : '';

			if ( '' !== $name ) {
				return $name;
			}
		}

		$name = trim( (string) $this->get( 'general.publication_name', '' ) );

		if ( '' === $name && function_exists( 'get_bloginfo' ) ) {
			$name = trim( (string) get_bloginfo( 'name' ) );
		}

		return $name;
	}

	/**
	 * Post types that produce events.
	 *
	 * @return string[]
	 */
	public function post_types(): array {
		$types = array_values( array_filter( array_map( 'strval', (array) $this->get( 'general.post_types', array() ) ) ) );
		return array() === $types ? array( 'post' ) : $types;
	}

	/**
	 * Feed cache lifetime in seconds, from 1 to 60 minutes.
	 *
	 * @return int
	 */
	public function cache_ttl(): int {
		return max( 1, min( 60, (int) $this->get( 'general.cache_ttl_minutes', 5 ) ) ) * MINUTE_IN_SECONDS;
	}

	/**
	 * Index-first delay in seconds: how long partner feeds and outlets wait after publishing.
	 *
	 * @return int
	 */
	public function index_first_delay_seconds(): int {
		return max( 0, min( 120, (int) $this->get( 'general.index_first_delay', 15 ) ) ) * MINUTE_IN_SECONDS;
	}

	/**
	 * Days the delivery log and the fetch log are kept.
	 *
	 * @return int
	 */
	public function log_days(): int {
		return max( 7, min( 90, (int) $this->get( 'general.log_days', 30 ) ) );
	}

	/**
	 * Whether uninstalling should delete the plugin's data.
	 *
	 * @return bool
	 */
	public function delete_data_on_uninstall(): bool {
		return (bool) $this->get( 'data.delete_on_uninstall', false );
	}

	/**
	 * The whole option as stored.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function stored_all(): array {
		if ( null === $this->stored ) {
			$raw          = null !== $this->injected ? $this->injected : get_option( self::OPTION, array() );
			$this->stored = is_array( $raw ) ? $raw : array();
		}

		return $this->stored;
	}

	/**
	 * The stored values of one section.
	 *
	 * @param string $name Section name.
	 * @return array<string, mixed>
	 */
	private function stored_section( string $name ): array {
		$all = $this->stored_all();
		return isset( $all[ $name ] ) && is_array( $all[ $name ] ) ? $all[ $name ] : array();
	}

	/**
	 * Reads a field kept on the current person.
	 *
	 * @param array<string, mixed> $spec Field spec with a user_meta storage.
	 * @return mixed
	 */
	private static function user_value( array $spec ) {
		$key   = substr( (string) $spec['storage'], strlen( 'user_meta:' ) );
		$value = get_current_user_id() > 0 ? get_user_meta( get_current_user_id(), $key, true ) : '';

		if ( '' === $value || null === $value || false === $value ) {
			return Validator::default_of( $spec );
		}

		return $value;
	}
}
