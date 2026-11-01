<?php
/**
 * Assign a fix: hand a problem found in one article to a person, and keep track.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\CheckTabs;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Control\AssignView;
use Estasoft\NewsDistribution\Admin\Control\Form;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Fixes;
use Estasoft\NewsDistribution\Core\Operations\Flash;

defined( 'ABSPATH' ) || exit;

/**
 * `&tab=assign`. An editor sees every problem in the latest check and can assign, remind, reassign and
 * close a fix. An author sees the tab only when a fix was handed to them, and can mark their own done.
 * The same service answers `GET|POST /articles/{id}/fixes` and `PATCH /fixes/{id}`.
 */
final class AssignTab extends AbstractCheckTab {

	public function slug(): string {
		return 'assign';
	}

	public function title(): string {
		return __( 'Assign a fix', 'estasoft-news-distribution' );
	}

	public function description(): string {
		return __( 'Hand a problem to a person, and see who is fixing it.', 'estasoft-news-distribution' );
	}

	public function order(): int {
		return 45;
	}

	public function permission(): string {
		return Access::VIEW_OWN;
	}

	public function available( int $post_id ): bool {
		if ( $post_id < 1 ) {
			return false;
		}

		if ( $this->plugin->access()->can( Access::VIEW, $post_id ) ) {
			return true;
		}

		return array() !== $this->plugin->make( Fixes::class )->for_article( $post_id );
	}

	public function enqueue(): void {
		Assets::screen_style( 'control' );
		Assets::screen_script( 'control' );
	}

	public function render( int $post_id ): string {
		if ( ! get_post( $post_id ) instanceof \WP_Post ) {
			return '';
		}

		return ( new AssignView( $this->plugin, $post_id, $this->url( $post_id ) ) )->render();
	}

	/**
	 * Does what a posted form asks, then goes back to the tab.
	 *
	 * @param int $post_id Article ID.
	 * @return void
	 */
	public function handle( int $post_id ): void {
		if ( ! $this->apply( $post_id ) ) {
			return;
		}

		wp_safe_redirect( $this->url( $post_id ) );
		exit;
	}

	/**
	 * Assigns a problem, sends a reminder or marks a fix done, when one of those forms was posted.
	 *
	 * The nonce and the person's access are checked first. The result is kept as a message for the next page.
	 *
	 * @param int $post_id Article ID.
	 * @return bool Whether a form was posted.
	 */
	public function apply( int $post_id ): bool {
		if ( ! Form::submitted( $post_id ) ) {
			return false;
		}

		$what = Form::posted();

		if ( ! in_array( $what, array( 'assign', 'remind', 'done' ), true ) ) {
			return false;
		}

		$fixes = $this->plugin->make( Fixes::class );

		if ( 'assign' === $what ) {
			$this->plugin->access()->require_or_die( Access::VIEW, $post_id );
			$this->assign( $fixes, $post_id );
		} elseif ( 'remind' === $what ) {
			$this->plugin->access()->require_or_die( Access::VIEW, $post_id );
			$this->remind( $fixes, $post_id );
		} else {
			$this->done( $fixes, $post_id );
		}

		return true;
	}

	/**
	 * Hands the problem to the person chosen in the form.
	 *
	 * @param Fixes $fixes   The fixes.
	 * @param int   $post_id Article ID.
	 * @return void
	 */
	private function assign( Fixes $fixes, int $post_id ): void {
		$fix = $fixes->assign(
			$post_id,
			array(
				'code'     => Form::text( 'code' ),
				'assignee' => absint( Form::text( 'assignee' ) ),
				'note'     => Form::text( 'note' ),
				'email'    => Form::checked( 'email' ),
			)
		);

		if ( is_wp_error( $fix ) ) {
			Flash::set( 'danger', $fix->get_error_message() );

			return;
		}

		Flash::set(
			'success',
			sprintf(
				/* translators: %s: a person's name. */
				__( 'Assigned to %s.', 'estasoft-news-distribution' ),
				(string) $fix['assignee']['name']
			) . ' ' . $this->told( $fix )
		);
	}

	/**
	 * Sends the person the same message again.
	 *
	 * @param Fixes $fixes   The fixes.
	 * @param int   $post_id Article ID.
	 * @return void
	 */
	private function remind( Fixes $fixes, int $post_id ): void {
		$fix = $this->own_fix( $fixes, $post_id );

		if ( null === $fix ) {
			return;
		}

		$fix = $fixes->remind( (int) $fix['id'] );

		if ( is_wp_error( $fix ) ) {
			Flash::set( 'danger', $fix->get_error_message() );

			return;
		}

		Flash::set(
			'success',
			sprintf(
				/* translators: %s: a person's name. */
				__( 'Reminded %s.', 'estasoft-news-distribution' ),
				(string) $fix['assignee']['name']
			) . ' ' . $this->told( $fix )
		);
	}

	/**
	 * Marks a fix done. An editor may close any fix of the article; a person may close their own.
	 *
	 * @param Fixes $fixes   The fixes.
	 * @param int   $post_id Article ID.
	 * @return void
	 */
	private function done( Fixes $fixes, int $post_id ): void {
		$fix = $this->own_fix( $fixes, $post_id );

		if ( null === $fix ) {
			return;
		}

		$mine = get_current_user_id() === (int) $fix['assignee']['id'];

		if ( ! $mine && ! $this->plugin->access()->can( Access::VIEW, $post_id ) ) {
			$this->plugin->access()->require_or_die( Access::VIEW, $post_id );
		}

		$done = $fixes->set_status( (int) $fix['id'], Fixes::DONE );

		if ( is_wp_error( $done ) ) {
			Flash::set( 'danger', $done->get_error_message() );

			return;
		}

		Flash::set( 'success', __( 'Marked done.', 'estasoft-news-distribution' ) );
	}

	/**
	 * The fix named in the form, when it belongs to this article and the person may see it.
	 *
	 * @param Fixes $fixes   The fixes.
	 * @param int   $post_id Article ID.
	 * @return array<string, mixed>|null
	 */
	private function own_fix( Fixes $fixes, int $post_id ): ?array {
		$fix = $fixes->find( absint( Form::text( 'fix' ) ) );

		if ( null === $fix || (int) $fix['post_id'] !== $post_id || ! $fixes->can_see( $fix ) ) {
			Flash::set( 'danger', __( 'That fix does not exist.', 'estasoft-news-distribution' ) );

			return null;
		}

		return $fix;
	}

	/**
	 * Whether the person was told, in a sentence.
	 *
	 * @param array<string, mixed> $fix A fix with `notified`.
	 * @return string
	 */
	private function told( array $fix ): string {
		$notified = (array) ( $fix['notified'] ?? array() );
		$status   = (string) ( $notified['status'] ?? '' );

		if ( 'sent' === $status ) {
			return __( 'They were told by email.', 'estasoft-news-distribution' );
		}

		if ( 'not_asked' === $status ) {
			return __( 'No email was sent.', 'estasoft-news-distribution' );
		}

		$error = trim( (string) ( $notified['error'] ?? '' ) );

		return '' === $error
			? __( 'The email could not be sent, so tell them yourself.', 'estasoft-news-distribution' )
			: sprintf(
				/* translators: %s: why the email failed. */
				__( 'The email could not be sent (%s), so tell them yourself.', 'estasoft-news-distribution' ),
				$error
			);
	}
}
