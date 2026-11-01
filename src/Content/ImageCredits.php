<?php
/**
 * The credit, source and rights note of a photo in the media library.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Content;

use Estasoft\NewsDistribution\Checks\CreditCheck;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Where the fields live and how they are filled.
 *
 * Stored on the attachment:
 *
 * - `_estasoft_nd_credit`: the credit, such as "Anna Verhoeven / Example Times". Article check reads it.
 * - `_estasoft_nd_credit_source`: the type of source, one of `SOURCES` (own, agency, licensed, cc, other).
 * - `_estasoft_nd_credit_note`: the rights note, such as a copyright notice.
 * - `_estasoft_nd_credit_from`: which of `credit`, `caption` and `note` were filled from the photo file.
 *
 * The caption is the attachment's own caption (its excerpt). Alt text is never filled: it describes the
 * picture, and a photographer's caption does not.
 *
 * Filling reads the photo file's own metadata (`PhotoMetadata`) and writes only into a field that is
 * empty. A value a person typed is never replaced, by a fill on upload, by the media credit route or by
 * the bulk fill. Every bulk fill keeps what it wrote so that it can be undone, and an undo restores only
 * what is still as the fill left it.
 *
 * For other code (channels, `Markup\`, themes):
 *
 * - `credit_of( $attachment_id )` is the credit, after the internal filter `estasoft_nd_internal_image_credit`
 *   has had its say (the same filter the Article check and the page markup read).
 * - `details( $attachment_id )` is the credit, the source, the note and the caption in one array.
 * - `line( $attachment_id )` is the caption with the credit after it, when "Put the credit in the caption"
 *   is on. It goes through the internal filter `estasoft_nd_internal_image_caption`, so a channel can write
 *   it another way. Feeds that carry an image caption should use it.
 */
final class ImageCredits {

	public const CREDIT     = CreditCheck::META;
	public const SOURCE     = '_estasoft_nd_credit_source';
	public const NOTE       = '_estasoft_nd_credit_note';
	public const FROM       = '_estasoft_nd_credit_from';
	public const UNDO       = 'estasoft_nd_credit_undo';
	public const CANDIDATES = 'estasoft_nd_credit_candidates';

	public const MAX_CREDIT = 255;
	public const MAX_NOTE   = 500;
	public const KEEP_UNDO  = 5;

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
	 * The types of source a photo can have, value => words.
	 *
	 * @return array<string, string>
	 */
	public static function sources(): array {
		return array(
			'own'      => __( 'Our own photographer', 'estasoft-news-distribution' ),
			'agency'   => __( 'News agency', 'estasoft-news-distribution' ),
			'licensed' => __( 'Licensed', 'estasoft-news-distribution' ),
			'cc'       => __( 'Creative Commons', 'estasoft-news-distribution' ),
			'other'    => __( 'Other', 'estasoft-news-distribution' ),
		);
	}

	/**
	 * Cleans a credit or a note on its way to storage.
	 *
	 * @param mixed $value What was given.
	 * @param int   $max   Longest allowed, in characters.
	 * @return string
	 */
	public static function clean_text( $value, int $max = self::MAX_CREDIT ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return mb_substr( sanitize_text_field( (string) $value ), 0, $max );
	}

	/**
	 * Cleans the source type.
	 *
	 * @param mixed $value What was given.
	 * @return string One of the keys of `sources()`, or an empty string.
	 */
	public static function clean_source( $value ): string {
		$value = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';

		return isset( self::sources()[ $value ] ) ? $value : '';
	}

	/**
	 * Whether an attachment is an image this feature can work with.
	 *
	 * @param int $attachment Attachment ID.
	 * @return bool
	 */
	public function is_image( int $attachment ): bool {
		return $attachment > 0 && 'attachment' === get_post_type( $attachment ) && wp_attachment_is_image( $attachment );
	}

	/**
	 * The section's values: `on_upload`, `no_credit`, `require`, `on_fail`, `in_caption`, `presets_on_publish`.
	 *
	 * @return array<string, mixed>
	 */
	public function settings(): array {
		return $this->plugin->settings()->section( 'credits' );
	}

	/**
	 * The name the publication goes by, for a photo whose file has no credit.
	 *
	 * @return string
	 */
	public function publication_name(): string {
		return trim( (string) $this->plugin->settings()->get( 'general.publication_name', '' ) );
	}

	/**
	 * What is stored for a photo now.
	 *
	 * @param int $attachment Attachment ID.
	 * @return array{credit: string, source: string, note: string, caption: string, from: string[]}
	 */
	public function stored( int $attachment ): array {
		$from = get_post_meta( $attachment, self::FROM, true );
		$from = is_array( $from ) ? array_values( array_intersect( array( 'credit', 'caption', 'note' ), array_map( 'strval', $from ) ) ) : array();

		return array(
			'credit'  => trim( (string) get_post_meta( $attachment, self::CREDIT, true ) ),
			'source'  => self::clean_source( get_post_meta( $attachment, self::SOURCE, true ) ),
			'note'    => trim( (string) get_post_meta( $attachment, self::NOTE, true ) ),
			'caption' => trim( (string) get_post_field( 'post_excerpt', $attachment ) ),
			'from'    => $from,
		);
	}

	/**
	 * The credit of a photo, as channels and the page markup should read it.
	 *
	 * @param int $attachment Attachment ID.
	 * @return string Empty when the photo has no credit.
	 */
	public function credit_of( int $attachment ): string {
		/**
		 * Answers the credit of an image (internal).
		 *
		 * Return a string (empty means no credit), or null to read the attachment's own credit. The Article
		 * check and the news article markup read this filter, and so should any channel that writes a credit.
		 *
		 * @param string|null $credit     The credit, or null.
		 * @param int         $attachment Attachment ID.
		 */
		$credit = apply_filters( 'estasoft_nd_internal_image_credit', null, $attachment );

		if ( null === $credit ) {
			$credit = get_post_meta( $attachment, self::CREDIT, true );
		}

		return is_scalar( $credit ) ? trim( (string) $credit ) : '';
	}

	/**
	 * Everything a channel may want to write about a photo.
	 *
	 * @param int $attachment Attachment ID.
	 * @return array{credit: string, source: string, source_label: string, note: string, caption: string, line: string}
	 */
	public function details( int $attachment ): array {
		$stored  = $this->stored( $attachment );
		$sources = self::sources();

		return array(
			'credit'       => $this->credit_of( $attachment ),
			'source'       => $stored['source'],
			'source_label' => $sources[ $stored['source'] ] ?? '',
			'note'         => $stored['note'],
			'caption'      => $stored['caption'],
			'line'         => $this->line( $attachment ),
		);
	}

	/**
	 * The caption of a photo with its credit after it, when "Put the credit in the caption" is on.
	 *
	 * @param int         $attachment Attachment ID.
	 * @param string|null $caption    A caption to use in place of the photo's own.
	 * @return string Plain text.
	 */
	public function line( int $attachment, ?string $caption = null ): string {
		$caption = trim( null === $caption ? (string) get_post_field( 'post_excerpt', $attachment ) : $caption );
		$caption = trim( wp_strip_all_tags( $caption ) );
		$credit  = $this->credit_of( $attachment );
		$line    = $caption;

		if ( '' !== $credit && ! empty( $this->settings()['in_caption'] ) ) {
			$line = '' === $caption ? $credit : $caption . ' (' . $credit . ')';
		}

		/**
		 * Changes the caption a channel writes for an image (internal).
		 *
		 * @param string $line       The caption, with the credit after it when that is switched on.
		 * @param int    $attachment Attachment ID.
		 * @param string $caption    The caption alone.
		 * @param string $credit     The credit alone.
		 */
		return (string) apply_filters( 'estasoft_nd_internal_image_caption', $line, $attachment, $caption, $credit );
	}

	/**
	 * What the photo file says, for the three fields.
	 *
	 * @param int $attachment Attachment ID.
	 * @return array{credit: string, caption: string, notice: string}
	 */
	public function file_values( int $attachment ): array {
		$file = get_attached_file( $attachment );

		return PhotoMetadata::read( is_string( $file ) ? $file : '' );
	}

	/**
	 * Fills the empty fields of a photo from its file. A field that has a value is left alone.
	 *
	 * @param int $attachment Attachment ID.
	 * @return array{filled: string[], kept: string[], found: array{credit: string, caption: string, notice: string}, written: array<string, string>}
	 *         `filled` and `kept` name fields (`credit`, `caption`, `note`): filled now, and left as they were because they had a value.
	 *         `written` is what was stored for each filled field.
	 */
	public function fill( int $attachment ): array {
		$result = array(
			'filled'  => array(),
			'kept'    => array(),
			'found'   => array(
				PhotoMetadata::CREDIT  => '',
				PhotoMetadata::CAPTION => '',
				PhotoMetadata::NOTICE  => '',
			),
			'written' => array(),
		);

		if ( ! $this->is_image( $attachment ) ) {
			return $result;
		}

		$found  = $this->file_values( $attachment );
		$stored = $this->stored( $attachment );
		$from   = $stored['from'];
		$wanted = array(
			'credit'  => array( $found[ PhotoMetadata::CREDIT ], self::MAX_CREDIT ),
			'caption' => array( $found[ PhotoMetadata::CAPTION ], 1000 ),
			'note'    => array( $found[ PhotoMetadata::NOTICE ], self::MAX_NOTE ),
		);

		$result['found'] = $found;

		foreach ( $wanted as $field => $pair ) {
			if ( '' !== $stored[ $field ] ) {
				$result['kept'][] = $field;
				continue;
			}

			$value     = self::clean_text( $pair[0], $pair[1] );
			$from_file = '' !== $value;

			if ( '' === $value && 'credit' === $field && 'publication' === ( $this->settings()['no_credit'] ?? 'flag' ) ) {
				$value = self::clean_text( $this->publication_name() );
			}

			if ( '' === $value ) {
				continue;
			}

			$this->write( $attachment, $field, $value );

			$result['filled'][]          = $field;
			$result['written'][ $field ] = $value;

			if ( $from_file ) {
				$from[] = $field;
			}
		}

		if ( array() !== $result['filled'] ) {
			update_post_meta( $attachment, self::FROM, array_values( array_unique( $from ) ) );
		}

		return $result;
	}

	/**
	 * Writes one field.
	 *
	 * @param int    $attachment Attachment ID.
	 * @param string $field      `credit`, `caption` or `note`.
	 * @param string $value      The value; an empty string clears the field.
	 * @return void
	 */
	private function write( int $attachment, string $field, string $value ): void {
		if ( 'caption' === $field ) {
			wp_update_post(
				array(
					'ID'           => $attachment,
					'post_excerpt' => $value,
				)
			);

			return;
		}

		$key = 'credit' === $field ? self::CREDIT : self::NOTE;

		if ( '' === $value ) {
			delete_post_meta( $attachment, $key );
		} else {
			update_post_meta( $attachment, $key, $value );
		}
	}

	/**
	 * Fills many photos and keeps what it wrote so the fill can be undone.
	 *
	 * @param int[] $ids Attachment IDs.
	 * @return array{batch: string, photos: int, fields: int, skipped: int}
	 */
	public function fill_many( array $ids ): array {
		$batch  = wp_generate_password( 12, false );
		$items  = array();
		$photos = 0;
		$fields = 0;
		$skip   = 0;

		foreach ( array_slice( array_unique( array_map( 'absint', $ids ) ), 0, 200 ) as $id ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				++$skip;
				continue;
			}

			$result = $this->fill( $id );

			if ( array() === $result['filled'] ) {
				++$skip;
				continue;
			}

			++$photos;
			$fields += count( $result['filled'] );

			foreach ( $result['written'] as $field => $value ) {
				$items[ $id ][ $field ] = array(
					'previous' => '',
					'written'  => $value,
				);
			}
		}

		if ( array() !== $items ) {
			$log           = $this->undo_log();
			$log[ $batch ] = array(
				'at'    => time(),
				'by'    => get_current_user_id(),
				'items' => $items,
			);

			update_option( self::UNDO, array_slice( $log, -self::KEEP_UNDO, null, true ), false );
		}

		return array(
			'batch'   => $batch,
			'photos'  => $photos,
			'fields'  => $fields,
			'skipped' => $skip,
		);
	}

	/**
	 * The kept fills.
	 *
	 * @return array<string, array{at: int, by: int, items: array<int, array<string, array{previous: string, written: string}>>}>
	 */
	private function undo_log(): array {
		$log = get_option( self::UNDO, array() );

		return is_array( $log ) ? $log : array();
	}

	/**
	 * Undoes one bulk fill. A field a person has changed since is left as it is.
	 *
	 * @param string $batch The batch the fill returned.
	 * @return int|null How many fields went back to their previous value, or null when there is no such fill.
	 */
	public function undo( string $batch ): ?int {
		$log = $this->undo_log();

		if ( ! isset( $log[ $batch ] ) || ! is_array( $log[ $batch ]['items'] ?? null ) ) {
			return null;
		}

		$restored = 0;

		foreach ( $log[ $batch ]['items'] as $id => $fields ) {
			$id = (int) $id;

			if ( ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}

			$stored = $this->stored( $id );
			$from   = $stored['from'];

			foreach ( (array) $fields as $field => $pair ) {
				if ( ! is_array( $pair ) || (string) ( $pair['written'] ?? '' ) !== $stored[ $field ] ) {
					continue;
				}

				$this->write( $id, (string) $field, (string) ( $pair['previous'] ?? '' ) );
				$from = array_values( array_diff( $from, array( (string) $field ) ) );
				++$restored;
			}

			if ( array() === $from ) {
				delete_post_meta( $id, self::FROM );
			} else {
				update_post_meta( $id, self::FROM, $from );
			}
		}

		unset( $log[ $batch ] );
		update_option( self::UNDO, $log, false );

		return $restored;
	}

	/**
	 * Looks through the library for photos whose file has a credit and whose WordPress credit is empty.
	 *
	 * Works in batches so a large library is never read in one request: the first call (`$offset` 0) starts a
	 * new list, and each later call adds to it. A photo that already has a credit in WordPress is never listed.
	 *
	 * @param int $offset Position in the library.
	 * @param int $limit  How many photos to read in this call.
	 * @return array{done: bool, next: int, scanned: int, total: int, found: int}
	 */
	public function scan( int $offset, int $limit = 100 ): array {
		$limit = max( 1, min( 300, $limit ) );
		$query = new \WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'post_mime_type'         => array( 'image/jpeg', 'image/tiff' ),
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'posts_per_page'         => $limit,
				'offset'                 => max( 0, $offset ),
				'no_found_rows'          => false,
				'update_post_term_cache' => false,
			)
		);
		$list  = $offset > 0 ? $this->stored_candidates()['ids'] : array();

		foreach ( $query->posts as $id ) {
			$id = (int) $id;

			if ( '' !== $this->stored( $id )['credit'] ) {
				continue;
			}

			if ( '' !== $this->file_values( $id )[ PhotoMetadata::CREDIT ] ) {
				$list[] = $id;
			}
		}

		$total  = (int) $query->found_posts;
		$next   = max( 0, $offset ) + count( $query->posts );
		$done   = $next >= $total || array() === $query->posts;
		$list   = array_values( array_unique( $list ) );
		$stored = array(
			'ids'     => $list,
			'scanned' => min( $next, $total ),
			'total'   => $total,
			'at'      => time(),
			'done'    => $done,
		);

		update_option( self::CANDIDATES, $stored, false );

		return array(
			'done'    => $done,
			'next'    => $next,
			'scanned' => $stored['scanned'],
			'total'   => $total,
			'found'   => count( $list ),
		);
	}

	/**
	 * The list the last scan left.
	 *
	 * @return array{ids: int[], scanned: int, total: int, at: int, done: bool}
	 */
	public function stored_candidates(): array {
		$stored = get_option( self::CANDIDATES, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'ids'     => array_values( array_map( 'absint', (array) ( $stored['ids'] ?? array() ) ) ),
			'scanned' => (int) ( $stored['scanned'] ?? 0 ),
			'total'   => (int) ( $stored['total'] ?? 0 ),
			'at'      => (int) ( $stored['at'] ?? 0 ),
			'done'    => ! empty( $stored['done'] ),
		);
	}

	/**
	 * The photos the last scan found that still have no credit in WordPress.
	 *
	 * @param int $limit  How many rows to describe.
	 * @param int $offset Where to start.
	 * @return array{total: int, scanned: int, library: int, at: int, done: bool, items: array<int, array{id: int, file: string, in_file: string, in_wp: string}>}
	 */
	public function candidates( int $limit = 20, int $offset = 0 ): array {
		$stored = $this->stored_candidates();
		$open   = array();

		foreach ( $stored['ids'] as $id ) {
			if ( $this->is_image( $id ) && '' === $this->stored( $id )['credit'] ) {
				$open[] = $id;
			}
		}

		$items = array();

		foreach ( array_slice( $open, max( 0, $offset ), max( 1, $limit ) ) as $id ) {
			$file    = get_attached_file( $id );
			$items[] = array(
				'id'      => $id,
				'file'    => is_string( $file ) ? wp_basename( $file ) : '',
				'in_file' => $this->file_values( $id )[ PhotoMetadata::CREDIT ],
				'in_wp'   => '',
			);
		}

		return array(
			'total'   => count( $open ),
			'scanned' => $stored['scanned'],
			'library' => $stored['total'],
			'at'      => $stored['at'],
			'done'    => $stored['done'],
			'items'   => $items,
		);
	}

	/**
	 * The ids of every photo in the list, for a fill of all of them.
	 *
	 * @param int $limit How many to return.
	 * @return int[]
	 */
	public function open_candidates( int $limit = 200 ): array {
		$out = array();

		foreach ( $this->stored_candidates()['ids'] as $id ) {
			if ( $this->is_image( $id ) && '' === $this->stored( $id )['credit'] ) {
				$out[] = $id;
			}

			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		return $out;
	}
}
