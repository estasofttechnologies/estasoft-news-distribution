<?php
/**
 * REST: /environment and /clock-check.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Rest;

use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\ClockCheck;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Environment;
use Estasoft\NewsDistribution\Core\Settings\Sections\Clock as ClockSection;

defined( 'ABSPATH' ) || exit;

/**
 * Two read-only routes for the site setup screens, both for the Manage permission.
 *
 * `GET /environment` answers with what News Distribution can see about the site:
 *
 *     {
 *       "environment_type": "staging",
 *       "staging": { "paused": true, "reason": "detected", "mode": "auto", "detected": "staging", "demo": false },
 *       "checked_at": "2026-10-21T11:35:00Z",
 *       "probes": {
 *         "robots": { "name": "robots", "label": "...", "status": "ok", "summary": "...", "detail": "...",
 *                     "data": { ... }, "checked_at": "2026-10-21T11:35:00Z", "cached": false }
 *       }
 *     }
 *
 * `status` is one of ok, info, warning, problem or unknown. A probe result is kept
 * for five minutes; `refresh=1` looks again. The probes `robots` and `rest` read the
 * site's own address, so the first call that needs them takes a moment. Nothing is
 * sent to any other server. `probe` limits the answer to some probes.
 *
 * `GET /clock-check` compares the clocks (see Core\ClockCheck::run()). The screen sends
 * the browser's own time (`browser_time`, Unix seconds) and zone (`browser_zone`, such
 * as "Europe/Amsterdam"); both are optional. With `html=1` the answer also holds the
 * screen's two result sections as ready markup, in `html`.
 */
final class EnvironmentController extends Controller {

	/**
	 * @return void
	 */
	public function register_routes(): void {
		$this->route(
			'/environment',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'read' ),
					'permission_callback' => $this->requires( Access::MANAGE ),
					'args'                => array(
						'refresh' => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'probe'   => array(
							'type'  => 'array',
							'items' => array(
								'type' => 'string',
								'enum' => Environment::probe_names(),
							),
						),
					),
				),
			)
		);

		$this->route(
			'/clock-check',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'clock' ),
					'permission_callback' => $this->requires( Access::MANAGE ),
					'args'                => array(
						'browser_time' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'browser_zone' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'html'         => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);
	}

	/**
	 * GET /environment.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function read( \WP_REST_Request $request ) {
		$environment = new Environment( $this->plugin );
		$refresh     = (bool) $request->get_param( 'refresh' );
		$wanted      = $request->get_param( 'probe' );
		$names       = is_array( $wanted ) && array() !== $wanted ? array_values( array_intersect( Environment::probe_names(), array_map( 'strval', $wanted ) ) ) : Environment::probe_names();
		$probes      = array();
		$latest      = 0;

		foreach ( $names as $name ) {
			$probe = $environment->probe( $name, true, $refresh );

			$latest              = max( $latest, (int) $probe['checked_at'] );
			$probe['checked_at'] = $probe['checked_at'] > 0 ? Clock::iso( (int) $probe['checked_at'] ) : null;
			$probes[ $name ]     = $probe;
		}

		return rest_ensure_response(
			array(
				'environment_type' => $environment->detected_type(),
				'staging'          => $environment->staging(),
				'checked_at'       => $latest > 0 ? Clock::iso( $latest ) : null,
				'probes'           => $probes,
			)
		);
	}

	/**
	 * GET /clock-check.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function clock( \WP_REST_Request $request ) {
		$browser = null;
		$time    = (int) $request->get_param( 'browser_time' );

		if ( $time > 0 ) {
			$browser = array(
				'time' => $time,
				'zone' => (string) $request->get_param( 'browser_zone' ),
			);
		}

		$report           = ( new ClockCheck( $this->plugin ) )->run( $browser );
		$report['ran_at'] = Clock::iso( (int) $report['ran_at'] );

		foreach ( $report['future']['items'] as $index => $item ) {
			$report['future']['items'][ $index ]['feed_at'] = Clock::iso( (int) $item['feed_ts'] );
		}

		if ( (bool) $request->get_param( 'html' ) ) {
			$report['html'] = array(
				'checks' => ClockSection::checks_html( $report ),
				'clocks' => ClockSection::clocks_html( $report ),
			);
		}

		return rest_ensure_response( $report );
	}
}
