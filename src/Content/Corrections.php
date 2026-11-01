<?php
/**
 * Correction notes: what an editor wrote when an article was corrected, and the log of them.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Content;

use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Get it with `Plugin::instance()->make( Corrections::class )`.
 *
 * Two post meta values hold the notes:
 *
 * - `_estasoft_nd_correction`: the note that is current: `show` (whether the page prints it),
 *   `note`, `at` (Unix time) and `by` (user id).
 * - `_estasoft_nd_corrections`: the log, oldest first, every note that was ever saved:
 *   `at`, `by`, `note`, `shown`. It is never trimmed by the plugin's log period: a correction is
 *   part of the article's record. The History tab of Article check reads it with `log()`.
 *
 * Nothing is published until a person saves a note. Saving counts as a real update: the
 * modified date moves (the date guard is told through its internal filter, whatever else
 * changed) and a published article sends update notices to the pushed channels, like any other
 * update. Corrections are also written to the audit trail, which is purged on the log period.
 */
final class Corrections {

	public const CURRENT = '_estasoft_nd_correction';
	public const LOG     = '_estasoft_nd_corrections';
	public const AUDIT   = 'correction';
	public const MAX     = 1000;
	public const KEEP    = 100;

	/**
	 * The article whose correction is being saved now, or 0.
	 *
	 * @var int
	 */
	private static int $saving = 0;

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
	 * Answers the date guard: a correction being saved always moves the modified date.
	 *
	 * Hooked to `estasoft_nd_internal_date_guard_always_moves`.
	 *
	 * @param mixed $always  The answer so far.
	 * @param mixed $old     The article before the save.
	 * @param mixed $saved   The article as saved.
	 * @param mixed $post_id The post.
	 * @return mixed
	 */
	public static function moves_date( $always, $old = null, $saved = null, $post_id = 0 ) {
		return self::$saving > 0 && (int) $post_id === self::$saving ? true : $always;
	}

	/**
	 * The current note of an article, or null when none was ever saved.
	 *
	 * @param int $post_id Article ID.
	 * @return array{show: bool, note: string, at: int, by: int}|null
	 */
	public function current( int $post_id ): ?array {
		$stored = get_post_meta( $post_id, self::CURRENT, true );

		if ( ! is_array( $stored ) || ! isset( $stored['note'] ) ) {
			return null;
		}

		return array(
			'show' => ! empty( $stored['show'] ),
			'note' => (string) $stored['note'],
			'at'   => (int) ( $stored['at'] ?? 0 ),
			'by'   => (int) ( $stored['by'] ?? 0 ),
		);
	}

	/**
	 * The note the page prints, or null when there is none to print.
	 *
	 * @param int $post_id Article ID.
	 * @return array{show: bool, note: string, at: int, by: int}|null
	 */
	public function visible( int $post_id ): ?array {
		$current = $this->current( $post_id );

		return null !== $current && $current['show'] && '' !== trim( $current['note'] ) ? $current : null;
	}

	/**
	 * Every note that was saved, oldest first.
	 *
	 * @param int $post_id Article ID.
	 * @return array<int, array{at: int, by: int, note: string, shown: bool}>
	 */
	public function log( int $post_id ): array {
		$stored = get_post_meta( $post_id, self::LOG, true );
		$out    = array();

		foreach ( is_array( $stored ) ? $stored : array() as $row ) {
			if ( is_array( $row ) && isset( $row['note'] ) ) {
				$out[] = array(
					'at'    => (int) ( $row['at'] ?? 0 ),
					'by'    => (int) ( $row['by'] ?? 0 ),
					'note'  => (string) $row['note'],
					'shown' => ! empty( $row['shown'] ),
				);
			}
		}

		return $out;
	}

	/**
	 * Saves a note, adds it to the log and counts the save as a real update.
	 *
	 * @param int      $post_id Article ID.
	 * @param string   $note    The note, plain text with line breaks.
	 * @param bool     $show    Whether the page prints it.
	 * @param int|null $user_id Who wrote it, or the signed-in user.
	 * @return array{show: bool, note: string, at: int, by: int, modified: int}|\WP_Error
	 */
	public function save( int $post_id, string $note, bool $show, ?int $user_id = null ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, $this->plugin->settings()->post_types(), true ) ) {
			return new \WP_Error(
				'estasoft_nd_correction_article',
				__( 'That article cannot have a correction note.', 'estasoft-news-distribution' ),
				array( 'status' => 404 )
			);
		}

		$note = trim( sanitize_textarea_field( $note ) );

		if ( mb_strlen( $note ) > self::MAX ) {
			return new \WP_Error(
				'estasoft_nd_correction_long',
				sprintf(
					/* translators: %s: the largest number of characters, such as 1,000. */
					__( 'The note can be at most %s characters.', 'estasoft-news-distribution' ),
					number_format_i18n( self::MAX )
				),
				array(
					'status' => 400,
					'fields' => array( 'note' => '' ),
				)
			);
		}

		if ( $show && '' === $note ) {
			return new \WP_Error(
				'estasoft_nd_correction_empty',
				__( 'Write the note first, or turn off “Show a correction note”.', 'estasoft-news-distribution' ),
				array(
					'status' => 400,
					'fields' => array( 'note' => '' ),
				)
			);
		}

		$user = null === $user_id ? get_current_user_id() : $user_id;
		$now  = Clock::now();

		update_post_meta(
			$post_id,
			self::CURRENT,
			array(
				'show' => $show,
				'note' => $note,
				'at'   => $now,
				'by'   => $user,
			)
		);

		$log   = $this->log( $post_id );
		$log[] = array(
			'at'    => $now,
			'by'    => $user,
			'note'  => $note,
			'shown' => $show,
		);

		update_post_meta( $post_id, self::LOG, array_slice( $log, -self::KEEP ) );

		$this->plugin->audit()->record(
			self::AUDIT,
			$post_id,
			$show ? __( 'Correction note saved and shown on the page.', 'estasoft-news-distribution' ) : __( 'Correction note saved and hidden on the page.', 'estasoft-news-distribution' ),
			$user
		);

		$this->count_as_update( $post );

		$fresh = get_post( $post_id );

		return array(
			'show'     => $show,
			'note'     => $note,
			'at'       => $now,
			'by'       => $user,
			'modified' => $fresh instanceof \WP_Post ? Clock::from_gmt( (string) $fresh->post_modified_gmt ) : $now,
		);
	}

	/**
	 * Saves the post again so the modified date moves and the channels hear about it.
	 *
	 * Only a published article is saved: a draft has no date to protect and nobody to tell.
	 *
	 * @param \WP_Post $post The article.
	 * @return void
	 */
	private function count_as_update( \WP_Post $post ): void {
		if ( 'publish' !== $post->post_status ) {
			return;
		}

		self::$saving = (int) $post->ID;

		try {
			wp_update_post( array( 'ID' => (int) $post->ID ) );
		} finally {
			self::$saving = 0;
		}
	}
}
