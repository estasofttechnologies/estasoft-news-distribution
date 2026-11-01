<?php
/**
 * Asks YouTube and Vimeo for the details of a video, from the queue and only when the setting is on.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Content;

use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Queue;
use Estasoft\NewsDistribution\Markup\VideoFinder;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * "Look up details" in Settings, Video. It is off until a person turns it on, and when it is on:
 *
 * - It runs in the background queue (`estasoft_nd_video_lookup`, one job for each article), after an
 *   article with a video was saved. It never runs while an article is saved, when a page is shown, or
 *   in the editor.
 * - It sends one request for each video that has no saved answer: the video's address to the site's oEmbed
 *   endpoint. It sends nothing about the readers, no cookies and no account. See
 *   docs/external-services/youtube-vimeo.md.
 * - Each answer is saved with the article (`_estasoft_nd_video_lookup`), so a video is asked about once. An
 *   answer that said the video is private or removed is kept as such. A request that failed is tried
 *   again after an hour, when the article is saved again.
 *
 * What a site's oEmbed answer holds is not confirmed in this build (no request could be made while it was
 * written). From memory of the sites' documentation, both give the title and a thumbnail address, and Vimeo
 * also gives the upload date, the duration in seconds and a description. YouTube's answer has no upload date,
 * no duration and no description, so those are typed in the editor.
 *
 * Tests replace the request with the internal filter `estasoft_nd_internal_video_lookup`: it receives
 * `null`, the site, the video's address and the video, and returns what the site would answer (an array in the shape
 * of an oEmbed answer, or `array( 'status' => 'unavailable' )` for a private or removed video), a `WP_Error`
 * for a request that failed, or `null` to make the real request.
 */
final class VideoLookup {

	public const GROUP = 'estasoft_nd';

	private Plugin $plugin;

	/**
	 * Creates the service.
	 *
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * The address a site answers oEmbed requests at.
	 *
	 * @param string $site YouTube or Vimeo.
	 * @param string $url  The video's address.
	 * @return string An empty string for a site that has none.
	 */
	public static function endpoint( string $site, string $url ): string {
		if ( VideoFinder::YOUTUBE === $site ) {
			return add_query_arg(
				array(
					'format' => 'json',
					'url'    => $url,
				),
				'https://www.youtube.com/oembed'
			);
		}

		if ( VideoFinder::VIMEO === $site ) {
			return add_query_arg( 'url', $url, 'https://vimeo.com/api/oembed.json' );
		}

		return '';
	}

	/**
	 * The videos of an article that still need an answer.
	 *
	 * @param int $post_id Article ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function pending( int $post_id ): array {
		$videos  = $this->plugin->make( Videos::class );
		$post    = get_post( $post_id );
		$lookups = $videos->lookups( $post_id );
		$out     = array();

		if ( ! $post instanceof \WP_Post ) {
			return $out;
		}

		foreach ( $videos->detect( (string) $post->post_content ) as $video ) {
			if ( empty( $video['included'] ) || VideoFinder::OWN === $video['site'] ) {
				continue;
			}

			$answer = $lookups[ (string) $video['key'] ] ?? null;

			if ( null === $answer || ( 'error' === $answer['status'] && $answer['at'] + Videos::RETRY_AFTER <= Clock::now() ) ) {
				$out[] = $video;
			}
		}

		return $out;
	}

	/**
	 * Puts the job in the queue, when the setting is on and a video needs an answer.
	 *
	 * @param int $post_id Article ID.
	 * @return bool Whether a job was queued.
	 */
	public function queue( int $post_id ): bool {
		if ( ! $this->plugin->make( Videos::class )->lookup_on() || array() === $this->pending( $post_id ) ) {
			return false;
		}

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			return Queue::once( 0, Videos::HOOK, array( $post_id ), self::GROUP ) > 0;
		}

		return false !== wp_schedule_single_event( time() + 5, Videos::HOOK, array( $post_id ) );
	}

	/**
	 * Whether a job for this article is waiting in the queue.
	 *
	 * @param int $post_id Article ID.
	 * @return bool
	 */
	public function waiting( int $post_id ): bool {
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			return Queue::is_waiting( Videos::HOOK, array( $post_id ), self::GROUP );
		}

		return false !== wp_next_scheduled( Videos::HOOK, array( $post_id ) );
	}

	/**
	 * Asks for every video of an article that has no answer, and saves the answers. Called by the queue.
	 *
	 * @param int $post_id Article ID.
	 * @return int How many videos were asked about.
	 */
	public function run( int $post_id ): int {
		if ( ! $this->plugin->make( Videos::class )->lookup_on() ) {
			return 0;
		}

		$stored = get_post_meta( $post_id, Videos::LOOKUPS, true );
		$stored = is_array( $stored ) ? $stored : array();
		$asked  = 0;

		foreach ( $this->pending( $post_id ) as $video ) {
			$stored[ (string) $video['key'] ] = $this->ask( $video );
			++$asked;
		}

		if ( $asked > 0 ) {
			update_post_meta( $post_id, Videos::LOOKUPS, $stored );
		}

		return $asked;
	}

	/**
	 * Asks one site about one video.
	 *
	 * @param array<string, mixed> $video A video from the finder.
	 * @return array{status: string, at: int, name: string, thumbnail: string, upload_date: string, duration: int, description: string}
	 */
	public function ask( array $video ): array {
		$site   = (string) $video['site'];
		$url    = (string) $video['url'];
		$answer = array(
			'status'      => 'error',
			'at'          => Clock::now(),
			'name'        => '',
			'thumbnail'   => '',
			'upload_date' => '',
			'duration'    => 0,
			'description' => '',
		);

		/**
		 * Answers a video lookup without a request (internal, for tests).
		 *
		 * @param array|\WP_Error|null $pre   What the site answered, or null to ask it.
		 * @param string               $site  youtube or vimeo.
		 * @param string               $url   The video's address.
		 * @param array                $video The video.
		 */
		$pre = apply_filters( 'estasoft_nd_internal_video_lookup', null, $site, $url, $video );

		if ( null !== $pre ) {
			return is_array( $pre ) ? $this->read( $pre, $answer ) : $answer;
		}

		$endpoint = self::endpoint( $site, $url );

		if ( '' === $endpoint ) {
			return $answer;
		}

		$response = wp_safe_remote_get(
			$endpoint,
			array(
				'timeout'             => 8,
				'redirection'         => 2,
				'limit_response_size' => 262144,
				'user-agent'          => 'EstasoftNewsDistribution/' . ESTASOFT_ND_VERSION . '; ' . home_url(),
				'headers'             => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $answer;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( in_array( $code, array( 401, 403, 404 ), true ) ) {
			$answer['status'] = 'unavailable';

			return $answer;
		}

		if ( 200 !== $code ) {
			return $answer;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return is_array( $data ) ? $this->read( $data, $answer ) : $answer;
	}

	/**
	 * Takes the details out of an oEmbed answer.
	 *
	 * @param array<string, mixed> $data   The answer, decoded.
	 * @param array<string, mixed> $answer The answer being built.
	 * @return array{status: string, at: int, name: string, thumbnail: string, upload_date: string, duration: int, description: string}
	 */
	private function read( array $data, array $answer ): array {
		if ( isset( $data['status'] ) && 'unavailable' === $data['status'] ) {
			$answer['status'] = 'unavailable';

			return $answer;
		}

		$answer['status']      = 'ok';
		$answer['name']        = isset( $data['title'] ) && is_scalar( $data['title'] ) ? mb_substr( sanitize_text_field( (string) $data['title'] ), 0, 200 ) : '';
		$answer['thumbnail']   = isset( $data['thumbnail_url'] ) && is_string( $data['thumbnail_url'] ) && Republished::valid_url( $data['thumbnail_url'] ) ? esc_url_raw( $data['thumbnail_url'], array( 'http', 'https' ) ) : '';
		$answer['upload_date'] = Videos::clean_date( $data['upload_date'] ?? '' );
		$answer['duration']    = Videos::clean_duration( $data['duration'] ?? 0 );
		$answer['description'] = isset( $data['description'] ) && is_scalar( $data['description'] ) ? mb_substr( sanitize_textarea_field( (string) $data['description'] ), 0, 1000 ) : '';

		return $answer;
	}
}
