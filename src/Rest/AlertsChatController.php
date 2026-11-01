<?php
/**
 * REST: the "Send test message" buttons of the Slack and Microsoft Teams alerts.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Rest;

use Estasoft\NewsDistribution\Admin\Operations\AlertsView;
use Estasoft\NewsDistribution\Admin\Output;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Alerts;

defined( 'ABSPATH' ) || exit;

/**
 * `POST /settings/alerts/test-chat` with `{ "service": "slack" | "teams" }` posts one short test message
 * to the address that is saved for that service. It is started by a person pressing a button and by nothing
 * else.
 *
 * - It needs the `manage` permission. Saving or replacing the address needs `admin`, and that is checked
 *   where the address is saved (`PUT /settings/alerts`), not here: this route never takes an address.
 * - "Sent" (status `sent`) means the service accepted the message. Nobody can see whether a person read it.
 * - With no address saved, nothing is sent and the status is `nobody`.
 * - The answer carries `html`, the line the screen prints beside the button. It never holds the address.
 */
final class AlertsChatController extends Controller {

	/**
	 * @return void
	 */
	public function register_routes(): void {
		$this->route(
			'/settings/alerts/test-chat',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'test_chat' ),
					'permission_callback' => $this->requires( Access::MANAGE ),
					'args'                => array(
						'service' => array(
							'description' => __( 'The chat service to send the test message to.', 'estasoft-news-distribution' ),
							'type'        => 'string',
							'enum'        => array( Alerts::SLACK, Alerts::TEAMS ),
							'required'    => true,
						),
					),
				),
			)
		);
	}

	/**
	 * POST /settings/alerts/test-chat.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function test_chat( \WP_REST_Request $request ) {
		$alerts  = $this->plugin->make( Alerts::class );
		$service = (string) $request['service'];

		if ( null === $alerts->chat_sender( $service ) ) {
			return $this->error( 'estasoft_nd_unknown_chat_service', __( 'That chat service does not exist.', 'estasoft-news-distribution' ), 404 );
		}

		$result = $alerts->send_test_chat( $service );
		$line   = AlertsView::chat_test_line( $alerts->last_chat_test( $service ), $alerts->has_chat_address( $service ) );

		return rest_ensure_response(
			array(
				'ok'      => 'sent' === $result['status'],
				'status'  => $result['status'],
				'service' => $service,
				'message' => $result['error'],
				'html'    => wp_kses( $line, Output::allowed() ),
			)
		);
	}
}
