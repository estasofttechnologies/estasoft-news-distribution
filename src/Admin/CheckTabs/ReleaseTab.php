<?php
/**
 * Release plan: when each channel gets the article, and the hold.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\CheckTabs;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Check\Times;
use Estasoft\NewsDistribution\Admin\Control\Form;
use Estasoft\NewsDistribution\Admin\Control\ReleaseView;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Control\Plan;
use Estasoft\NewsDistribution\Core\Hold;
use Estasoft\NewsDistribution\Core\Operations\Flash;

defined( 'ABSPATH' ) || exit;

/**
 * `&tab=release`. Everyone who can open the article's check sees the timeline; an editor can change the
 * plan and hold the article. The forms work without a script and post to this tab; the script only hides
 * the rows that do not apply to the release mode that is chosen.
 *
 * The same services answer `GET|PUT /articles/{id}/release` and `POST|DELETE /articles/{id}/hold`.
 */
final class ReleaseTab extends AbstractCheckTab {

	public function slug(): string {
		return 'release';
	}

	public function title(): string {
		return __( 'Release plan', 'estasoft-news-distribution' );
	}

	public function description(): string {
		return __( 'Who receives this article, and when.', 'estasoft-news-distribution' );
	}

	public function order(): int {
		return 40;
	}

	public function permission(): string {
		return Access::VIEW_OWN;
	}

	public function enqueue(): void {
		Assets::screen_style( 'control' );
		Assets::screen_script( 'control' );
	}

	public function render( int $post_id ): string {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		return ( new ReleaseView( $this->plugin, $post_id, $this->url( $post_id ) ) )->render();
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
	 * Saves the plan, ends an exclusive, holds the article or releases the hold, when one of those forms was posted.
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

		if ( ! in_array( $what, array( 'plan', 'end', 'hold', 'release_hold' ), true ) ) {
			return false;
		}

		$this->plugin->access()->require_or_die( Access::VIEW, $post_id );

		$holds = $this->plugin->make( Hold::class );

		if ( 'plan' === $what ) {
			$result = $holds->save_plan(
				$post_id,
				array(
					'mode'         => Form::text( 'mode' ),
					'exclusive'    => Form::text( 'exclusive' ),
					'duration'     => Form::text( 'duration' ),
					'delay_search' => Form::checked( 'delay_search' ),
					'times'        => $this->times(),
				)
			);

			$this->report( $result, 'plan' );
		} elseif ( 'end' === $what ) {
			$this->report( $holds->end_exclusive( $post_id ), 'end' );
		} elseif ( 'hold' === $what ) {
			$this->report( $holds->hold( $post_id, Form::text( 'reason' ) ), 'hold' );
		} else {
			$this->report( $holds->lift( $post_id ), 'release_hold' );
		}

		return true;
	}

	/**
	 * The times of a custom plan as posted. An empty choice means "the usual" and is left out.
	 *
	 * @return array<string, int>
	 */
	private function times(): array {
		$times = array();

		foreach ( Form::items( 'times' ) as $channel => $seconds ) {
			if ( '' !== $seconds && is_numeric( $seconds ) ) {
				$times[ (string) $channel ] = (int) $seconds;
			}
		}

		return $times;
	}

	/**
	 * Keeps a message for the page the person is sent back to.
	 *
	 * @param array<string, mixed>|\WP_Error $result What the service returned.
	 * @param string                         $what   Which form it was.
	 * @return void
	 */
	private function report( $result, string $what ): void {
		if ( is_wp_error( $result ) ) {
			Flash::set( 'danger', $result->get_error_message() );

			return;
		}

		if ( 'plan' === $what ) {
			Flash::set( 'success', $this->plan_saved( $result ) );
		} elseif ( 'end' === $what ) {
			Flash::set( 'success', __( 'The exclusive is over. The other channels are going out now.', 'estasoft-news-distribution' ) );
		} elseif ( 'hold' === $what ) {
			Flash::set( 'success', __( 'The article is on hold. Partner feeds, feed readers and pushes do not carry it until you release the hold.', 'estasoft-news-distribution' ) );
		} else {
			$queued = isset( $result['queued'] ) && is_array( $result['queued'] ) ? count( $result['queued'] ) : 0;
			$text   = __( 'Hold released. Partner feeds and feed readers carry the article again.', 'estasoft-news-distribution' );

			if ( $queued > 0 ) {
				$text .= ' ' . sprintf(
					/* translators: %d: how many pushes were queued. */
					_n( '%d push was queued.', '%d pushes were queued.', $queued, 'estasoft-news-distribution' ),
					$queued
				);
			}

			Flash::set( 'success', $text );
		}
	}

	/**
	 * The sentence after a plan is saved: what the plan now does.
	 *
	 * @param array<string, mixed> $state What `Hold::state()` returned.
	 * @return string
	 */
	private function plan_saved( array $state ): string {
		$mode = (string) $state['plan']['mode'];

		if ( Plan::MODE_EXCLUSIVE === $mode && null !== $state['exclusive']['ends'] ) {
			return sprintf(
				/* translators: %s: a time such as 14:38. */
				__( 'Plan saved. The other channels go out at %s.', 'estasoft-news-distribution' ),
				Times::at( (int) $state['exclusive']['ends'] )
			);
		}

		if ( Plan::MODE_EXCLUSIVE === $mode ) {
			return __( 'Plan saved. The other channels wait until you end the exclusive.', 'estasoft-news-distribution' );
		}

		if ( Plan::MODE_NOW === $mode ) {
			return __( 'Plan saved. Every channel goes out right away.', 'estasoft-news-distribution' );
		}

		if ( Plan::MODE_CUSTOM === $mode ) {
			return __( 'Plan saved. Each channel goes out at the time you chose.', 'estasoft-news-distribution' );
		}

		return __( 'Plan saved. Pushes go out after the usual delay.', 'estasoft-news-distribution' );
	}
}
