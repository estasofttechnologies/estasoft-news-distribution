<?php
/**
 * REST: /articles and what hangs off one article.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Rest;

use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\ArticleService;

defined( 'ABSPATH' ) || exit;

/**
 * `GET /articles`, `GET|POST /articles/{id}/check`, `GET /articles/{id}/preview/{channel}`,
 * `GET /articles/{id}/history` and `POST /articles/{id}/send`.
 *
 * The work is in `Core\ArticleService`, which the Article check screen reads too. A
 * person who may only see their own articles gets a 403 for anyone else's. An article that
 * does not exist, or is not of a post type the site sends, is a 404.
 */
final class ArticlesController extends Controller {

	/**
	 * @return void
	 */
	public function register_routes(): void {
		$this->route(
			'/articles',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $this->requires( Access::VIEW_OWN ),
					'args'                => array_merge(
						$this->paging_args(),
						array(
							'language' => array(
								'type'              => 'string',
								'default'           => '',
								'sanitize_callback' => 'sanitize_text_field',
								'validate_callback' => static function ( $value ): bool {
									return '' === $value || 'all' === $value || 1 === preg_match( '/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})?$/', (string) $value );
								},
							),
							'status'   => array(
								'type'    => 'string',
								'default' => '',
								'enum'    => array( '', 'all', 'problems', 'waiting', 'publish', 'future', 'draft', 'pending' ),
							),
							'search'   => array(
								'type'              => 'string',
								'default'           => '',
								'sanitize_callback' => 'sanitize_text_field',
							),
						)
					),
				),
			)
		);

		$one = '/articles/(?P<id>\d+)';

		$this->route(
			$one . '/check',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'read_check' ),
					'permission_callback' => $this->gate( Access::VIEW_OWN ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'run_check' ),
					'permission_callback' => $this->gate( Access::VIEW_OWN ),
				),
			)
		);

		$this->route(
			$one . '/preview/(?P<channel>[a-z0-9-]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'preview' ),
					'permission_callback' => $this->gate( Access::VIEW_OWN ),
				),
			)
		);

		$this->route(
			$one . '/history',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'history' ),
					'permission_callback' => $this->gate( Access::VIEW_OWN ),
				),
			)
		);

		$this->route(
			$one . '/send',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'send' ),
					'permission_callback' => $this->gate( Access::VIEW ),
					'args'                => array(
						'channels' => array(
							'type'    => 'array',
							'default' => array(),
							'items'   => array( 'type' => 'string' ),
						),
					),
				),
			)
		);
	}

	/**
	 * GET /articles.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		$per_page = max( 1, min( 100, (int) $request['per_page'] ) );
		$result   = $this->service()->list(
			array(
				'language' => (string) $request['language'],
				'status'   => (string) $request['status'],
				'search'   => (string) $request['search'],
				'page'     => (int) $request['page'],
				'per_page' => $per_page,
			)
		);

		return $this->paged( $result['items'], $result['total'], $per_page );
	}

	/**
	 * GET /articles/{id}/check: the stored check, or an empty one when the article was never checked.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function read_check( \WP_REST_Request $request ) {
		return $this->answer( (int) $request['id'], false );
	}

	/**
	 * POST /articles/{id}/check: runs the checks again and stores the result.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function run_check( \WP_REST_Request $request ) {
		return $this->answer( (int) $request['id'], true );
	}

	/**
	 * GET /articles/{id}/preview/{channel}.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function preview( \WP_REST_Request $request ) {
		$known = $this->known( (int) $request['id'] );

		if ( is_wp_error( $known ) ) {
			return $known;
		}

		$preview = $this->service()->preview( (int) $request['id'], (string) $request['channel'] );

		return is_wp_error( $preview ) ? $preview : rest_ensure_response( $preview );
	}

	/**
	 * GET /articles/{id}/history.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function history( \WP_REST_Request $request ) {
		$known = $this->known( (int) $request['id'] );

		if ( is_wp_error( $known ) ) {
			return $known;
		}

		return rest_ensure_response( $this->service()->history( (int) $request['id'] ) );
	}

	/**
	 * POST /articles/{id}/send: answers 202 when something went in the queue.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function send( \WP_REST_Request $request ) {
		$id    = (int) $request['id'];
		$known = $this->known( $id );

		if ( is_wp_error( $known ) ) {
			return $known;
		}

		if ( ! $this->plugin->access()->can_edit_article( $id ) ) {
			return $this->error( 'estasoft_nd_forbidden', __( 'You are not allowed to send this article.', 'estasoft-news-distribution' ), rest_authorization_required_code() );
		}

		$channels = array_values( array_filter( array_map( 'sanitize_key', (array) $request['channels'] ) ) );
		$result   = $this->service()->send( $id, $channels );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response = new \WP_REST_Response( $result, array() === $result['queued'] ? 200 : 202 );

		return $response;
	}

	/**
	 * The check of an article, stored or run now.
	 *
	 * @param int  $id  Article ID.
	 * @param bool $run Run the checks.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function answer( int $id, bool $run ) {
		$known = $this->known( $id );

		if ( is_wp_error( $known ) ) {
			return $known;
		}

		$check = $this->service()->check( $id, $run );

		return null === $check ? $this->missing() : rest_ensure_response( $check );
	}

	/**
	 * A permission callback: the permission for the article in the address. A missing
	 * article passes the general check and is answered with a 404 by the route.
	 *
	 * @param string $permission One of the Core\Access constants.
	 * @return callable
	 */
	private function gate( string $permission ): callable {
		$access = $this->plugin->access();

		return static function ( \WP_REST_Request $request ) use ( $access, $permission ): bool {
			$id = (int) $request['id'];

			return $access->can( $permission, get_post( $id ) instanceof \WP_Post ? $id : null );
		};
	}

	/**
	 * Whether the article exists and is of a post type the site sends.
	 *
	 * @param int $id Article ID.
	 * @return true|\WP_Error
	 */
	private function known( int $id ) {
		$post = get_post( $id );

		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, $this->plugin->settings()->post_types(), true ) ) {
			return $this->missing();
		}

		return true;
	}

	/**
	 * The 404.
	 *
	 * @return \WP_Error
	 */
	private function missing(): \WP_Error {
		return $this->error( 'estasoft_nd_unknown_article', __( 'That article does not exist.', 'estasoft-news-distribution' ), 404 );
	}

	/**
	 * The article service.
	 *
	 * @return ArticleService
	 */
	private function service(): ArticleService {
		return $this->plugin->make( ArticleService::class );
	}
}
