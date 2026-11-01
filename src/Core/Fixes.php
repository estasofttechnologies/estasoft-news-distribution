<?php
/**
 * Fixes handed to a person: assign, remind, reassign, mark done.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

use Estasoft\NewsDistribution\Checks\ArticleChecker;
use Estasoft\NewsDistribution\Core\Db\Schema;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * A fix is one problem the article check found, given to one person, with an
 * optional note and an optional date. It lives in the `fixes` table. Assigning
 * sends the person an email through `Core\Alerts` when they have an address and
 * the alert type is on, and keeps track: it cannot make anyone do the fix, and
 * it does not change the article.
 *
 * Who sees what: an editor sees every fix of an article. A person who may only
 * see their own articles (an author) sees only the fixes assigned to them.
 *
 * Get it with `Plugin::instance()->make( Fixes::class )`.
 */
final class Fixes {

	public const OPEN      = 'open';
	public const DONE      = 'done';
	public const DISMISSED = 'dismissed';

	/**
	 * The longest note, in characters.
	 */
	public const NOTE_MAX = 500;

	/**
	 * Seconds that must pass between two reminders for one fix.
	 */
	public const REMIND_GAP = 300;

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
	 * The fixes of one article, newest first.
	 *
	 * @param int      $post_id Article ID.
	 * @param int|null $viewer  Person looking, or the signed-in user. A person who may not see others' articles only gets their own fixes.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_article( int $post_id, ?int $viewer = null ): array {
		global $wpdb;

		$viewer = null === $viewer ? get_current_user_id() : $viewer;
		$table  = Schema::table( 'fixes' );
		$rows   = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT * FROM %i WHERE post_id = %d ORDER BY id DESC LIMIT 200', $table, $post_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$only   = $this->plugin->access()->sees_only_own( $viewer );
		$out    = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( $only && (int) $row['assignee_id'] !== $viewer ) {
				continue;
			}

			$out[] = $this->shape( $row );
		}

		return $out;
	}

	/**
	 * The open fixes assigned to one person, newest first.
	 *
	 * @param int $user_id Person.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_user( int $user_id ): array {
		global $wpdb;

		$table = Schema::table( 'fixes' );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT * FROM %i WHERE assignee_id = %d AND status = %s ORDER BY id DESC LIMIT 100', $table, $user_id, self::OPEN ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$out   = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = $this->shape( $row );
		}

		return $out;
	}

	/**
	 * One fix.
	 *
	 * @param int $id Fix ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		$row = $this->row( $id );

		return null === $row ? null : $this->shape( $row );
	}

	/**
	 * Whether a fix is still open.
	 *
	 * @param int $id Fix ID.
	 * @return bool
	 */
	public function is_open( int $id ): bool {
		$row = $this->row( $id );

		return null !== $row && self::OPEN === $row['status'];
	}

	/**
	 * Whether a person may see one fix: an editor, or the person it is assigned to.
	 *
	 * @param array<string, mixed> $fix  A fix from find().
	 * @param int|null             $user Person, or the signed-in user.
	 * @return bool
	 */
	public function can_see( array $fix, ?int $user = null ): bool {
		$user = null === $user ? get_current_user_id() : $user;

		return $user > 0 && ( $this->plugin->access()->can( Access::VIEW, null, $user ) || (int) $fix['assignee']['id'] === $user );
	}

	/**
	 * Who can be handed a fix on an article, with how to label them in a list.
	 *
	 * Only people who can open Article check under the site's current choice are offered.
	 *
	 * @param int $post_id Article ID.
	 * @return array<int, string> User ID => "Omar Ali (author)".
	 */
	public function assignable( int $post_id ): array {
		$post    = get_post( $post_id );
		$author  = $post instanceof \WP_Post ? (int) $post->post_author : 0;
		$current = get_current_user_id();
		$out     = array();

		foreach ( $this->plugin->access()->assignable_users() as $user ) {
			if ( (int) $user->ID === $current ) {
				$note = __( 'you', 'estasoft-news-distribution' );
			} elseif ( (int) $user->ID === $author ) {
				$note = __( 'author', 'estasoft-news-distribution' );
			} else {
				$note = $this->role_of( $user );
			}

			$out[ (int) $user->ID ] = '' === $note ? $user->display_name : sprintf(
				/* translators: 1: a person's name, 2: their role such as "editor". */
				__( '%1$s (%2$s)', 'estasoft-news-distribution' ),
				$user->display_name,
				$note
			);
		}

		return $out;
	}

	/**
	 * The finding a code stands for in the article's stored check.
	 *
	 * @param int    $post_id Article ID.
	 * @param string $code    Finding code.
	 * @return array<string, mixed>|null Null when the check no longer has it.
	 */
	public function finding( int $post_id, string $code ): ?array {
		$report = $this->plugin->make( ArticleChecker::class )->stored( $post_id );

		if ( null === $report ) {
			return null;
		}

		foreach ( $report->findings() as $finding ) {
			if ( (string) ( $finding['code'] ?? '' ) === $code ) {
				return $finding;
			}
		}

		return null;
	}

	/**
	 * Hands a problem to a person. When the problem already has an open fix, that fix goes to the new person.
	 *
	 * @param int                  $post_id Article ID.
	 * @param array<string, mixed> $input   code, assignee, note, email (default true), due (a date, a time or an ISO time).
	 * @param int|null             $by      Person assigning, or the signed-in user.
	 * @return array<string, mixed>|\WP_Error The fix, with `notified`: the email's result.
	 */
	public function assign( int $post_id, array $input, ?int $by = null ) {
		$post = $this->post( $post_id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$by       = null === $by ? get_current_user_id() : $by;
		$code     = isset( $input['code'] ) ? sanitize_text_field( (string) $input['code'] ) : '';
		$assignee = isset( $input['assignee'] ) ? (int) $input['assignee'] : 0;
		$note     = $this->note( $input['note'] ?? '' );
		$finding  = '' === $code ? null : $this->finding( $post_id, $code );

		if ( null === $finding ) {
			return $this->fail( 'estasoft_nd_unknown_problem', __( 'That problem is not in the latest check of this article. Run the check again.', 'estasoft-news-distribution' ), 422, array( 'code' ) );
		}

		$person = $this->person( $post_id, $assignee );

		if ( is_wp_error( $person ) ) {
			return $person;
		}

		$due = $this->due( $input['due'] ?? '' );

		if ( is_wp_error( $due ) ) {
			return $due;
		}

		$existing = $this->open_for( $post_id, $code );
		$channel  = isset( $finding['channels'][0] ) ? sanitize_key( (string) $finding['channels'][0] ) : '';

		if ( null === $existing ) {
			$id = $this->insert( $post_id, $code, $channel, $assignee, $by, $note, $due );
		} else {
			$id = (int) $existing['id'];
			$this->update(
				$id,
				array(
					'assignee_id' => $assignee,
					'assigned_by' => $by,
					'note'        => $note,
					'due_gmt'     => 0 === $due ? null : Clock::to_gmt( $due ),
				)
			);
		}

		if ( $id < 1 ) {
			return $this->fail( 'estasoft_nd_fix_not_saved', __( 'The fix could not be saved. Try again.', 'estasoft-news-distribution' ), 500 );
		}

		$fix             = $this->find( $id );
		$fix             = null === $fix ? array() : $fix;
		$fix['notified'] = ! array_key_exists( 'email', $input ) || filter_var( $input['email'], FILTER_VALIDATE_BOOLEAN )
			? $this->email( $fix, false )
			: array(
				'status' => 'not_asked',
				'sent'   => 0,
				'to'     => array(),
				'error'  => '',
			);

		return $fix;
	}

	/**
	 * Sends the person the same message again.
	 *
	 * @param int      $id Fix ID.
	 * @param int|null $by Person reminding, or the signed-in user.
	 * @return array<string, mixed>|\WP_Error The fix, with `notified`.
	 */
	public function remind( int $id, ?int $by = null ) {
		$fix = $this->find( $id );

		if ( null === $fix ) {
			return $this->fail( 'estasoft_nd_unknown_fix', __( 'That fix does not exist.', 'estasoft-news-distribution' ), 404 );
		}

		if ( self::OPEN !== $fix['status'] ) {
			return $this->fail( 'estasoft_nd_fix_closed', __( 'This fix is already closed, so there is nothing to remind anyone about.', 'estasoft-news-distribution' ), 409 );
		}

		$key = 'estasoft_nd_fix_reminded_' . $id;

		if ( false !== get_transient( $key ) ) {
			return $this->fail( 'estasoft_nd_fix_reminded', __( 'You reminded them a moment ago. Give them a few minutes before you send it again.', 'estasoft-news-distribution' ), 429 );
		}

		set_transient( $key, 1, self::REMIND_GAP );

		$fix['notified'] = $this->email( $fix, true, null === $by ? get_current_user_id() : $by );

		return $fix;
	}

	/**
	 * Gives an open fix to someone else, and tells them.
	 *
	 * @param int      $id       Fix ID.
	 * @param int      $assignee The new person.
	 * @param int|null $by       Person reassigning, or the signed-in user.
	 * @param bool     $email    Whether to email the new person.
	 * @return array<string, mixed>|\WP_Error The fix, with `notified`.
	 */
	public function reassign( int $id, int $assignee, ?int $by = null, bool $email = true ) {
		$fix = $this->find( $id );

		if ( null === $fix ) {
			return $this->fail( 'estasoft_nd_unknown_fix', __( 'That fix does not exist.', 'estasoft-news-distribution' ), 404 );
		}

		if ( self::OPEN !== $fix['status'] ) {
			return $this->fail( 'estasoft_nd_fix_closed', __( 'This fix is already closed. Assign the problem again if it is back.', 'estasoft-news-distribution' ), 409 );
		}

		$person = $this->person( (int) $fix['post_id'], $assignee );

		if ( is_wp_error( $person ) ) {
			return $person;
		}

		$by = null === $by ? get_current_user_id() : $by;

		$this->update(
			$id,
			array(
				'assignee_id' => $assignee,
				'assigned_by' => $by,
			)
		);

		$fix             = $this->find( $id );
		$fix             = null === $fix ? array() : $fix;
		$fix['notified'] = $email
			? $this->email( $fix, false )
			: array(
				'status' => 'not_asked',
				'sent'   => 0,
				'to'     => array(),
				'error'  => '',
			);

		return $fix;
	}

	/**
	 * Marks a fix done, dismissed, or open again.
	 *
	 * @param int    $id     Fix ID.
	 * @param string $status open, done or dismissed.
	 * @return array<string, mixed>|\WP_Error The fix.
	 */
	public function set_status( int $id, string $status ) {
		if ( ! in_array( $status, array( self::OPEN, self::DONE, self::DISMISSED ), true ) ) {
			return $this->fail( 'estasoft_nd_bad_fix_status', __( 'A fix is open, done or dismissed.', 'estasoft-news-distribution' ), 422, array( 'status' ) );
		}

		if ( null === $this->row( $id ) ) {
			return $this->fail( 'estasoft_nd_unknown_fix', __( 'That fix does not exist.', 'estasoft-news-distribution' ), 404 );
		}

		$this->update(
			$id,
			array(
				'status'       => $status,
				'resolved_gmt' => self::OPEN === $status ? null : Clock::to_gmt(),
			)
		);

		return (array) $this->find( $id );
	}

	/**
	 * Closes the open fixes of an article whose problem the latest check no longer finds.
	 *
	 * @param int $post_id Article ID.
	 * @return int How many were closed.
	 */
	public function close_solved( int $post_id ): int {
		$report = $this->plugin->make( ArticleChecker::class )->stored( $post_id );

		if ( null === $report ) {
			return 0;
		}

		$found  = array_column( $report->findings(), 'code' );
		$closed = 0;

		foreach ( $this->for_article( $post_id, 1 ) as $fix ) {
			if ( self::OPEN === $fix['status'] && ! in_array( $fix['code'], $found, true ) ) {
				$this->set_status( (int) $fix['id'], self::DONE );
				++$closed;
			}
		}

		return $closed;
	}

	/**
	 * Forgets the fixes of an article that was deleted for good, so nobody is left holding a fix for a post
	 * that no longer exists.
	 *
	 * @param int $post_id Article ID.
	 * @return int How many fixes were removed.
	 */
	public function forget( int $post_id ): int {
		global $wpdb;

		if ( $post_id < 1 ) {
			return 0;
		}

		$removed = $wpdb->delete( Schema::table( 'fixes' ), array( 'post_id' => $post_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return false === $removed ? 0 : (int) $removed;
	}

	// ---------------------------------------------------------------------
	// Internals.
	// ---------------------------------------------------------------------

	/**
	 * Sends the email of a fix, and schedules the one reminder when it has a date.
	 *
	 * @param array<string, mixed> $fix      A fix from find().
	 * @param bool                 $reminder Whether this is the reminder.
	 * @param int|null             $by       Person sending, or the person who assigned it.
	 * @return array{status: string, sent: int, to: string[], error: string}
	 */
	private function email( array $fix, bool $reminder, ?int $by = null ): array {
		$alerts  = $this->plugin->make( Alerts::class );
		$finding = $this->finding( (int) $fix['post_id'], (string) $fix['code'] );
		$text    = null === $finding ? (string) $fix['message'] : (string) $finding['message'];
		$text    = '' === $fix['note'] ? $text : trim( $text . ' ' . $fix['note'] );
		$context = array(
			'to_user'    => (int) $fix['assignee']['id'],
			'by'         => null === $by ? (int) $fix['assigned_by']['id'] : $by,
			'article_id' => (int) $fix['post_id'],
			'title'      => (string) $fix['title'],
			'fix'        => $text,
			'fix_id'     => (int) $fix['id'],
			'reminder'   => $reminder,
		);

		if ( $fix['due_ts'] > 0 ) {
			$context['due'] = (int) $fix['due_ts'];
		}

		$result = $alerts->send( Alerts::FIX_ASSIGNED, $context );

		if ( ! $reminder && $fix['due_ts'] > Clock::now() ) {
			unset( $context['reminder'] );
			$alerts->schedule_reminder( Alerts::FIX_ASSIGNED, $context, $fix['due_ts'] - Clock::now() );
		}

		return $result;
	}

	/**
	 * The row of a fix.
	 *
	 * @param int $id Fix ID.
	 * @return array<string, mixed>|null
	 */
	private function row( int $id ): ?array {
		global $wpdb;

		$table = Schema::table( 'fixes' );
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * The open fix for a problem on an article, if there is one.
	 *
	 * @param int    $post_id Article ID.
	 * @param string $code    Finding code.
	 * @return array<string, mixed>|null
	 */
	private function open_for( int $post_id, string $code ): ?array {
		global $wpdb;

		$table = Schema::table( 'fixes' );
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT * FROM %i WHERE post_id = %d AND code = %s AND status = %s ORDER BY id DESC LIMIT 1', $table, $post_id, $code, self::OPEN ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Adds a row.
	 *
	 * @param int    $post_id  Article ID.
	 * @param string $code     Finding code.
	 * @param string $channel  Channel id the finding belongs to, or an empty string.
	 * @param int    $assignee Person the fix goes to.
	 * @param int    $by       Person assigning.
	 * @param string $note     Note.
	 * @param int    $due      Unix time, or 0.
	 * @return int The new ID, or 0.
	 */
	private function insert( int $post_id, string $code, string $channel, int $assignee, int $by, string $note, int $due ): int {
		global $wpdb;

		$row    = array(
			'post_id'     => $post_id,
			'code'        => substr( $code, 0, 60 ),
			'channel'     => $channel,
			'assignee_id' => $assignee,
			'assigned_by' => $by,
			'status'      => self::OPEN,
			'created_gmt' => Clock::to_gmt(),
			'note'        => $note,
		);
		$format = array( '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s' );

		if ( $due > 0 ) {
			$row['due_gmt'] = Clock::to_gmt( $due );
			$format[]       = '%s';
		}

		$done = $wpdb->insert( Schema::table( 'fixes' ), $row, $format ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return false === $done ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Changes columns of a row.
	 *
	 * @param int                  $id      Fix ID.
	 * @param array<string, mixed> $columns Column => value. A null value clears the column.
	 * @return void
	 */
	private function update( int $id, array $columns ): void {
		global $wpdb;

		$sets = array();

		foreach ( $columns as $column => $value ) {
			if ( null === $value ) {
				$sets[] = $wpdb->prepare( '%i = NULL', $column );
				continue;
			}

			$sets[] = is_int( $value ) ? $wpdb->prepare( '%i = %d', $column, $value ) : $wpdb->prepare( '%i = %s', $column, $value );
		}

		$table = Schema::table( 'fixes' );
		$sql   = $wpdb->prepare( 'UPDATE %i SET ', $table ) . implode( ', ', $sets ) . $wpdb->prepare( ' WHERE id = %d', $id );

		$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Put together from prepared parts above.
	}

	/**
	 * A row as the screens and routes show it.
	 *
	 * @param array<string, mixed> $row A row of the fixes table.
	 * @return array<string, mixed>
	 */
	private function shape( array $row ): array {
		$post_id = (int) $row['post_id'];
		$due     = Clock::from_gmt( (string) ( $row['due_gmt'] ?? '' ) );
		$done    = Clock::from_gmt( (string) ( $row['resolved_gmt'] ?? '' ) );
		$created = Clock::from_gmt( (string) $row['created_gmt'] );
		$finding = $this->finding( $post_id, (string) $row['code'] );

		return array(
			'id'          => (int) $row['id'],
			'post_id'     => $post_id,
			'title'       => wp_strip_all_tags( get_the_title( $post_id ) ),
			'code'        => (string) $row['code'],
			'channel'     => (string) $row['channel'],
			'message'     => null === $finding ? $this->words( (string) $row['code'] ) : (string) $finding['message'],
			'fix'         => null === $finding ? '' : (string) ( $finding['fix'] ?? '' ),
			'still_found' => null !== $finding,
			'assignee'    => $this->who( (int) $row['assignee_id'] ),
			'assigned_by' => $this->who( (int) $row['assigned_by'] ),
			'status'      => (string) $row['status'],
			'note'        => (string) ( $row['note'] ?? '' ),
			'due'         => $due > 0 ? Clock::iso( $due ) : null,
			'due_ts'      => $due,
			'created'     => Clock::iso( $created ),
			'created_ts'  => $created,
			'resolved'    => $done > 0 ? Clock::iso( $done ) : null,
		);
	}

	/**
	 * A person as the routes show them.
	 *
	 * @param int $user_id User ID.
	 * @return array{id: int, name: string}
	 */
	private function who( int $user_id ): array {
		$user = get_userdata( $user_id );

		return array(
			'id'   => $user_id,
			'name' => $user instanceof \WP_User ? (string) $user->display_name : __( 'Someone who has left', 'estasoft-news-distribution' ),
		);
	}

	/**
	 * A code written as words, for a problem the check no longer lists.
	 *
	 * @param string $code Finding code such as "image.alt_missing".
	 * @return string
	 */
	private function words( string $code ): string {
		return ucfirst( trim( str_replace( array( '.', '_', '-' ), ' ', $code ) ) );
	}

	/**
	 * A person's role as a lower-case word.
	 *
	 * @param \WP_User $user The person.
	 * @return string
	 */
	private function role_of( \WP_User $user ): string {
		$names = wp_roles()->get_names();
		$role  = (string) ( $user->roles[0] ?? '' );

		return isset( $names[ $role ] ) ? mb_strtolower( translate_user_role( $names[ $role ] ) ) : '';
	}

	/**
	 * Checks that a person can be given a fix.
	 *
	 * @param int $post_id  Article ID.
	 * @param int $assignee User ID.
	 * @return true|\WP_Error
	 */
	private function person( int $post_id, int $assignee ) {
		if ( $assignee < 1 ) {
			return $this->fail( 'estasoft_nd_choose_person', __( 'Choose a person.', 'estasoft-news-distribution' ), 422, array( 'assignee' ) );
		}

		if ( ! array_key_exists( $assignee, $this->assignable( $post_id ) ) ) {
			return $this->fail( 'estasoft_nd_not_assignable', __( 'Only people with access to Article check can be assigned a fix.', 'estasoft-news-distribution' ), 422, array( 'assignee' ) );
		}

		return true;
	}

	/**
	 * A note, cleaned and cut to length.
	 *
	 * @param mixed $note What was typed.
	 * @return string
	 */
	private function note( $note ): string {
		return trim( mb_substr( sanitize_textarea_field( is_scalar( $note ) ? (string) $note : '' ), 0, self::NOTE_MAX ) );
	}

	/**
	 * A due date from a form or a route: a Unix time, an ISO time or a date.
	 *
	 * @param mixed $value What was given.
	 * @return int|\WP_Error Unix time, or 0 for none.
	 */
	private function due( $value ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		if ( '' === $value || '0' === $value ) {
			return 0;
		}

		if ( ctype_digit( $value ) ) {
			$time = (int) $value;
		} elseif ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			$date = date_create_immutable( $value . ' 17:00:00', Clock::zone() );
			$time = false === $date ? 0 : $date->getTimestamp();
		} else {
			$parsed = strtotime( $value );
			$time   = false === $parsed ? 0 : (int) $parsed;
		}

		if ( $time <= 0 ) {
			return $this->fail( 'estasoft_nd_bad_due', __( 'Enter the date as year-month-day, such as 2026-10-23.', 'estasoft-news-distribution' ), 422, array( 'due' ) );
		}

		return $time;
	}

	/**
	 * The article, if it exists and is of a type the plugin sends.
	 *
	 * @param int $post_id Article ID.
	 * @return \WP_Post|\WP_Error
	 */
	private function post( int $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, $this->plugin->settings()->post_types(), true ) ) {
			return $this->fail( 'estasoft_nd_unknown_article', __( 'That article does not exist.', 'estasoft-news-distribution' ), 404 );
		}

		return $post;
	}

	/**
	 * A plain-language error.
	 *
	 * @param string   $code    Stable code.
	 * @param string   $message Sentence the screen can show as written.
	 * @param int      $status  HTTP status.
	 * @param string[] $fields  Fields the message is about.
	 * @return \WP_Error
	 */
	private function fail( string $code, string $message, int $status, array $fields = array() ): \WP_Error {
		return new \WP_Error( $code, $message, array_merge( array( 'status' => $status ), array() === $fields ? array() : array( 'fields' => $fields ) ) );
	}
}
