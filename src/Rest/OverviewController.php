<?php
/**
 * REST: /overview.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Rest;

use Estasoft\NewsDistribution\Admin\Overview\OverviewData;
use Estasoft\NewsDistribution\Core\Access;

defined( 'ABSPATH' ) || exit;

/**
 * `GET /overview` answers with what the Overview screen shows, for the View permission:
 *
 *     {
 *       "generated": "2026-10-21T14:35:00Z",
 *       "language": "all",
 *       "languages": [ { "code": "en", "name": "English (US)" } ],
 *       "summary": { "attention": 5, "channels_on": 12, "channels_total": 16, "articles": 5, "clean": false },
 *       "needs": [ { "id": "fetch-google-news", "kind": "fetch", "status": "attention", "title": "...", "detail": "...", "channel": "google-news", "action": { "label": "...", "url": "..." } } ],
 *       "groups": [ { "id": "sitemap", "title": "Sitemap", "help": "...", "total": 4, "counts": { "healthy": 2 }, "attention": 1, "open": true, "rows": [ { "key": "bing", "channel": "bing", "name": "...", "summary": "...", "status": "healthy", "enabled": true, "url": "..." } ] } ],
 *       "articles": [ { "id": 123, "title": "...", "language": "en", "published": "2026-10-21T14:23:00Z", "edit_link": "...", "groups": { "sitemap": { "status": "in_feed", "done": 3, "total": 3, "at": null, "channels": { } } }, "overall": { "status": "in_feed", "ok": "in_feed" } } ],
 *       "path": { "sections": [ { "id": "works", "title": "...", "rows": [ { "id": "google-news", "name": "...", "done": 2, "total": 3, "started": true, "live": false, "status": "attention", "text": "...", "action": { "label": "...", "url": "...", "primary": true } } ] } ], "started": 8, "live": 3, "all_live": false },
 *       "reminders": [ { "platform": "apple-news", "name": "Apple News", "date": "2026-10-28", "overdue": false } ]
 *     }
 *
 * Everything is read from stored results: the stored check of each article, the delivery log,
 * the fetch log, channel health and the application tracker. The route runs no check and calls
 * no other server. Statuses are `Core\Status` codes. `language` limits the latest articles and
 * the skipped-article items to one language; `path=0` leaves out the path to each platform.
 */
final class OverviewController extends Controller {

	/**
	 * @return void
	 */
	public function register_routes(): void {
		$this->route(
			'/overview',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'read' ),
					'permission_callback' => $this->requires( Access::VIEW ),
					'args'                => array(
						'language' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_key',
						),
						'path'     => array(
							'type'    => 'boolean',
							'default' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function read( \WP_REST_Request $request ): \WP_REST_Response {
		$data = ( new OverviewData( $this->plugin ) )->build(
			array(
				'language' => (string) $request['language'],
				'path'     => (bool) $request['path'],
			)
		);

		return new \WP_REST_Response( $data );
	}
}
