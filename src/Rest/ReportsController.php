<?php
/**
 * REST: the report data, and the button that starts a report's scan.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Rest;

use Estasoft\NewsDistribution\Admin\Reports\Parts\Reports;
use Estasoft\NewsDistribution\Admin\Reports\Parts\Scannable;
use Estasoft\NewsDistribution\Admin\Reports\Parts\Scans;
use Estasoft\NewsDistribution\Channels\SmartNews\ReadinessReport;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Digest;

defined( 'ABSPATH' ) || exit;

/**
 * - `GET /reports/{name}`: `fetch-monitor`, `translations`, `hreflang`, `discover`,
 *   `clashes`, `rendering`, `self-test`, and any other report class found in
 *   `src/Admin/Reports/`, each with the data its screen shows (the report's
 *   `data()`). `digest` is what Core\Digest builds; `readiness` is the SmartNews
 *   readiness report. When the class behind those two is not there, the answer is 404.
 * - `POST /reports/{name}`: for a report that reads pages (`self-test`, `hreflang`,
 *   `discover`). Starts the work in the queue and answers `202` with the job id and
 *   a `Location` header for `GET /jobs/{id}`.
 *
 * Times are ISO 8601 in UTC and statuses are Core\Status codes.
 */
final class ReportsController extends Controller {

	/**
	 * Reports that are built by a service class, not by a report screen.
	 *
	 * @return array<string, class-string>
	 */
	private function services(): array {
		$services = array(
			'digest'    => Digest::class,
			'readiness' => ReadinessReport::class,
		);

		/**
		 * Replaces the classes behind `GET /reports/digest` and `/readiness` (internal; tests use it).
		 *
		 * @param array<string, string> $services Report name => class name.
		 */
		$filtered = apply_filters( 'estasoft_nd_internal_report_services', $services );

		return is_array( $filtered ) ? $filtered : $services;
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$this->route(
			'/reports/(?P<name>[a-z0-9-]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'read' ),
					'permission_callback' => $this->requires( Access::VIEW ),
					'args'                => array(
						'period' => array(
							'type'    => 'string',
							'enum'    => array( 'so_far', 'last_week' ),
							'default' => 'so_far',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'start' ),
					'permission_callback' => $this->requires( Access::VIEW ),
				),
			)
		);
	}

	/**
	 * GET /reports/{name}.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function read( \WP_REST_Request $request ) {
		$name     = (string) $request['name'];
		$services = $this->services();

		if ( isset( $services[ $name ] ) ) {
			return $this->read_service( $name, $services[ $name ], (string) $request['period'] );
		}

		$report = Reports::find( $this->plugin, $name );

		if ( null === $report ) {
			return $this->unknown( $name );
		}

		if ( ! $this->plugin->access()->can( $report->permission() ) ) {
			return $this->forbidden();
		}

		return rest_ensure_response( array_merge( array( 'report' => $name ), $report->data() ) );
	}

	/**
	 * POST /reports/{name}.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function start( \WP_REST_Request $request ) {
		$name   = (string) $request['name'];
		$report = Reports::find( $this->plugin, $name );

		if ( null === $report ) {
			return $this->unknown( $name );
		}

		if ( ! $this->plugin->access()->can( $report->permission() ) ) {
			return $this->forbidden();
		}

		if ( ! $report instanceof Scannable ) {
			return $this->error( 'estasoft_nd_report_not_startable', __( 'This report reads stored figures. There is nothing to start.', 'estasoft-news-distribution' ), 405 );
		}

		$started = $this->plugin->make( Scans::class )->start( $name );

		if ( is_wp_error( $started ) ) {
			return $started;
		}

		$job      = (string) $started['job'];
		$response = new \WP_REST_Response(
			array(
				'report' => $name,
				'job'    => $job,
				'state'  => 'queued',
			),
			202
		);
		$response->header( 'Location', rest_url( self::REST_NAMESPACE . '/jobs/' . rawurlencode( $job ) ) );

		return $response;
	}

	/**
	 * The digest or the readiness report, from the class that builds it.
	 *
	 * @param string $name   `digest` or `readiness`.
	 * @param string $service_class The class that builds it.
	 * @param string $period `so_far` or `last_week`, for the digest.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function read_service( string $name, string $service_class, string $period ) {
		if ( ! $this->plugin->access()->can( Access::MANAGE ) ) {
			return $this->forbidden();
		}

		if ( ! class_exists( $service_class ) ) {
			return $this->error( 'estasoft_nd_report_missing', __( 'This report is not available on this site.', 'estasoft-news-distribution' ), 404 );
		}

		$service = $this->plugin->make( $service_class );

		if ( $service instanceof Digest ) {
			$data = $this->digest_times( $service->build( null, $period ) );
		} elseif ( method_exists( $service, 'build' ) ) {
			$data = $service->build();
		} else {
			$data = array();
		}

		if ( ! is_array( $data ) || array() === $data ) {
			return $this->error( 'estasoft_nd_report_empty', __( 'This report has nothing to show yet. Turn the channel on and try again.', 'estasoft-news-distribution' ), 404 );
		}

		if ( isset( $data['generated'] ) && is_int( $data['generated'] ) ) {
			$data['generated'] = Clock::iso( $data['generated'] );
		}

		return rest_ensure_response( array_merge( array( 'report' => $name ), $data ) );
	}

	/**
	 * The digest with its Unix times as ISO strings, as every REST answer has them.
	 *
	 * @param array<string, mixed> $digest What Core\Digest::build() returned.
	 * @return array<string, mixed>
	 */
	private function digest_times( array $digest ): array {
		if ( isset( $digest['period'] ) && is_array( $digest['period'] ) ) {
			foreach ( array( 'from', 'to' ) as $key ) {
				if ( isset( $digest['period'][ $key ] ) && is_int( $digest['period'][ $key ] ) ) {
					$digest['period'][ $key ] = Clock::iso( $digest['period'][ $key ] );
				}
			}
		}

		if ( isset( $digest['fetches'] ) && is_array( $digest['fetches'] ) ) {
			foreach ( $digest['fetches'] as $index => $fetch ) {
				if ( ! is_array( $fetch ) || ! isset( $fetch['languages'] ) || ! is_array( $fetch['languages'] ) ) {
					continue;
				}

				foreach ( $fetch['languages'] as $code => $state ) {
					if ( is_array( $state ) && isset( $state['last'] ) && is_int( $state['last'] ) ) {
						$digest['fetches'][ $index ]['languages'][ $code ]['last'] = $state['last'] > 0 ? Clock::iso( $state['last'] ) : null;
					}
				}
			}
		}

		return $digest;
	}

	/**
	 * A 404 for a name that is not a report.
	 *
	 * @param string $name What was asked for.
	 * @return \WP_Error
	 */
	private function unknown( string $name ): \WP_Error {
		return $this->error(
			'estasoft_nd_unknown_report',
			sprintf(
				/* translators: %s: the report name from the address. */
				__( 'There is no report called “%s”.', 'estasoft-news-distribution' ),
				$name
			),
			404
		);
	}

	/**
	 * A 403 for a report the person may not open.
	 *
	 * @return \WP_Error
	 */
	private function forbidden(): \WP_Error {
		return $this->error( 'rest_forbidden', __( 'You are not allowed to open this report.', 'estasoft-news-distribution' ), rest_authorization_required_code() );
	}
}
