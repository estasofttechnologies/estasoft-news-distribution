<?php
/**
 * REST: /network/analytics, queue and delivery counts across a network.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Rest;

use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Network\Analytics;

defined( 'ABSPATH' ) || exit;

/**
 * Registered only on a multisite network. Permission `network`.
 *
 * `GET /network/analytics` takes `period` (`today`, `7d` or `30d`), `site` (one site's ID, or 0 for
 * every site) and `refresh=1` (read again instead of using the copy kept for five minutes). The
 * answer is described in `Network\Analytics`. It counts what News Distribution sent; it cannot
 * retry a delivery or say what a platform did with one.
 */
final class NetworkAnalyticsController extends Controller {

	/**
	 * @return void
	 */
	public function register_routes(): void {
		if ( ! is_multisite() ) {
			return;
		}

		$this->route(
			'/network/analytics',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $this->requires( Access::NETWORK ),
					'args'                => array(
						'period'  => array(
							'type'    => 'string',
							'default' => 'today',
							'enum'    => Analytics::PERIODS,
						),
						'site'    => array(
							'type'              => 'integer',
							'default'           => 0,
							'minimum'           => 0,
							'sanitize_callback' => 'absint',
						),
						'refresh' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);
	}

	/**
	 * GET /network/analytics.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function index( \WP_REST_Request $request ) {
		$site = (int) $request['site'];

		if ( $site > 0 && ! get_site( $site ) instanceof \WP_Site ) {
			return $this->error( 'estasoft_nd_site_not_found', __( 'That site does not exist.', 'estasoft-news-distribution' ), 404 );
		}

		return rest_ensure_response( ( new Analytics() )->build( (string) $request['period'], $site, (bool) $request['refresh'] ) );
	}
}
