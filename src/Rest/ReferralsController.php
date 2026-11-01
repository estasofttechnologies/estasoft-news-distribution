<?php
/**
 * REST: the visit counter's public route, and the button that deletes the counts.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Rest;

use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Referrals\Counter;
use Estasoft\NewsDistribution\Core\Referrals\Limiter;
use Estasoft\NewsDistribution\Core\Referrals\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * - `POST /referrals/visit`: the one public route of the plugin. A reader's browser calls it from
 *   an article page, which a page cache may have served, so it takes no cookie and no nonce. It
 *   takes `post` (the article's number), `ref` (the host of the page the reader came from, at most
 *   100 characters of letters, digits, dots and dashes, or empty) and `utm` (the `utm_source`, at most
 *   40 characters, or empty). It answers `204` and adds 1 to a count; it keeps nothing else. It answers
 *   `404` while counting is off or when the post is not a published, public article of a chosen post type
 *   (and writes nothing), `400` for input that is missing or too long, and `429` with `Retry-After`
 *   when one article has counted more than the limit under one source in a minute. A reader who came
 *   from this site itself is answered `204` and not counted. Anyone can call it, so a count can be
 *   inflated; the screen says so.
 * - `DELETE /referrals/counts`: deletes every count. Administrators only.
 *
 * `GET /reports/referrals` is answered by the reports controller (`Admin\Reports\ReferralsReport::data()`),
 * with the argument `days` (7, 30 or 90).
 */
final class ReferralsController extends Controller {

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$this->route(
			'/referrals/visit',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'visit' ),
					// Public by necessity: the reader is not signed in, and a cached page cannot carry a nonce. It writes only a count, and checks every value.
					'permission_callback' => '__return_true',
					'args'                => array(
						'post' => array(
							'type'     => 'integer',
							'required' => true,
							'minimum'  => 1,
						),
						'ref'  => array(
							'type'      => 'string',
							'default'   => '',
							'maxLength' => Sources::MAX_HOST,
							'pattern'   => '^[a-z0-9.-]*$',
						),
						'utm'  => array(
							'type'      => 'string',
							'default'   => '',
							'maxLength' => Sources::MAX_UTM,
							'pattern'   => '^[a-z0-9_.-]*$',
						),
					),
				),
			)
		);

		$this->route(
			'/referrals/counts',
			array(
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_counts' ),
					'permission_callback' => $this->requires( Access::ADMIN ),
				),
			)
		);
	}

	/**
	 * POST /referrals/visit.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function visit( \WP_REST_Request $request ) {
		$counter = $this->plugin->make( Counter::class );

		if ( ! $counter->enabled() ) {
			return $this->error( 'estasoft_nd_referrals_off', __( 'This site is not counting visits by source.', 'estasoft-news-distribution' ), 404 );
		}

		$post_id = (int) $request['post'];

		if ( ! $counter->counts_for( $post_id ) ) {
			return $this->error( 'estasoft_nd_referral_unknown_post', __( 'That article cannot be counted.', 'estasoft-news-distribution' ), 404 );
		}

		$own    = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$source = Sources::classify( (string) $request['ref'], (string) $request['utm'], $own );

		// A reader who moves from one page of this site to another is not a visit from outside.
		if ( '' === $source ) {
			return new \WP_REST_Response( null, 204 );
		}

		$limit = Limiter::hit( $post_id, $source );

		if ( ! $limit['allowed'] ) {
			$response = rest_convert_error_to_response(
				$this->error( 'estasoft_nd_referral_rate_limited', __( 'This article has been counted many times in the last minute. Try again in a minute.', 'estasoft-news-distribution' ), 429 )
			);
			$response->header( 'Retry-After', (string) $limit['retry_after'] );

			return $response;
		}

		$counter->record( $post_id, $source );

		return new \WP_REST_Response( null, 204 );
	}

	/**
	 * DELETE /referrals/counts.
	 *
	 * @return \WP_REST_Response
	 */
	public function delete_counts(): \WP_REST_Response {
		return new \WP_REST_Response( array( 'deleted' => $this->plugin->make( Counter::class )->delete_all() ), 200 );
	}
}
