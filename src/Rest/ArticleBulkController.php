<?php
/**
 * REST: a bulk action on a selection of articles.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Rest;

use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Control\Bulk;

defined( 'ABSPATH' ) || exit;

/**
 * `POST /articles/bulk`. Body: `action` (check, send, hold or release), `ids` (at most 50 article
 * IDs) and, for a hold, `reason`.
 *
 * A short selection is done before the answer, which is 200 with the finished job. A longer one
 * is done in the background, 20 posts at a time: the answer is 202 with the job, and the caller
 * polls `GET /jobs/{id}`. The job's `result` names every post that was skipped and why.
 */
final class ArticleBulkController extends Controller {

	/**
	 * @return void
	 */
	public function register_routes(): void {
		$this->route(
			'/articles/bulk',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'run' ),
					'permission_callback' => $this->requires( Access::VIEW_OWN ),
					'args'                => array(
						'action' => array(
							'type'     => 'string',
							'required' => true,
							'enum'     => array_keys( Bulk::actions() ),
						),
						'ids'    => array(
							'type'     => 'array',
							'required' => true,
							'items'    => array( 'type' => 'integer' ),
						),
						'reason' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
					),
				),
			)
		);
	}

	/**
	 * POST /articles/bulk.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function run( \WP_REST_Request $request ) {
		$started = $this->plugin->make( Bulk::class )->start(
			(string) $request['action'],
			array_map( 'absint', (array) $request['ids'] ),
			array( 'reason' => (string) $request['reason'] )
		);

		if ( is_wp_error( $started ) ) {
			return $started;
		}

		$job = $this->plugin->jobs()->get( $started['job'] );
		$job = null === $job ? array() : $job;

		return new \WP_REST_Response( $job, 'done' === $started['state'] || 'failed' === $started['state'] ? 200 : 202 );
	}
}
