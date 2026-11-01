<?php
/**
 * REST routes of the image presets: the focal point of a photo and the platform copies made from it.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Rest;

use Estasoft\NewsDistribution\Content\ImageCredits;
use Estasoft\NewsDistribution\Content\ImagePresets;
use Estasoft\NewsDistribution\Core\Access;

defined( 'ABSPATH' ) || exit;

/**
 * - `GET /media/{id}/presets`: how the photo measures up for each platform (`original`, `focal`, `presets`).
 * - `POST /media/{id}/presets/{preset}`: makes the copy for one platform. The original is never changed.
 * - `POST /media/{id}/focal`: sets the focal point (`x` and `y`, fractions from 0 to 1), or clears it with
 *   `reset`. The copies made for the old point are removed, since they no longer keep the right part in view.
 *
 * Permission: Manage, and the right to edit that photo.
 */
final class MediaPresetsController extends Controller {

	/**
	 * @return void
	 */
	public function register_routes(): void {
		$this->route(
			'/media/(?P<id>\d+)/presets',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'read' ),
				'permission_callback' => array( $this, 'can_edit_photo' ),
			)
		);

		$this->route(
			'/media/(?P<id>\d+)/presets/(?P<preset>[a-z0-9-]+)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'make' ),
				'permission_callback' => array( $this, 'can_edit_photo' ),
			)
		);

		$this->route(
			'/media/(?P<id>\d+)/focal',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'focal' ),
				'permission_callback' => array( $this, 'can_edit_photo' ),
				'args'                => array(
					'x'     => array(
						'type'    => 'number',
						'minimum' => 0,
						'maximum' => 1,
					),
					'y'     => array(
						'type'    => 'number',
						'minimum' => 0,
						'maximum' => 1,
					),
					'reset' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}

	/**
	 * Whether the person manages the plugin and may edit this photo. A photo that is not there passes, so that the route can say so.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return bool
	 */
	public function can_edit_photo( \WP_REST_Request $request ): bool {
		$id = (int) $request['id'];

		return $this->plugin->access()->can( Access::MANAGE ) && ( null === get_post( $id ) || current_user_can( 'edit_post', $id ) );
	}

	/**
	 * Checks that the id is a photo.
	 *
	 * @param int $id Attachment ID.
	 * @return \WP_Error|null
	 */
	private function photo_error( int $id ): ?\WP_Error {
		if ( 'attachment' !== get_post_type( $id ) ) {
			return $this->error( 'estasoft_nd_media_missing', __( 'That file is not in the media library.', 'estasoft-news-distribution' ), 404 );
		}

		if ( ! $this->plugin->make( ImageCredits::class )->is_image( $id ) ) {
			return $this->error( 'estasoft_nd_media_not_image', __( 'Presets are made from photos. This file is not an image.', 'estasoft-news-distribution' ), 400 );
		}

		return null;
	}

	/**
	 * How a photo measures up for each platform.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function read( \WP_REST_Request $request ) {
		$id    = (int) $request['id'];
		$error = $this->photo_error( $id );

		if ( null !== $error ) {
			return $error;
		}

		$described = $this->plugin->make( ImagePresets::class )->describe( $id );

		if ( null === $described ) {
			return $this->error( 'estasoft_nd_preset_file', __( 'The size of the image cannot be read.', 'estasoft-news-distribution' ), 422 );
		}

		return new \WP_REST_Response( $described );
	}

	/**
	 * Makes one copy.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function make( \WP_REST_Request $request ) {
		$id    = (int) $request['id'];
		$error = $this->photo_error( $id );

		if ( null !== $error ) {
			return $error;
		}

		$presets = $this->plugin->make( ImagePresets::class );
		$copy    = $presets->make( $id, (string) $request['preset'] );

		if ( is_wp_error( $copy ) ) {
			return $this->error( $copy->get_error_code(), $copy->get_error_message(), 422 );
		}

		return new \WP_REST_Response(
			array(
				'copy'      => array(
					'url'    => $copy['url'],
					'width'  => $copy['width'],
					'height' => $copy['height'],
				),
				'described' => $presets->describe( $id ),
			)
		);
	}

	/**
	 * Sets or clears the focal point.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function focal( \WP_REST_Request $request ) {
		$id    = (int) $request['id'];
		$error = $this->photo_error( $id );

		if ( null !== $error ) {
			return $error;
		}

		$presets = $this->plugin->make( ImagePresets::class );

		if ( ! empty( $request['reset'] ) ) {
			$presets->set_focal( $id, null, null );
		} elseif ( null !== $request['x'] && null !== $request['y'] ) {
			$presets->set_focal( $id, (float) $request['x'], (float) $request['y'] );
		} else {
			return $this->error( 'estasoft_nd_focal_missing', __( 'Send the focal point as x and y, or ask to reset it.', 'estasoft-news-distribution' ), 400 );
		}

		return new \WP_REST_Response( $presets->describe( $id ) );
	}
}
