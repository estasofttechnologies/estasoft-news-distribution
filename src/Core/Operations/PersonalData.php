<?php
/**
 * WordPress's personal data export and erase tools, for the names News Distribution holds.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Operations;

use Estasoft\NewsDistribution\Core\Alerts;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Db\Schema;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * The only personal data News Distribution holds is about a site's own staff:
 *
 * - the audit trail and the assigned fixes keep a user ID;
 * - the AI log keeps the ID of the person who decided;
 * - a delivery log message can carry a person's display name (for example when
 *   someone publishes past the pre-publish gate and the reason is typed);
 * - the alert list and the application tracker can hold an email address or a name;
 * - a few settings are saved on the person's own profile.
 *
 * The exporter returns those, found by the email address WordPress gives it.
 * The eraser does not delete the history: it takes the person out of it. User
 * IDs become 0 (nobody), a display name in a log message becomes `[removed]`,
 * and an alert address or tracker contact that matches is cleared. What was sent
 * to a platform cannot be taken back, and the screen says so.
 *
 * Both tools work in pages of 100 rows and say when they are done, as WordPress
 * expects, so a person with a long history does not time out a request.
 *
 * Limits: a name is found in a delivery log message only when it is written there
 * exactly as the person's current display name, and only when it has at least
 * three characters.
 */
final class PersonalData {

	public const PER_PAGE = 100;

	private const PLACEHOLDER = '[removed]';

	private Plugin $plugin;

	/**
	 * Creates the tools.
	 *
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Adds the exporter. Hooked to `wp_privacy_personal_data_exporters`.
	 *
	 * @param mixed $exporters The exporters so far.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_exporter( $exporters ): array {
		$exporters = is_array( $exporters ) ? $exporters : array();

		$exporters['estasoft-news-distribution'] = array(
			'exporter_friendly_name' => __( 'News Distribution', 'estasoft-news-distribution' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Adds the eraser. Hooked to `wp_privacy_personal_data_erasers`.
	 *
	 * @param mixed $erasers The erasers so far.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_eraser( $erasers ): array {
		$erasers = is_array( $erasers ) ? $erasers : array();

		$erasers['estasoft-news-distribution'] = array(
			'eraser_friendly_name' => __( 'News Distribution', 'estasoft-news-distribution' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * What is held about the person with an email address.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( $email, $page = 1 ): array {
		global $wpdb;

		$email  = strtolower( trim( (string) $email ) );
		$page   = max( 1, (int) $page );
		$offset = ( $page - 1 ) * self::PER_PAGE;
		$user   = get_user_by( 'email', $email );
		$items  = array();
		$more   = false;

		if ( $user instanceof \WP_User ) {
			$id = (int) $user->ID;

			$rows = $this->rows( 'audit', array( 'user_id' ), $id, $offset );
			$more = $more || count( $rows ) >= self::PER_PAGE;

			foreach ( $rows as $row ) {
				$items[] = $this->item(
					'estasoft-nd-audit',
					__( 'News Distribution: actions you took', 'estasoft-news-distribution' ),
					'audit-' . $row['id'],
					array(
						__( 'Action', 'estasoft-news-distribution' )     => (string) $row['action'],
						__( 'Article ID', 'estasoft-news-distribution' ) => (string) $row['post_id'],
						__( 'Detail', 'estasoft-news-distribution' )     => (string) $row['detail'],
						__( 'Time', 'estasoft-news-distribution' )       => $this->when( (string) $row['created_gmt'] ),
					)
				);
			}

			$rows = $this->rows( 'fixes', array( 'assignee_id', 'assigned_by' ), $id, $offset );
			$more = $more || count( $rows ) >= self::PER_PAGE;

			foreach ( $rows as $row ) {
				$items[] = $this->item(
					'estasoft-nd-fixes',
					__( 'News Distribution: fixes assigned to you or by you', 'estasoft-news-distribution' ),
					'fix-' . $row['id'],
					array(
						__( 'Role', 'estasoft-news-distribution' )       => (int) $row['assignee_id'] === $id
							? __( 'The fix was assigned to you', 'estasoft-news-distribution' )
							: __( 'You assigned the fix', 'estasoft-news-distribution' ),
						__( 'Article ID', 'estasoft-news-distribution' ) => (string) $row['post_id'],
						__( 'Problem', 'estasoft-news-distribution' )    => (string) $row['code'],
						__( 'Status', 'estasoft-news-distribution' )     => (string) $row['status'],
						__( 'Time', 'estasoft-news-distribution' )       => $this->when( (string) $row['created_gmt'] ),
					)
				);
			}

			if ( in_array( 'ai_log', Schema::names(), true ) ) {
				$rows = $this->rows( 'ai_log', array( 'reviewer_id' ), $id, $offset );
				$more = $more || count( $rows ) >= self::PER_PAGE;

				foreach ( $rows as $row ) {
					$items[] = $this->item(
						'estasoft-nd-ai-log',
						__( 'News Distribution: AI suggestions you decided on', 'estasoft-news-distribution' ),
						'ai-' . $row['id'],
						array(
							__( 'Feature', 'estasoft-news-distribution' )    => (string) $row['feature'],
							__( 'Decision', 'estasoft-news-distribution' )   => (string) $row['decision'],
							__( 'Article ID', 'estasoft-news-distribution' ) => (string) $row['post_id'],
							__( 'Time', 'estasoft-news-distribution' )       => $this->when( (string) $row['created_gmt'] ),
						)
					);
				}
			}

			$name = $this->name_of( $user );

			if ( '' !== $name ) {
				$rows = $this->rows( 'delivery_log', array( 'message' ), '%' . $wpdb->esc_like( $name ) . '%', $offset );
				$more = $more || count( $rows ) >= self::PER_PAGE;

				foreach ( $rows as $row ) {
					$items[] = $this->item(
						'estasoft-nd-delivery',
						__( 'News Distribution: delivery log entries that name you', 'estasoft-news-distribution' ),
						'delivery-' . $row['id'],
						array(
							__( 'Article ID', 'estasoft-news-distribution' ) => (string) $row['post_id'],
							__( 'Channel', 'estasoft-news-distribution' )    => (string) $row['channel'],
							__( 'Message', 'estasoft-news-distribution' )    => (string) $row['message'],
							__( 'Time', 'estasoft-news-distribution' )       => $this->when( (string) $row['created_gmt'] ),
						)
					);
				}
			}

			if ( 1 === $page ) {
				$meta = array();

				foreach ( (array) get_user_meta( $id ) as $key => $values ) {
					if ( 0 === strpos( (string) $key, 'estasoft_nd_' ) ) {
						$meta[ (string) $key ] = wp_json_encode( maybe_unserialize( is_array( $values ) ? reset( $values ) : $values ) );
					}
				}

				if ( array() !== $meta ) {
					$items[] = $this->item( 'estasoft-nd-profile', __( 'News Distribution: settings saved on your profile', 'estasoft-news-distribution' ), 'profile-' . $id, $meta );
				}
			}
		}

		if ( 1 === $page ) {
			foreach ( $this->plugin->make( Alerts::class )->addresses() as $address ) {
				if ( strtolower( $address ) === $email ) {
					$items[] = $this->item(
						'estasoft-nd-alerts',
						__( 'News Distribution: alert address', 'estasoft-news-distribution' ),
						'alert-address',
						array( __( 'Email address on the alert list', 'estasoft-news-distribution' ) => $address )
					);
				}
			}

			$needles = $this->needles( $email, $user instanceof \WP_User ? $user : null );

			foreach ( $this->plugin->applications()->all() as $platform => $record ) {
				if ( is_array( $record ) && $this->matches( (string) ( $record['contact'] ?? '' ), $needles ) ) {
					$items[] = $this->item(
						'estasoft-nd-tracker',
						__( 'News Distribution: application tracker', 'estasoft-news-distribution' ),
						'tracker-' . $platform,
						array(
							__( 'Platform', 'estasoft-news-distribution' ) => (string) $platform,
							__( 'Contact', 'estasoft-news-distribution' )  => (string) $record['contact'],
						)
					);
				}
			}
		}

		return array(
			'data' => $items,
			'done' => ! $more,
		);
	}

	/**
	 * Takes the person out of what is held. Nothing else is deleted.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( $email, $page = 1 ): array {
		global $wpdb;

		$email   = strtolower( trim( (string) $email ) );
		$user    = get_user_by( 'email', $email );
		$changed = 0;
		$more    = false;

		if ( $user instanceof \WP_User ) {
			$id = (int) $user->ID;

			// The table and the column that holds the user, from a fixed list.
			$jobs = array(
				array( 'audit', 'user_id' ),
				array( 'fixes', 'assignee_id' ),
				array( 'fixes', 'assigned_by' ),
			);

			if ( in_array( 'ai_log', Schema::names(), true ) ) {
				$jobs[] = array( 'ai_log', 'reviewer_id' );
			}

			foreach ( $jobs as $job ) {
				$table = Schema::table( $job[0] );

				$done = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare( 'UPDATE %i SET %i = 0 WHERE %i = %d LIMIT %d', $table, $job[1], $job[1], $id, self::PER_PAGE * 10 )
				);

				$done     = is_int( $done ) ? $done : 0;
				$changed += $done;
				$more     = $more || $done >= self::PER_PAGE * 10;
			}

			$name = $this->name_of( $user );

			if ( '' !== $name ) {
				$table = Schema::table( 'delivery_log' );
				$done  = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare( 'UPDATE %i SET message = REPLACE( message, %s, %s ) WHERE message LIKE %s LIMIT %d', $table, $name, self::PLACEHOLDER, '%' . $wpdb->esc_like( $name ) . '%', self::PER_PAGE * 10 ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				);

				$done     = is_int( $done ) ? $done : 0;
				$changed += $done;
				$more     = $more || $done >= self::PER_PAGE * 10;
			}

			foreach ( array_keys( (array) get_user_meta( $id ) ) as $key ) {
				if ( 0 === strpos( (string) $key, 'estasoft_nd_' ) ) {
					delete_user_meta( $id, (string) $key );
					++$changed;
				}
			}
		}

		$changed += $this->erase_alert_address( $email );
		$changed += $this->erase_tracker_contacts( $this->needles( $email, $user instanceof \WP_User ? $user : null ) );

		return array(
			'items_removed'  => $changed > 0,
			'items_retained' => false,
			'messages'       => $changed > 0
				? array( __( 'News Distribution took the person out of its audit trail, fixes, logs, alert list and application tracker. What was already sent to a platform cannot be taken back.', 'estasoft-news-distribution' ) )
				: array(),
			'done'           => ! $more,
		);
	}

	/**
	 * Removes an address from the alert list.
	 *
	 * @param string $email Email address, lower case.
	 * @return int 1 when it was on the list, else 0.
	 */
	private function erase_alert_address( string $email ): int {
		$parsed = Alerts::parse_addresses( (string) $this->plugin->settings()->get( 'alerts.send_to', '' ) );
		$kept   = array_values(
			array_filter(
				$parsed['valid'],
				static function ( string $address ) use ( $email ): bool {
					return strtolower( $address ) !== $email;
				}
			)
		);

		if ( count( $kept ) === count( $parsed['valid'] ) ) {
			return 0;
		}

		$saved = $this->plugin->settings()->save_section( 'alerts', array( 'send_to' => implode( ', ', $kept ) ) );

		return is_wp_error( $saved ) ? 0 : 1;
	}

	/**
	 * Clears the contact of every tracker entry that is about the person.
	 *
	 * @param string[] $needles What counts as the person: the email address and the display name.
	 * @return int Entries cleared.
	 */
	private function erase_tracker_contacts( array $needles ): int {
		$cleared = 0;

		foreach ( $this->plugin->applications()->all() as $platform => $record ) {
			if ( ! is_array( $record ) || ! $this->matches( (string) ( $record['contact'] ?? '' ), $needles ) ) {
				continue;
			}

			$saved = $this->plugin->applications()->save( (string) $platform, array( 'contact' => '' ) );

			if ( ! is_wp_error( $saved ) ) {
				++$cleared;
			}
		}

		return $cleared;
	}

	/**
	 * What identifies a person in free text: the address and the display name.
	 *
	 * @param string        $email Lower case email address.
	 * @param \WP_User|null $user  The user, if the address belongs to one.
	 * @return string[]
	 */
	private function needles( string $email, ?\WP_User $user ): array {
		$needles = array( $email );

		if ( null !== $user && '' !== $this->name_of( $user ) ) {
			$needles[] = strtolower( $this->name_of( $user ) );
		}

		return $needles;
	}

	/**
	 * Whether a text names any of the needles.
	 *
	 * @param string   $text    The text.
	 * @param string[] $needles See `needles()`.
	 * @return bool
	 */
	private function matches( string $text, array $needles ): bool {
		$text = strtolower( $text );

		foreach ( $needles as $needle ) {
			if ( '' !== $needle && false !== strpos( $text, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The name to look for in a log message, or an empty string when it is too short to be searched for safely.
	 *
	 * @param \WP_User $user The user.
	 * @return string
	 */
	private function name_of( \WP_User $user ): string {
		$name = trim( (string) $user->display_name );

		return mb_strlen( $name ) >= 3 ? $name : '';
	}

	/**
	 * Reads one page of rows from one of the plugin's tables.
	 *
	 * @param string             $name    Short table name.
	 * @param array<int, string> $columns Columns to match, from a fixed list in this file. A row counts when any of them matches.
	 * @param int|string         $value   A whole number matches exactly; text is a LIKE pattern.
	 * @param int                $offset  First row.
	 * @return array<int, array<string, mixed>>
	 */
	private function rows( string $name, array $columns, $value, int $offset ): array {
		global $wpdb;

		$table = Schema::table( $name );
		$parts = array();

		foreach ( $columns as $column ) {
			$parts[] = is_int( $value ) ? $wpdb->prepare( '%i = %d', $column, $value ) : $wpdb->prepare( '%i LIKE %s', $column, $value );
		}

		$sql  = $wpdb->prepare( 'SELECT * FROM %i WHERE ( ', $table ) . implode( ' OR ', $parts ) . $wpdb->prepare( ' ) ORDER BY id ASC LIMIT %d OFFSET %d', self::PER_PAGE, $offset );
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Put together from prepared parts above.

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * One item of the export.
	 *
	 * @param string                $group_id    Group key.
	 * @param string                $group_label Group name.
	 * @param string                $item_id     Item key.
	 * @param array<string, string> $data        Name and value pairs.
	 * @return array<string, mixed>
	 */
	private function item( string $group_id, string $group_label, string $item_id, array $data ): array {
		$pairs = array();

		foreach ( $data as $name => $value ) {
			$pairs[] = array(
				'name'  => (string) $name,
				'value' => (string) $value,
			);
		}

		return array(
			'group_id'    => $group_id,
			'group_label' => $group_label,
			'item_id'     => 'estasoft-nd-' . $item_id,
			'data'        => $pairs,
		);
	}

	/**
	 * A database time as text in the site's time zone.
	 *
	 * @param string $gmt Database value, in UTC.
	 * @return string
	 */
	private function when( string $gmt ): string {
		$time = Clock::from_gmt( $gmt );

		return 0 === $time ? '' : Clock::datetime( $time );
	}
}
