<?php
/**
 * Take down: remove one article from every channel, channel by channel.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\CheckTabs;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Control\Form;
use Estasoft\NewsDistribution\Admin\Control\TakedownView;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Operations\Flash;
use Estasoft\NewsDistribution\Core\Takedown;

defined( 'ABSPATH' ) || exit;

/**
 * `&tab=takedown`, for editors. It is there for a published article, and for one that was taken down and
 * since switched to draft, so the person can still see what was done and restore it.
 *
 * Every form works without a script and posts to this tab. The same service answers
 * `GET|POST /articles/{id}/takedown`.
 */
final class TakedownTab extends AbstractCheckTab {

	public function slug(): string {
		return 'takedown';
	}

	public function title(): string {
		return __( 'Take down', 'estasoft-news-distribution' );
	}

	public function description(): string {
		return __( 'Remove this article from every channel.', 'estasoft-news-distribution' );
	}

	public function order(): int {
		return 50;
	}

	public function permission(): string {
		return Access::VIEW;
	}

	public function available( int $post_id ): bool {
		if ( $post_id < 1 ) {
			return false;
		}

		return 'publish' === get_post_status( $post_id ) || $this->plugin->make( Takedown::class )->is_down( $post_id );
	}

	public function enqueue(): void {
		Assets::screen_style( 'control' );
		Assets::screen_script( 'control' );
	}

	public function render( int $post_id ): string {
		if ( ! get_post( $post_id ) instanceof \WP_Post ) {
			return '';
		}

		return ( new TakedownView( $this->plugin, $post_id, $this->url( $post_id ) ) )->render();
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
	 * Starts, restores or switches to draft, when one of those forms was posted.
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

		if ( ! in_array( $what, array( 'start', 'restore', 'draft' ), true ) ) {
			return false;
		}

		$this->plugin->access()->require_or_die( Access::VIEW, $post_id );

		$takedown = $this->plugin->make( Takedown::class );

		if ( 'start' === $what ) {
			$result = $takedown->start( $post_id );

			if ( is_wp_error( $result ) ) {
				Flash::set( 'danger', $result->get_error_message() );
			} elseif ( $result['summary']['attention'] > 0 ) {
				Flash::set( 'warning', __( 'The article is out of your feeds and sitemaps, but at least one channel needs you to finish the job.', 'estasoft-news-distribution' ) );
			} else {
				Flash::set( 'success', __( 'The article is out of your feeds and sitemaps, and the removal notices were sent. Each platform decides when it drops the article.', 'estasoft-news-distribution' ) );
			}
		} elseif ( 'restore' === $what ) {
			$result = $takedown->restore( $post_id );

			Flash::set(
				is_wp_error( $result ) ? 'danger' : 'success',
				is_wp_error( $result ) ? $result->get_error_message() : __( 'The article is back in your feeds and sitemaps. Notices that were already sent cannot be taken back.', 'estasoft-news-distribution' )
			);
		} else {
			$result = $takedown->draft( $post_id );

			Flash::set(
				is_wp_error( $result ) ? 'danger' : 'success',
				is_wp_error( $result ) ? $result->get_error_message() : __( 'The post is now a draft. Readers no longer see it.', 'estasoft-news-distribution' )
			);
		}

		return true;
	}
}
