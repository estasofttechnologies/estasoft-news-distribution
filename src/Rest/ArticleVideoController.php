<?php
/**
 * REST routes of an article's videos, for the editor panel.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Rest;

use Estasoft\NewsDistribution\Content\VideoLookup;
use Estasoft\NewsDistribution\Content\Videos;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Markup\VideoFinder;

defined( 'ABSPATH' ) || exit;

/**
 * - `POST /articles/{id}/videos`: the videos in a text and what is known about each (name, thumbnail, upload date,
 *   duration, description, where it came from, what is missing and whether it gets markup). Body: `content` (the text in the
 *   editor, which may not be saved yet; default is the saved text) and `overrides` (the details typed in the editor and not
 *   saved yet; default is what is saved). Nothing is saved and no request is made to a video site.
 * - `POST /articles/{id}/videos/lookup`: puts the lookup of this article's videos in the queue, when "Look up details" is on.
 *   It answers at once with `queued` (a job is waiting, new or already there) and `pending` (how many videos have no
 *   answer yet); the answers arrive later, in the background. It reads the saved text, not the editor's.
 *
 * Permission: See Article check for the article, and for the lookup, the right to edit it.
 */
final class ArticleVideoController extends Controller {

	/**
	 * @return void
	 */
	public function register_routes(): void {
		$this->route(
			'/articles/(?P<id>\d+)/videos',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'videos' ),
				'permission_callback' => $this->requires_article( Access::VIEW_OWN ),
				'args'                => array(
					'content'   => array(
						'type'    => 'string',
						'default' => null,
					),
					'overrides' => array(
						'type'    => 'array',
						'items'   => array( 'type' => 'object' ),
						'default' => null,
					),
				),
			)
		);

		$this->route(
			'/articles/(?P<id>\d+)/videos/lookup',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'lookup' ),
				'permission_callback' => array( $this, 'can_edit' ),
			)
		);
	}

	/**
	 * Whether the person may edit the article in the address.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return bool
	 */
	public function can_edit( \WP_REST_Request $request ): bool {
		$id = (int) $request['id'];

		return $this->plugin->access()->can( Access::VIEW_OWN, $id ) && $this->plugin->access()->can_edit_article( $id );
	}

	/**
	 * The videos of a text.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function videos( \WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$post = get_post( $id );

		if ( ! $post instanceof \WP_Post ) {
			return $this->error( 'estasoft_nd_article_missing', __( 'That article does not exist.', 'estasoft-news-distribution' ), 404 );
		}

		$service = $this->plugin->make( Videos::class );
		$content = null === $request['content'] ? (string) $post->post_content : (string) $request['content'];
		$typed   = is_array( $request['overrides'] ) ? $request['overrides'] : null;
		$list    = array();
		$ready   = 0;
		$counted = 0;

		foreach ( $service->resolve( $id, $service->detect( $content ), $typed ) as $video ) {
			if ( ! empty( $video['included'] ) ) {
				++$counted;
			}

			if ( ! empty( $video['ready'] ) ) {
				++$ready;
			}

			$list[] = array(
				'key'         => $video['key'],
				'site'        => $video['site'],
				'url'         => '' !== (string) $video['url'] ? $video['url'] : $video['content_url'],
				'included'    => (bool) $video['included'],
				'reason'      => $video['reason'],
				'name'        => $video['name'],
				'description' => $video['description'],
				'thumbnail'   => $video['thumbnail'],
				'upload_date' => $video['upload_date'],
				'upload_day'  => substr( (string) $video['upload_date'], 0, 10 ),
				'duration'    => $video['duration'],
				'from'        => $video['from'],
				'skip'        => $video['skip'],
				'status'      => $video['status'],
				'missing'     => $video['missing'],
				'ready'       => $video['ready'],
				'needs_thumb' => VideoFinder::YOUTUBE !== $video['site'] && '' === (string) $video['thumbnail'],
			);
		}

		return rest_ensure_response(
			array(
				'enabled' => $service->enabled(),
				'lookup'  => $service->lookup_on(),
				'ask'     => 'ask' === ( $service->settings()['no_date'] ?? 'flag' ),
				'videos'  => $list,
				'ready'   => $ready,
				'counted' => $counted,
			)
		);
	}

	/**
	 * Queues the lookup.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function lookup( \WP_REST_Request $request ) {
		$id = (int) $request['id'];

		if ( ! get_post( $id ) instanceof \WP_Post ) {
			return $this->error( 'estasoft_nd_article_missing', __( 'That article does not exist.', 'estasoft-news-distribution' ), 404 );
		}

		$service = $this->plugin->make( Videos::class );

		if ( ! $service->lookup_on() ) {
			return $this->error( 'estasoft_nd_video_lookup_off', __( 'Look up details is off. Turn it on in Settings, Video.', 'estasoft-news-distribution' ), 409 );
		}

		$lookup = $this->plugin->make( VideoLookup::class );
		$queued = $lookup->queue( $id ) || $lookup->waiting( $id );

		return rest_ensure_response(
			array(
				'queued'  => $queued,
				'pending' => count( $lookup->pending( $id ) ),
			)
		);
	}
}
