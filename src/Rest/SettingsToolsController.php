<?php
/**
 * REST: the buttons of Alerts, Cache purge and Connections.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Rest;

use Estasoft\NewsDistribution\Admin\Operations\AlertsView;
use Estasoft\NewsDistribution\Admin\Operations\CachingView;
use Estasoft\NewsDistribution\Admin\Output;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Alerts;
use Estasoft\NewsDistribution\Core\CachePurge;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Connections;

defined( 'ABSPATH' ) || exit;

/**
 * Three things a person starts by pressing a button, each a POST that changes nothing on its own schedule:
 *
 * - `POST /settings/alerts/test-email`: sends one short email, with no article text, to the Send to
 *   addresses (or to the signed-in person when there are none). "Sent" means WordPress accepted it.
 * - `POST /settings/caching/test-purge`: asks each caching plugin to drop one made-up address.
 * - `POST /settings/connections/{id}/connect|check|disconnect`: the search engine connections.
 *   `connect` takes `credential` and the engine's plain fields, and contacts the engine; `check`
 *   contacts the engine; `disconnect` does not.
 *
 * The two tests answer with `html`, a line the screen prints beside the button.
 */
final class SettingsToolsController extends Controller {

	/**
	 * @return void
	 */
	public function register_routes(): void {
		$this->route(
			'/settings/alerts/test-email',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'test_email' ),
					'permission_callback' => $this->requires( Access::MANAGE ),
				),
			)
		);

		$this->route(
			'/settings/caching/test-purge',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'test_purge' ),
					'permission_callback' => $this->requires( Access::MANAGE ),
				),
			)
		);

		$this->route(
			'/settings/connections/(?P<id>[a-z0-9-]+)/(?P<op>connect|check|disconnect)',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'connection' ),
					'permission_callback' => $this->requires( Access::ADMIN ),
				),
			)
		);
	}

	/**
	 * Makes markup safe to hand to the script.
	 *
	 * @param string $html Markup built by the Ui components.
	 * @return string
	 */
	private function safe( string $html ): string {
		return wp_kses( $html, Output::allowed() );
	}

	/**
	 * POST /settings/alerts/test-email.
	 *
	 * @return \WP_REST_Response
	 */
	public function test_email() {
		$alerts = $this->plugin->make( Alerts::class );
		$result = $alerts->send_test();
		$last   = $alerts->last_test();

		if ( 'nobody' === $result['status'] ) {
			$last = array(
				'time'    => Clock::now(),
				'ok'      => false,
				'to'      => array(),
				'message' => $result['error'],
			);
		}

		return rest_ensure_response(
			array(
				'ok'      => 'sent' === $result['status'],
				'status'  => $result['status'],
				'to'      => $result['to'],
				'message' => $result['error'],
				'html'    => $this->safe( AlertsView::test_line( $last ) ),
			)
		);
	}

	/**
	 * POST /settings/caching/test-purge.
	 *
	 * @return \WP_REST_Response
	 */
	public function test_purge() {
		$row  = $this->plugin->make( CachePurge::class )->test();
		$view = new CachingView( $this->plugin );

		return rest_ensure_response(
			array(
				'ok'       => (bool) $row['ok'],
				'through'  => $row['through'],
				'html'     => $this->safe( $view->last_line() ),
				'log_html' => $this->safe( $view->log_table() ),
			)
		);
	}

	/**
	 * POST /settings/connections/{id}/{connect|check|disconnect}.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function connection( \WP_REST_Request $request ) {
		$service = $this->plugin->make( Connections::class );
		$id      = (string) $request['id'];

		if ( null === $service->get( $id ) ) {
			return $this->error( 'estasoft_nd_unknown_connection', __( 'That connection does not exist.', 'estasoft-news-distribution' ), 404 );
		}

		switch ( (string) $request['op'] ) {
			case 'connect':
				$params = $request->get_json_params();
				$params = is_array( $params ) ? $params : $request->get_body_params();
				$engine = $service->get( $id );
				$input  = array();

				if ( null !== $engine && is_array( $params ) ) {
					$input[ $engine->secret_field() ] = $params['credential'] ?? '';

					foreach ( $engine->plain_fields() as $field ) {
						if ( isset( $params[ $field ] ) ) {
							$input[ $field ] = $params[ $field ];
						}
					}
				}

				$done = $service->connect( $id, $input );

				return is_wp_error( $done ) ? $done : rest_ensure_response( $done );

			case 'check':
				$done = $service->check( $id );

				return is_wp_error( $done ) ? $done : rest_ensure_response( $done );

			default:
				$service->disconnect( $id );

				return rest_ensure_response( array( 'ok' => true ) );
		}
	}
}
