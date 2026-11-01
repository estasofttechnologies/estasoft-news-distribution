<?php
/**
 * How the findings of an article check are grouped and written on the Article check screens.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Check;

use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * A finding is `{ code, severity, message, channels, fix }` (see `Checks\CheckInterface`). The
 * words for the severity come from `Core\Status`: "Blocks the channel", "Risk" and "Tip".
 */
final class Findings {

	/**
	 * The areas of the article the checks look at, in the order the screen lists them.
	 *
	 * @return array<string, string> Area id => name.
	 */
	public static function areas(): array {
		return array(
			'body'      => __( 'Body rendering', 'estasoft-news-distribution' ),
			'date'      => __( 'Dates', 'estasoft-news-distribution' ),
			'title'     => __( 'Title', 'estasoft-news-distribution' ),
			'image'     => __( 'Featured image', 'estasoft-news-distribution' ),
			'byline'    => __( 'Byline', 'estasoft-news-distribution' ),
			'duplicate' => __( 'Duplicates and clashes', 'estasoft-news-distribution' ),
			'other'     => __( 'Other checks', 'estasoft-news-distribution' ),
		);
	}

	/**
	 * The area a finding code belongs to.
	 *
	 * @param string $code Finding code such as "image.alt_missing".
	 * @return string An id from areas().
	 */
	public static function area_of( string $code ): string {
		$prefix = (string) strtok( $code, '.' );

		switch ( $prefix ) {
			case 'image':
			case 'credit':
				return 'image';
			case 'body':
				return 'body';
			case 'date':
				return 'date';
			case 'title':
				return 'title';
			case 'byline':
				return 'byline';
			case 'duplicate':
			case 'clash':
				return 'duplicate';
		}

		return 'other';
	}

	/**
	 * Splits findings by area.
	 *
	 * @param array<int, array<string, mixed>> $findings Findings.
	 * @return array<string, array<int, array<string, mixed>>> Area id => findings.
	 */
	public static function by_area( array $findings ): array {
		$out = array();

		foreach ( $findings as $finding ) {
			$out[ self::area_of( (string) ( $finding['code'] ?? '' ) ) ][] = $finding;
		}

		return $out;
	}

	/**
	 * The most severe severity among findings.
	 *
	 * @param array<int, array<string, mixed>> $findings Findings.
	 * @return string `block`, `warn`, `info`, or an empty string when there are none.
	 */
	public static function worst( array $findings ): string {
		$found = array_column( $findings, 'severity' );

		foreach ( array( Status::BLOCK, Status::WARN, Status::INFO ) as $severity ) {
			if ( in_array( $severity, $found, true ) ) {
				return $severity;
			}
		}

		return '';
	}

	/**
	 * Sorts findings with the most severe first. The order inside a severity stays as it was.
	 *
	 * @param array<int, array<string, mixed>> $findings Findings.
	 * @return array<int, array<string, mixed>>
	 */
	public static function sorted( array $findings ): array {
		$rank = array(
			Status::BLOCK => 0,
			Status::WARN  => 1,
			Status::INFO  => 2,
		);

		$indexed = array();

		foreach ( array_values( $findings ) as $index => $finding ) {
			$indexed[] = array( $rank[ (string) ( $finding['severity'] ?? '' ) ] ?? 3, $index, $finding );
		}

		usort(
			$indexed,
			static function ( array $a, array $b ): int {
				return array( $a[0], $a[1] ) <=> array( $b[0], $b[1] );
			}
		);

		return array_column( $indexed, 2 );
	}

	/**
	 * The fix for a finding: the one the check named, or the one `docs/check-codes.md` lists for its code.
	 *
	 * A finding a platform's rule pack raises can come without a fix slug while the same code from the shared
	 * checks has one, so the table in the documentation fills the gap.
	 *
	 * @param array<string, mixed> $finding The finding.
	 * @return string A slug for `fix_label()`, or an empty string.
	 */
	public static function fix_of( array $finding ): string {
		$named = (string) ( $finding['fix'] ?? '' );

		if ( '' !== $named ) {
			return $named;
		}

		$code   = (string) ( $finding['code'] ?? '' );
		$prefix = (string) strtok( $code, '.' );

		if ( in_array( $code, array( 'image.missing', 'image.too_small', 'image.bad_ratio', 'thumbnail.missing' ), true ) ) {
			return 'add-image';
		}

		if ( 'title' === $prefix ) {
			return 'edit-title';
		}

		if ( 'date' === $prefix ) {
			return 'fix-date';
		}

		return 'byline' === $prefix ? 'set-author' : '';
	}

	/**
	 * What a fix is called on a button or a link.
	 *
	 * @param string $slug A fix slug from the check.
	 * @return string An empty string for a slug this screen does not know.
	 */
	public static function fix_label( string $slug ): string {
		$labels = array(
			'add-image'  => __( 'Add a featured image', 'estasoft-news-distribution' ),
			'add-alt'    => __( 'Add alt text', 'estasoft-news-distribution' ),
			'add-credit' => __( 'Add credit', 'estasoft-news-distribution' ),
			'edit-title' => __( 'Edit the title', 'estasoft-news-distribution' ),
			'fix-date'   => __( 'Fix the date', 'estasoft-news-distribution' ),
			'set-author' => __( 'Set the author', 'estasoft-news-distribution' ),
		);

		return $labels[ $slug ] ?? '';
	}

	/**
	 * Where a fix is done: the media screen of the featured image for alt text and credit, the post otherwise.
	 *
	 * @param string $slug    A fix slug.
	 * @param int    $post_id The article.
	 * @return string An empty string when the person cannot edit the article.
	 */
	public static function fix_url( string $slug, int $post_id ): string {
		$target = $post_id;

		if ( in_array( $slug, array( 'add-alt', 'add-credit' ), true ) ) {
			$image = (int) get_post_thumbnail_id( $post_id );

			if ( $image > 0 && current_user_can( 'edit_post', $image ) ) {
				$target = $image;
			}
		}

		return (string) get_edit_post_link( $target, 'raw' );
	}

	/**
	 * One finding as a line: the severity icon, the message, and the fix link when there is one.
	 *
	 * @param array<string, mixed>  $finding The finding.
	 * @param int                   $post_id The article, for the fix link.
	 * @param bool                  $badge   Print the severity word before the message.
	 * @param array<string, string> $labels  Channel id => name; with it the line says which channels the finding is for.
	 * @return string Escaped HTML.
	 */
	public static function line( array $finding, int $post_id, bool $badge = false, array $labels = array() ): string {
		$severity = (string) ( $finding['severity'] ?? Status::INFO );
		$info     = Status::describe( $severity );
		$icon     = Icon::svg( $info['icon'] );
		$text     = Html::tag( 'span', array(), esc_html( (string) ( $finding['message'] ?? '' ) ) );
		$fix      = self::fix_of( $finding );
		$url      = '' === $fix ? '' : self::fix_url( $fix, $post_id );

		if ( '' !== $url && '' !== self::fix_label( $fix ) ) {
			$text .= ' ' . Html::tag( 'a', array( 'href' => $url ), esc_html( self::fix_label( $fix ) ) );
		}

		$names = array();

		foreach ( (array) ( $finding['channels'] ?? array() ) as $channel_id ) {
			if ( isset( $labels[ (string) $channel_id ] ) ) {
				$names[] = $labels[ (string) $channel_id ];
			}
		}

		if ( array() !== $names ) {
			$text .= ' ' . Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( wp_sprintf( '%l', $names ) ) );
		}

		return Html::tag(
			'li',
			array( 'class' => 'estasoft-nd-finding estasoft-nd-finding--' . $info['tone'] ),
			( $badge ? StatusView::pill( $severity ) : Html::tag( 'span', array( 'class' => 'estasoft-nd-finding__icon' ), $icon ) )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-grow' ), $text )
		);
	}

	/**
	 * A list of finding lines.
	 *
	 * @param array<int, array<string, mixed>> $findings Findings.
	 * @param int                              $post_id  The article.
	 * @param bool                             $badge    Print the severity word on each line.
	 * @param array<string, string>            $labels   Channel id => name, to say which channels a finding is for.
	 * @return string Escaped HTML, empty when there are no findings.
	 */
	public static function list( array $findings, int $post_id, bool $badge = false, array $labels = array() ): string {
		if ( array() === $findings ) {
			return '';
		}

		$items = '';

		foreach ( self::sorted( $findings ) as $finding ) {
			$items .= self::line( $finding, $post_id, $badge, $labels );
		}

		return Html::tag( 'ul', array( 'class' => 'estasoft-nd-findings' ), $items );
	}
}
