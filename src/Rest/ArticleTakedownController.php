<?php
/**
 * REST: the takedown of one article.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Rest;

use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Takedown;

defined( 'ABSPATH' ) || exit;

/**
 * - `GET /articles/{id}/takedown`: each channel's step (Removed, Sent or Needs attention, or what
 *   would happen before a takedown starts) and the summary.
 * - `POST /articles/{id}/takedown`: `action` is `start` (the default), `restore` (put the article
 *   back in the feeds and sitemaps) or `draft` (switch the post to draft).
 *
 * Taking an article down is for people who can see every article. The route reports what News
 * Distribution did. It never says a platform removed an article: only the channels News
 * Distribution builds the feed for are Removed, and a notice sent to a platform is Sent.
 */
final class ArticleTakedownController extends Controller {

	/**
	 * @return void
	 */
	public function register_routes(): void {
		$this->route(
			'/articles/(?P<id>\d+)/takedown',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'read' ),
					'permission_callback' => $this->requires_article( Access::VIEW ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'act' ),
					'permission_callback' => $this->requires_article( Access::VIEW ),
					'args'                => array(
						'action' => array(
							'type'    => 'string',
							'default' => 'start',
							'enum'    => array( 'start', 'restore', 'draft' ),
						),
					),
				),
			)
		);
	}

	/**
	 * GET /articles/{id}/takedown.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function read( \WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$post = get_post( $id );

		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, $this->plugin->settings()->post_types(), true ) ) {
			return $this->error( 'estasoft_nd_unknown_article', __( 'That article does not exist.', 'estasoft-news-distribution' ), 404 );
		}

		return rest_ensure_response( $this->service()->read( $id ) );
	}

	/**
	 * POST /articles/{id}/takedown.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function act( \WP_REST_Request $request ) {
		$id      = (int) $request['id'];
		$service = $this->service();

		switch ( (string) $request['action'] ) {
			case 'restore':
				$result = $service->restore( $id );
				break;

			case 'draft':
				$result = $service->draft( $id );
				break;

			default:
				$result = $service->start( $id );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response = rest_ensure_response( $result );

		if ( 'start' === (string) $request['action'] ) {
			$response->set_status( 201 );
		}

		return $response;
	}

	/**
	 * The takedown service.
	 *
	 * @return Takedown
	 */
	private function service(): Takedown {
		return $this->plugin->make( Takedown::class );
	}
}
