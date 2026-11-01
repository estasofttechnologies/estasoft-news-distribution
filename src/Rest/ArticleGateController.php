<?php
/**
 * REST: the pre-publish gate for one article.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Rest;

use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Gate;

defined( 'ABSPATH' ) || exit;

/**
 * - `GET /articles/{id}/gate`: how the saved article stands against the gate. `run=false` reads
 *   the stored check instead of running the checks again.
 * - `POST /articles/{id}/gate/override`: records that a person published the article although the
 *   gate was blocking it. Body: `reason`. The reason is saved in the audit table with the person's
 *   name. This route does not publish anything.
 *
 * In block mode the server enforces the gate too (`Modules\GateServerModule`), and the answer says so
 * (`enforced` is true). The override route below is the one the editor panel used before the reason
 * travelled with the save; the panel no longer calls it, and the server writes the same row when an
 * article goes live with a reason.
 */
final class ArticleGateController extends Controller {

	/**
	 * @return void
	 */
	public function register_routes(): void {
		$one = '/articles/(?P<id>\d+)/gate';

		$this->route(
			$one,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'read' ),
					'permission_callback' => $this->editing(),
					'args'                => array(
						'run' => array(
							'type'    => 'boolean',
							'default' => true,
						),
					),
				),
			)
		);

		$this->route(
			$one . '/override',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'override' ),
					'permission_callback' => $this->editing(),
					'args'                => array(
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
	 * GET /articles/{id}/gate.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function read( \WP_REST_Request $request ) {
		$gate  = $this->gate();
		$state = $gate->evaluate( (int) $request['id'], (bool) $request['run'] );

		if ( is_wp_error( $state ) ) {
			return $state;
		}

		return rest_ensure_response( array_merge( $state, $gate->config( (int) $request['id'] ) ) );
	}

	/**
	 * POST /articles/{id}/gate/override.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function override( \WP_REST_Request $request ) {
		$done = $this->gate()->override( (int) $request['id'], (string) $request['reason'] );

		if ( is_wp_error( $done ) ) {
			return $done;
		}

		$response = rest_ensure_response( $done );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Permission: the person can edit the article in the address.
	 *
	 * @return callable
	 */
	private function editing(): callable {
		$access = $this->plugin->access();

		return static function ( \WP_REST_Request $request ) use ( $access ): bool {
			return $access->can_edit_article( (int) $request['id'] );
		};
	}

	/**
	 * The gate service.
	 *
	 * @return Gate
	 */
	private function gate(): Gate {
		return $this->plugin->make( Gate::class );
	}
}
