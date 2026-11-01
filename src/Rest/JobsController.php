<?php
/**
 * REST: /jobs/{id}.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Rest;

use Estasoft\NewsDistribution\Core\Access;

defined( 'ABSPATH' ) || exit;

/**
 * Progress of long work. See Core\Jobs.
 */
final class JobsController extends Controller {

	/**
	 * @return void
	 */
	public function register_routes(): void {
		$this->route(
			'/jobs/(?P<id>[a-z0-9]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'read' ),
					'permission_callback' => $this->requires( Access::VIEW_OWN ),
				),
			)
		);
	}

	/**
	 * GET /jobs/{id}.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function read( \WP_REST_Request $request ) {
		$job = $this->plugin->jobs()->get( (string) $request['id'] );

		// A job belongs to the person who started it; an administrator may follow any. Anyone else is told it is unknown.
		if ( null !== $job && (int) ( $job['user'] ?? 0 ) > 0 && get_current_user_id() !== (int) $job['user'] && ! $this->plugin->access()->can( Access::ADMIN ) ) {
			$job = null;
		}

		if ( null === $job ) {
			return $this->error( 'estasoft_nd_unknown_job', __( 'That task is no longer listed. Start it again if you still need it.', 'estasoft-news-distribution' ), 404 );
		}

		return rest_ensure_response( $job );
	}
}
