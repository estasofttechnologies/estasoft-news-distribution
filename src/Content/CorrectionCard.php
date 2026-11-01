<?php
/**
 * The "Correction note" card of the History tab of Article check.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Content;

use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the card and handles its form. The History tab calls `render( $post_id, $return_url )`
 * where it wants the card; the form posts to `admin-post.php` (`estasoft_nd_save_correction`), which
 * `Modules\LabelsModule` answers, saves the note with `Content\Corrections` and goes back to the
 * address the card was printed at. It works without a script. The same note can be saved through
 * `POST /articles/{id}/correction`.
 *
 * A person who may see the article but not edit it sees the note and no form.
 */
final class CorrectionCard {

	public const ACTION = 'estasoft_nd_save_correction';

	/**
	 * The card.
	 *
	 * @param Plugin $plugin     The running plugin.
	 * @param int    $post_id    Article ID.
	 * @param string $return_url Where the form goes back to; the History tab of the article.
	 * @return string Escaped HTML.
	 */
	public static function render( Plugin $plugin, int $post_id, string $return_url ): string {
		$corrections = $plugin->make( Corrections::class );
		$current     = $corrections->current( $post_id );
		$can_edit    = $plugin->access()->can( Access::VIEW_OWN, $post_id ) && $plugin->access()->can_edit_article( $post_id );
		$note        = null === $current ? '' : $current['note'];
		$show        = null === $current ? true : $current['show'];
		$status      = self::status( $current );
		$banner      = '';
		$result      = Query::key( 'correction' );

		if ( 'saved' === $result ) {
			$banner = Message::bar( 'success', esc_html__( 'Correction note saved.', 'estasoft-news-distribution' ) );
		} elseif ( 'error' === $result ) {
			$banner = Message::bar( 'danger', esc_html__( 'The note was not saved. Write the note first, or turn off “Show a correction note”.', 'estasoft-news-distribution' ) );
		}

		$id    = 'estasoft-nd-correction-' . $post_id;
		$field = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ),
			Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-check estasoft-nd-check--top',
					'for'   => $id . '-show',
				),
				Html::void(
					'input',
					array(
						'type'     => 'checkbox',
						'id'       => $id . '-show',
						'name'     => 'show',
						'value'    => '1',
						'checked'  => $show,
						'disabled' => ! $can_edit,
					)
				) . Html::tag(
					'span',
					array(),
					Html::tag( 'strong', array(), esc_html__( 'Show a correction note', 'estasoft-news-distribution' ) )
					. '<br>'
					. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'News Distribution adds the note to the end of the article text, so feeds that carry the full text include it.', 'estasoft-news-distribution' ) )
				)
			)
			. Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-sr',
					'for'   => $id . '-note',
				),
				esc_html__( 'Note', 'estasoft-news-distribution' )
			)
			. Html::tag(
				'textarea',
				array(
					'class'     => 'estasoft-nd-field estasoft-nd-w-full',
					'id'        => $id . '-note',
					'name'      => 'note',
					'rows'      => 3,
					'maxlength' => Corrections::MAX,
					'readonly'  => ! $can_edit,
				),
				esc_textarea( $note )
			)
			. Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
				esc_html__( 'Nothing is published until you save. A correction counts as a real update: the modified date moves (Date moved) and update notices go to the pushed channels. Platforms read the change on their own schedule.', 'estasoft-news-distribution' )
			)
		);

		$foot = Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $status ) );

		if ( $can_edit ) {
			$foot = Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-12' ),
				Button::submit( __( 'Save note', 'estasoft-news-distribution' ), array( 'variant' => 'primary' ) ) . $foot
			);
		}

		$row = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-form-row' ),
			Html::tag( 'div', array( 'class' => 'estasoft-nd-form-label' ), esc_html__( 'On the article', 'estasoft-news-distribution' ) )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-form-control' ), $field . $foot )
		);

		$body = $row;

		if ( $can_edit ) {
			$body = Html::tag(
				'form',
				array(
					'method' => 'post',
					'action' => admin_url( 'admin-post.php' ),
				),
				Html::void(
					'input',
					array(
						'type'  => 'hidden',
						'name'  => 'action',
						'value' => self::ACTION,
					)
				)
				. Html::void(
					'input',
					array(
						'type'  => 'hidden',
						'name'  => 'post',
						'value' => (string) $post_id,
					)
				)
				. Html::void(
					'input',
					array(
						'type'  => 'hidden',
						'name'  => 'return',
						'value' => $return_url,
					)
				)
				. wp_nonce_field( self::ACTION, '_wpnonce', false, false )
				. $row
			);
		}

		return Card::flush(
			'correction-' . $post_id,
			__( 'Correction note', 'estasoft-news-distribution' ),
			__( 'Tell readers what changed.', 'estasoft-news-distribution' ),
			$banner . $body
		);
	}

	/**
	 * The line next to the Save note button.
	 *
	 * @param array{show: bool, note: string, at: int, by: int}|null $current The current note, or null.
	 * @return string Plain text.
	 */
	private static function status( ?array $current ): string {
		if ( null === $current ) {
			return __( 'No note saved yet.', 'estasoft-news-distribution' );
		}

		return sprintf(
			/* translators: %s: a date and time, such as "21 Oct 2026, 14:21". */
			__( 'Saved %s.', 'estasoft-news-distribution' ),
			Clock::datetime( $current['at'] )
		);
	}

	/**
	 * Saves the note from the card's form and goes back.
	 *
	 * Called on `admin_post_estasoft_nd_save_correction`.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public static function handle( Plugin $plugin ): void {
		check_admin_referer( self::ACTION );

		$post_id = isset( $_POST['post'] ) ? absint( wp_unslash( $_POST['post'] ) ) : 0;

		if ( ! $plugin->access()->can( Access::VIEW_OWN, $post_id ) || ! $plugin->access()->can_edit_article( $post_id ) ) {
			$plugin->access()->deny();
		}

		$note   = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		$show   = ! empty( $_POST['show'] );
		$result = $plugin->make( Corrections::class )->save( $post_id, $note, $show );
		$back   = isset( $_POST['return'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['return'] ) ), '' ) : '';
		$back   = '' === $back ? add_query_arg(
			array(
				'page' => 'estasoft-nd-check',
				'post' => $post_id,
				'tab'  => 'history',
			),
			admin_url( 'admin.php' )
		) : $back;

		wp_safe_redirect( add_query_arg( 'correction', is_wp_error( $result ) ? 'error' : 'saved', $back ) );
		exit;
	}
}
