<?php
/**
 * REST routes of an article's content features: republished source and correction note. The videos have their own controller.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Rest;

use Estasoft\NewsDistribution\Content\Corrections;
use Estasoft\NewsDistribution\Content\Republished;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * - `POST /articles/{id}/republished-preview`: what would happen to the article with the
 *   source and handling in the body (not saved): what is added to the page, where it is skipped,
 *   and what stays on. Body: `handling`, `source`, `name`, `url`.
 * - `GET /articles/{id}/correction`: the current correction note and the log of notes.
 * - `POST /articles/{id}/correction`: save a note. Body: `note`, `show`. Nothing is published until
 *   this is called. It counts as a real update, so the modified date moves.
 *
 * Permission: See Article check for the article, and for a note, the right to edit the article.
 */
final class ArticleContentController extends Controller {

	/**
	 * @return void
	 */
	public function register_routes(): void {
		$this->route(
			'/articles/(?P<id>\d+)/republished-preview',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'republished_preview' ),
				'permission_callback' => $this->requires_article( Access::VIEW_OWN ),
				'args'                => array(
					'handling' => array(
						'type'    => 'string',
						'default' => '',
						'enum'    => array_merge( array( '' ), Republished::HANDLINGS ),
					),
					'source'   => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
					'name'     => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'url'      => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		$this->route(
			'/articles/(?P<id>\d+)/correction',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_correction' ),
					'permission_callback' => $this->requires_article( Access::VIEW_OWN ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_correction' ),
					'permission_callback' => array( $this, 'can_write_correction' ),
					'args'                => array(
						'note' => array(
							'type'     => 'string',
							'required' => true,
						),
						'show' => array(
							'type'    => 'boolean',
							'default' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * Whether the person may write a correction note on the article in the address.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return bool
	 */
	public function can_write_correction( \WP_REST_Request $request ): bool {
		$id = (int) $request['id'];

		return $this->plugin->access()->can( Access::VIEW_OWN, $id ) && $this->plugin->access()->can_edit_article( $id );
	}

	/**
	 * Previews the handling of a republished article.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function republished_preview( \WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$post = get_post( $id );

		if ( ! $post instanceof \WP_Post ) {
			return $this->error( 'estasoft_nd_article_missing', __( 'That article does not exist.', 'estasoft-news-distribution' ), 404 );
		}

		$content = $this->plugin->make( Republished::class );
		$details = Republished::clean_details(
			array(
				'source'   => (string) $request['source'],
				'name'     => (string) $request['name'],
				'url'      => (string) $request['url'],
				'handling' => (string) $request['handling'],
			)
		);
		$source  = $content->source( $details['source'] );
		$chosen  = '' !== $details['handling'] ? $details['handling'] : ( null !== $source && '' !== $source['handling'] ? $source['handling'] : $content->default_handling() );
		$final   = Republished::CANONICAL === $chosen && '' === $details['url'] ? Republished::NOINDEX : $chosen;
		$name    = null !== $source ? $source['name'] : $details['name'];
		$policy  = array(
			'handling' => $final,
			'name'     => $name,
			'url'      => $details['url'],
		);

		return rest_ensure_response(
			array(
				'handling'    => $final,
				'chosen'      => $chosen,
				'degraded'    => $final !== $chosen,
				'preview'     => $content->preview( $policy, $this->plugin->languages()->language_of_post( $id ) ),
				'url_invalid' => '' !== trim( (string) $request['url'] ) && '' === $details['url'],
			)
		);
	}

	/**
	 * The current correction note and the log.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function get_correction( \WP_REST_Request $request ) {
		return rest_ensure_response( $this->correction_body( (int) $request['id'] ) );
	}

	/**
	 * Saves a correction note.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_correction( \WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$result = $this->plugin->make( Corrections::class )->save( $id, (string) $request['note'], (bool) $request['show'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $this->correction_body( $id ) );
	}

	/**
	 * The shape both correction routes answer with. Times are ISO 8601 in UTC.
	 *
	 * @param int $post_id Article ID.
	 * @return array<string, mixed>
	 */
	private function correction_body( int $post_id ): array {
		$corrections = $this->plugin->make( Corrections::class );
		$current     = $corrections->current( $post_id );
		$log         = array();

		foreach ( $corrections->log( $post_id ) as $row ) {
			$user  = get_userdata( $row['by'] );
			$log[] = array(
				'at'    => Clock::iso( $row['at'] ),
				'by'    => $user instanceof \WP_User ? $user->display_name : '',
				'note'  => $row['note'],
				'shown' => $row['shown'],
			);
		}

		return array(
			'current' => null === $current ? null : array(
				'show' => $current['show'],
				'note' => $current['note'],
				'at'   => Clock::iso( $current['at'] ),
			),
			'log'     => $log,
		);
	}
}
