<?php
/**
 * REST routes of the credit and rights fields of photos.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Rest;

use Estasoft\NewsDistribution\Content\ImageCredits;
use Estasoft\NewsDistribution\Content\PhotoMetadata;
use Estasoft\NewsDistribution\Core\Access;

defined( 'ABSPATH' ) || exit;

/**
 * - `POST /media/{id}/credit`: fills the empty credit, caption and source note of one photo from the
 *   metadata (IPTC and EXIF) of its file. A field that already has a value is never replaced: it is
 *   named in `kept`. Alt text is never filled. The answer has `filled`, `kept`, what the file `found`
 *   and what the photo `current`ly holds.
 * - `POST /media/credit/scan`: looks through the library for photos whose file has a credit and whose
 *   WordPress credit is empty, a batch at a time. Body: `offset`, `limit`.
 * - `GET /media/credit/candidates`: the list the last scan left, with `limit` and `offset`.
 * - `POST /media/credit/fill`: fills the photos named in `ids`, or up to 200 of the listed ones with
 *   `all`. Answers with a `batch` that `POST /media/credit/undo` takes back.
 * - `POST /media/credit/undo`: restores what one bulk fill wrote, leaving any field a person changed since.
 *
 * Permission: the single route needs the right to edit that photo and See Article check for own
 * articles. The library routes need Manage.
 */
final class MediaCreditController extends Controller {

	/**
	 * @return void
	 */
	public function register_routes(): void {
		$this->route(
			'/media/(?P<id>\d+)/credit',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'credit' ),
				'permission_callback' => array( $this, 'can_edit_photo' ),
			)
		);

		$this->route(
			'/media/credit/scan',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'scan' ),
				'permission_callback' => $this->requires( Access::MANAGE ),
				'args'                => array(
					'offset' => array(
						'type'              => 'integer',
						'default'           => 0,
						'minimum'           => 0,
						'sanitize_callback' => 'absint',
					),
					'limit'  => array(
						'type'              => 'integer',
						'default'           => 100,
						'minimum'           => 1,
						'maximum'           => 300,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		$this->route(
			'/media/credit/candidates',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'candidates' ),
				'permission_callback' => $this->requires( Access::MANAGE ),
				'args'                => array(
					'offset' => array(
						'type'              => 'integer',
						'default'           => 0,
						'minimum'           => 0,
						'sanitize_callback' => 'absint',
					),
					'limit'  => array(
						'type'              => 'integer',
						'default'           => 10,
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		$this->route(
			'/media/credit/fill',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'fill' ),
				'permission_callback' => $this->requires( Access::MANAGE ),
				'args'                => array(
					'ids' => array(
						'type'              => 'array',
						'default'           => array(),
						'items'             => array( 'type' => 'integer' ),
						'sanitize_callback' => static function ( $value ): array {
							return array_values( array_filter( array_map( 'absint', (array) $value ) ) );
						},
					),
					'all' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		$this->route(
			'/media/credit/undo',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'undo' ),
				'permission_callback' => $this->requires( Access::MANAGE ),
				'args'                => array(
					'batch' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Whether the person may fill this photo: they can edit it, and they may use the plugin for their own articles.
	 * A photo that does not exist passes so that the route can answer that it is not there.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return bool
	 */
	public function can_edit_photo( \WP_REST_Request $request ): bool {
		$id = (int) $request['id'];

		if ( ! $this->plugin->access()->can( Access::VIEW_OWN ) ) {
			return false;
		}

		return null === get_post( $id ) || current_user_can( 'edit_post', $id );
	}

	/**
	 * Fills the empty fields of one photo.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function credit( \WP_REST_Request $request ) {
		$id      = (int) $request['id'];
		$credits = $this->plugin->make( ImageCredits::class );

		if ( 'attachment' !== get_post_type( $id ) ) {
			return $this->error( 'estasoft_nd_media_missing', __( 'That file is not in the media library.', 'estasoft-news-distribution' ), 404 );
		}

		if ( ! $credits->is_image( $id ) ) {
			return $this->error( 'estasoft_nd_media_not_image', __( 'Credits are read from photos. This file is not an image.', 'estasoft-news-distribution' ), 400 );
		}

		$result = $credits->fill( $id );

		return new \WP_REST_Response(
			array(
				'id'      => $id,
				'filled'  => $result['filled'],
				'kept'    => $result['kept'],
				'found'   => array(
					'credit'  => $result['found'][ PhotoMetadata::CREDIT ],
					'caption' => $result['found'][ PhotoMetadata::CAPTION ],
					'note'    => $result['found'][ PhotoMetadata::NOTICE ],
				),
				'current' => $credits->stored( $id ),
				'message' => $this->message( $result ),
			)
		);
	}

	/**
	 * The sentence a screen can show for one fill.
	 *
	 * @param array{filled: string[], kept: string[], found: array<string, string>, written: array<string, string>} $result What the fill did.
	 * @return string
	 */
	private function message( array $result ): string {
		if ( array() !== $result['filled'] ) {
			return __( 'Filled from the photo file. A value you typed is never replaced.', 'estasoft-news-distribution' );
		}

		if ( '' === $result['found'][ PhotoMetadata::CREDIT ] && '' === $result['found'][ PhotoMetadata::CAPTION ] && '' === $result['found'][ PhotoMetadata::NOTICE ] ) {
			return __( 'The photo file has no credit, caption or copyright notice, so nothing was filled in.', 'estasoft-news-distribution' );
		}

		return __( 'Nothing was filled in: the fields that the file could fill already have a value.', 'estasoft-news-distribution' );
	}

	/**
	 * Looks through a batch of the library.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function scan( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( $this->plugin->make( ImageCredits::class )->scan( (int) $request['offset'], (int) $request['limit'] ) );
	}

	/**
	 * The list the last scan left.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function candidates( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( $this->plugin->make( ImageCredits::class )->candidates( (int) $request['limit'], (int) $request['offset'] ) );
	}

	/**
	 * Fills the photos asked for.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function fill( \WP_REST_Request $request ) {
		$credits = $this->plugin->make( ImageCredits::class );
		$ids     = (array) $request['ids'];

		if ( ! empty( $request['all'] ) ) {
			$ids = $credits->open_candidates( 200 );
		}

		if ( array() === $ids ) {
			return $this->error( 'estasoft_nd_media_none', __( 'Choose at least one photo to fill.', 'estasoft-news-distribution' ), 400 );
		}

		$done = $credits->fill_many( $ids );

		return new \WP_REST_Response(
			array_merge(
				$done,
				array( 'remaining' => count( $credits->open_candidates( 1000 ) ) )
			)
		);
	}

	/**
	 * Takes back one bulk fill.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function undo( \WP_REST_Request $request ) {
		$restored = $this->plugin->make( ImageCredits::class )->undo( (string) $request['batch'] );

		if ( null === $restored ) {
			return $this->error( 'estasoft_nd_media_no_batch', __( 'That fill can no longer be undone.', 'estasoft-news-distribution' ), 404 );
		}

		return new \WP_REST_Response( array( 'restored' => $restored ) );
	}
}
