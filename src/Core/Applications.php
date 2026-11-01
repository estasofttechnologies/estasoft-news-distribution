<?php
/**
 * The application tracker: where the person stands with each platform.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin cannot see any platform's decision. Every status here is set by
 * hand. A reminder date that has passed turns the row to Needs attention.
 *
 * Stored in `estasoft_nd_applications` as `{ platform: { status, applied_on,
 * contact, reminder_on, note } }`.
 */
final class Applications {

	public const OPTION = 'estasoft_nd_applications';

	/**
	 * Fixed values for tests, or null to read the option.
	 *
	 * @var array<string, array<string, string>>|null
	 */
	private ?array $injected;

	/**
	 * The stored option for this request.
	 *
	 * @var array<string, array<string, string>>|null
	 */
	private ?array $stored = null;

	/**
	 * Creates the tracker.
	 *
	 * @param array<string, array<string, string>>|null $values Fixed values for tests.
	 */
	public function __construct( ?array $values = null ) {
		$this->injected = $values;
	}

	/**
	 * The platforms a person can record an application for, with their names.
	 *
	 * @return array<string, string> Platform id => name.
	 */
	public static function platforms(): array {
		$platforms = array(
			'microsoft-start' => 'Microsoft Start',
			'yahoo-news'      => 'Yahoo News',
			'apple-news'      => 'Apple News',
			'smartnews'       => 'SmartNews',
			'news-break'      => 'News Break',
			'ground-news'     => 'Ground News',
			'particle'        => 'Particle',
		);

		// Platforms the person added by hand carry their own name in their record.
		$stored = get_option( self::OPTION, array() );

		foreach ( is_array( $stored ) ? $stored : array() as $id => $row ) {
			if ( is_array( $row ) && isset( $row['name'] ) && '' !== (string) $row['name'] && ! isset( $platforms[ (string) $id ] ) ) {
				$platforms[ (string) $id ] = (string) $row['name'];
			}
		}

		/**
		 * Filters the platforms in the application tracker.
		 *
		 * @param array<string, string> $platforms Platform id => name.
		 */
		$filtered = apply_filters( 'estasoft_nd_application_platforms', $platforms );

		return is_array( $filtered ) ? $filtered : $platforms;
	}

	/**
	 * The status codes a person can pick.
	 *
	 * @return string[]
	 */
	public static function statuses(): array {
		return Status::codes_in( 'application' );
	}

	/**
	 * One platform's record, with defaults.
	 *
	 * @param string $platform Platform id.
	 * @return array{status: string, applied_on: string, contact: string, reminder_on: string, note: string}
	 */
	public function get( string $platform ): array {
		$row = $this->all()[ $platform ] ?? array();
		$row = is_array( $row ) ? $row : array();

		return array(
			'status'      => in_array( (string) ( $row['status'] ?? '' ), self::statuses(), true ) ? (string) $row['status'] : Status::NOT_REQUESTED,
			'applied_on'  => (string) ( $row['applied_on'] ?? '' ),
			'contact'     => (string) ( $row['contact'] ?? '' ),
			'reminder_on' => (string) ( $row['reminder_on'] ?? '' ),
			'note'        => (string) ( $row['note'] ?? '' ),
		);
	}

	/**
	 * The status shown for a platform: its hand-set status, or Needs attention
	 * when a reminder date has passed on an open application.
	 *
	 * @param string $platform Platform id.
	 * @return string A Status code.
	 */
	public function display_status( string $platform ): string {
		return $this->is_overdue( $platform ) ? Status::ATTENTION : $this->get( $platform )['status'];
	}

	/**
	 * Whether the reminder date has passed on an application that is still open.
	 *
	 * @param string $platform Platform id.
	 * @return bool
	 */
	public function is_overdue( string $platform ): bool {
		$row = $this->get( $platform );

		if ( ! in_array( $row['status'], array( Status::REQUESTED, Status::IN_REVIEW ), true ) || '' === $row['reminder_on'] ) {
			return false;
		}

		$due = date_create_immutable( $row['reminder_on'] . ' 23:59:59', Clock::zone() );

		return false !== $due && $due->getTimestamp() < Clock::now();
	}

	/**
	 * Validates and saves one platform's record.
	 *
	 * @param string               $platform Platform id.
	 * @param array<string, mixed> $input    Submitted values. Missing ones keep their value.
	 * @return array<string, string>|\WP_Error The saved record, or an error naming the field.
	 */
	public function save( string $platform, array $input ) {
		$name = null;

		if ( isset( $input['name'] ) ) {
			$name = mb_substr( trim( sanitize_text_field( (string) $input['name'] ) ), 0, 60 );
		}

		if ( ! isset( self::platforms()[ $platform ] ) && ( null === $name || '' === $name || 1 !== preg_match( '/^[a-z0-9-]{1,40}$/', $platform ) ) ) {
			return new \WP_Error( 'estasoft_nd_unknown_platform', __( 'That platform is not in the tracker.', 'estasoft-news-distribution' ), array( 'status' => 404 ) );
		}

		$row = $this->get( $platform );

		if ( array_key_exists( 'status', $input ) ) {
			if ( ! in_array( (string) $input['status'], self::statuses(), true ) ) {
				return $this->invalid( 'status', __( 'Choose one of the listed statuses.', 'estasoft-news-distribution' ) );
			}
			$row['status'] = (string) $input['status'];
		}

		foreach ( array(
			'applied_on'  => __( 'Applied on', 'estasoft-news-distribution' ),
			'reminder_on' => __( 'Remind me on', 'estasoft-news-distribution' ),
		) as $key => $label ) {
			if ( array_key_exists( $key, $input ) ) {
				$date = trim( (string) $input[ $key ] );

				if ( '' !== $date && ! self::is_date( $date ) ) {
					return $this->invalid(
						$key,
						/* translators: %s: the name of a date field. */
						sprintf( __( '%s must be a date such as 2026-10-21.', 'estasoft-news-distribution' ), $label )
					);
				}

				$row[ $key ] = $date;
			}
		}

		if ( array_key_exists( 'contact', $input ) ) {
			$row['contact'] = mb_substr( sanitize_text_field( (string) $input['contact'] ), 0, 200 );
		}

		if ( array_key_exists( 'note', $input ) ) {
			$row['note'] = mb_substr( sanitize_textarea_field( (string) $input['note'] ), 0, 1000 );
		}

		$all = $this->all();

		// A platform added by hand keeps its name next to its record.
		$kept = isset( $all[ $platform ]['name'] ) ? (string) $all[ $platform ]['name'] : '';

		if ( ! isset( self::base()[ $platform ] ) && null !== $name && '' !== $name ) {
			$kept = $name;
		}

		$all[ $platform ] = '' !== $kept ? array_merge( $row, array( 'name' => $kept ) ) : $row;
		$this->stored     = $all;

		if ( null === $this->injected ) {
			update_option( self::OPTION, $all, false );
		}

		return $row;
	}

	/**
	 * The ids of the platforms that ship with the plugin, without the ones a person added.
	 *
	 * @return array<string, bool>
	 */
	private static function base(): array {
		static $base = null;

		if ( null === $base ) {
			$base = array_fill_keys( array( 'microsoft-start', 'yahoo-news', 'apple-news', 'smartnews', 'news-break', 'ground-news', 'particle' ), true );
		}

		return $base;
	}

	/**
	 * A platform's name, or its id when it is not in the tracker.
	 *
	 * @param string $platform Platform id.
	 * @return string
	 */
	public static function name( string $platform ): string {
		return self::platforms()[ $platform ] ?? $platform;
	}

	/**
	 * Whether a platform was added by a person, so it can be removed again.
	 *
	 * @param string $platform Platform id.
	 * @return bool
	 */
	public static function is_custom( string $platform ): bool {
		$stored = get_option( self::OPTION, array() );

		return ! isset( self::base()[ $platform ] ) && is_array( $stored ) && isset( $stored[ $platform ]['name'] ) && '' !== (string) $stored[ $platform ]['name'];
	}

	/**
	 * Adds a platform to the tracker from a name and a link. Its status starts at
	 * Not requested.
	 *
	 * @param string $name Platform name, 1 to 60 characters.
	 * @param string $link Where to apply: a name, an email address or a link. May be empty.
	 * @return array{platform: string, record: array<string, string>}|\WP_Error The new id and its record, or an error naming the field.
	 */
	public function add( string $name, string $link = '' ) {
		$name = mb_substr( trim( sanitize_text_field( $name ) ), 0, 60 );

		if ( '' === $name ) {
			return $this->invalid( 'name', __( 'Give the platform a name.', 'estasoft-news-distribution' ) );
		}

		$known = array_map( 'strtolower', self::platforms() );

		if ( in_array( strtolower( $name ), $known, true ) ) {
			return $this->invalid( 'name', __( 'That platform is already in the tracker.', 'estasoft-news-distribution' ) );
		}

		$base = sanitize_title( $name );
		$base = '' === $base ? 'platform' : mb_substr( $base, 0, 36 );
		$id   = $base;
		$n    = 2;

		while ( isset( self::platforms()[ $id ] ) ) {
			$id = $base . '-' . $n;
			++$n;
		}

		$saved = $this->save(
			$id,
			array(
				'name'    => $name,
				'contact' => $link,
			)
		);

		return is_wp_error( $saved ) ? $saved : array(
			'platform' => $id,
			'record'   => $saved,
		);
	}

	/**
	 * Removes a platform a person added. The platforms that ship with the plugin stay.
	 *
	 * @param string $platform Platform id.
	 * @return bool Whether it was removed.
	 */
	public function remove( string $platform ): bool {
		if ( ! self::is_custom( $platform ) ) {
			return false;
		}

		$all = $this->all();
		unset( $all[ $platform ] );
		$this->stored = $all;

		if ( null === $this->injected ) {
			update_option( self::OPTION, $all, false );
		}

		return true;
	}

	/**
	 * Forgets the copy read earlier in this request. Tests call it after they reset the option.
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->stored = null;
	}

	/**
	 * Every stored record.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function all(): array {
		if ( null === $this->stored ) {
			$raw          = null !== $this->injected ? $this->injected : get_option( self::OPTION, array() );
			$this->stored = is_array( $raw ) ? $raw : array();
		}

		return $this->stored;
	}

	/**
	 * Whether a string is a real calendar date in Y-m-d form.
	 *
	 * @param string $value Text.
	 * @return bool
	 */
	public static function is_date( string $value ): bool {
		$date = \DateTime::createFromFormat( '!Y-m-d', $value );

		return false !== $date && $date->format( 'Y-m-d' ) === $value;
	}

	/**
	 * A date a person typed as a stored day. Accepts "5 Oct 2026", "5 October 2026"
	 * and "2026-10-05"; month names are English on purpose, so the text shown in a
	 * box is read back the same way whatever language the screen is in.
	 *
	 * @param string $text What was typed.
	 * @return string|null The day as Y-m-d, an empty string for no date, or null when it is not a date.
	 */
	public static function parse_date( string $text ): ?string {
		$text = trim( $text );

		if ( '' === $text ) {
			return '';
		}

		foreach ( array( '!j M Y', '!j F Y', '!Y-m-d' ) as $format ) {
			$date = \DateTimeImmutable::createFromFormat( $format, $text );
			$last = \DateTimeImmutable::getLastErrors();

			if ( false !== $date && ( false === $last || ( 0 === $last['warning_count'] && 0 === $last['error_count'] ) ) ) {
				return $date->format( 'Y-m-d' );
			}
		}

		return null;
	}

	/**
	 * A stored day as it is shown in a box: "5 Oct 2026". English month names, as parse_date() reads them.
	 *
	 * @param string $stored A stored day (Y-m-d), or an empty string.
	 * @return string
	 */
	public static function show_date( string $stored ): string {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $stored );

		return false === $date ? '' : $date->format( 'j M Y' );
	}

	/**
	 * A stored day as a short date in the site's language: "28 Oct", or "28 Oct 2025" when it is not this year.
	 *
	 * @param string $stored A stored day (Y-m-d), or an empty string.
	 * @return string An empty string when there is no day.
	 */
	public static function short_day( string $stored ): string {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $stored, Clock::zone() );

		if ( false === $date ) {
			return '';
		}

		$same_year = Clock::format( $date->getTimestamp(), 'Y' ) === Clock::format( Clock::now(), 'Y' );

		return Clock::format( $date->getTimestamp(), $same_year ? 'j M' : 'j M Y' );
	}

	/**
	 * An error that names one field.
	 *
	 * @param string $field   Field name.
	 * @param string $message Message.
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
