<?php
/**
 * REST: /network/ai-policy, what AI may do on every site of a network.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Rest;

use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Network\AiOverview;
use Estasoft\NewsDistribution\Network\AiPolicy;

defined( 'ABSPATH' ) || exit;

/**
 * Registered only on a multisite network. Permission `network`.
 *
 * - `GET /network/ai-policy`: the policy, the providers it can allow, and what each site does with it
 *   (see `Network\AiOverview`). No key or secret exists in it; the plugin stores no AI keys.
 * - `PUT /network/ai-policy`: `providers` (ids), `drafts`, `approve_translations`, `second_approver_role`
 *   (`editors` or `admins`), `network_tokens_month`, `max_tokens_month`, `alert_at` (80 or 90) and
 *   `max_log_months` (12, 24 or 36). Fields left out keep their value. A value outside its range is
 *   rejected with the field named, never changed. Author names can never be sent and cannot be changed here.
 */
final class NetworkAiPolicyController extends Controller {

	/**
	 * @return void
	 */
	public function register_routes(): void {
		if ( ! is_multisite() ) {
			return;
		}

		$this->route(
			'/network/ai-policy',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'read' ),
					'permission_callback' => $this->requires( Access::NETWORK ),
					'args'                => array(
						'refresh' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'save' ),
					'permission_callback' => $this->requires( Access::NETWORK ),
				),
			)
		);
	}

	/**
	 * GET /network/ai-policy.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function read( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response( AiOverview::build( (bool) $request['refresh'] ) );
	}

	/**
	 * PUT /network/ai-policy.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save( \WP_REST_Request $request ) {
		$input = $request->get_json_params();
		$input = is_array( $input ) ? $input : $request->get_body_params();
		$saved = AiPolicy::save( is_array( $input ) ? $input : array() );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return rest_ensure_response( AiOverview::build() );
	}
}
