<?php
/**
 * The choices made for one article: `_estasoft_nd_options`.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Check;

use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Hold;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * One post meta value holds the per-article choices. The block editor panel reads and writes it
 * through the REST API (the meta is registered with `show_in_rest` and a schema), the classic
 * box writes it from a form, and other code reads it:
 *
 * - `breaking` (bool): breaking news; the outlets send at once.
 * - `minor_edit` (bool): the next save of a published article sends no update to the pushed
 *   channels. It is used once and then switched off.
 * - `channels` (map of channel id to bool): a pushed channel switched off for this article is
 *   left out (`false`). Anything not listed is on.
 * - `content_label` (`original`, `syndicated` or `ai_assisted`): read by the Apple News list.
 * - `access` (`everyone`, `subscribers`, `registration` or `metered`): read by the page markup.
 *
 * Other features keep their own keys here too (the release plan, the republished guard). This class
 * never drops or retypes a key it does not know: the sanitizer runs on every write of the meta,
 * including writes from other features, so it keeps booleans, numbers and nested lists as they are.
 * A feature that wants its key in the REST schema adds it through the internal filter
 * `estasoft_nd_internal_options_schema` (the properties of the JSON schema).
 */
final class ArticleOptions {

	public const META = '_estasoft_nd_options';

	public const MAX_KEY = 40;

	/**
	 * What an article has when nothing was chosen.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'breaking'      => false,
			'minor_edit'    => false,
			'channels'      => array(),
			'content_label' => 'original',
			'access'        => 'everyone',
			'republished'   => false,
		);
	}

	/**
	 * The content labels the panel offers, value => words.
	 *
	 * @return array<string, string>
	 */
	public static function content_labels(): array {
		return array(
			'original'    => __( 'Original', 'estasoft-news-distribution' ),
			'syndicated'  => __( 'Syndicated (wire or partner)', 'estasoft-news-distribution' ),
			'ai_assisted' => __( 'AI-assisted', 'estasoft-news-distribution' ),
		);
	}

	/**
	 * Who can read the article, value => words.
	 *
	 * @return array<string, string>
	 */
	public static function access_choices(): array {
		return array(
			'everyone'     => __( 'Everyone', 'estasoft-news-distribution' ),
			'subscribers'  => __( 'Subscribers only', 'estasoft-news-distribution' ),
			'registration' => __( 'Registration required', 'estasoft-news-distribution' ),
			'metered'      => __( 'Metered', 'estasoft-news-distribution' ),
		);
	}

	/**
	 * The stored choices of an article, with the defaults for what was never chosen.
	 *
	 * @param int $post_id Article ID.
	 * @return array<string, mixed>
	 */
	public static function get( int $post_id ): array {
		$stored = get_post_meta( $post_id, self::META, true );

		return array_merge( self::defaults(), self::sanitize( is_array( $stored ) ? $stored : array() ) );
	}

	/**
	 * Saves choices. Only the keys given change; the rest stay as they were stored.
	 *
	 * @param int                  $post_id Article ID.
	 * @param array<string, mixed> $values  Keys to change.
	 * @return void
	 */
	public static function update( int $post_id, array $values ): void {
		$stored = get_post_meta( $post_id, self::META, true );
		$stored = is_array( $stored ) ? $stored : array();

		update_post_meta( $post_id, self::META, array_merge( $stored, $values ) );
	}

	/**
	 * Cleans a value on its way to storage. The keys this class owns are checked one by one.
	 * Any other key is kept with its type: only text is cleaned.
	 *
	 * @param mixed $value What was given.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$clean = array();

		foreach ( $value as $key => $item ) {
			$key = is_int( $key ) ? (string) $key : sanitize_key( (string) $key );

			if ( '' === $key ) {
				continue;
			}

			switch ( $key ) {
				case 'breaking':
				case 'minor_edit':
				case 'republished':
					$clean[ $key ] = filter_var( $item, FILTER_VALIDATE_BOOLEAN );
					break;

				case 'content_label':
				case 'access':
					$word          = is_scalar( $item ) ? substr( sanitize_key( (string) $item ), 0, self::MAX_KEY ) : '';
					$clean[ $key ] = '' === $word ? (string) self::defaults()[ $key ] : $word;
					break;

				case 'channels':
					$clean[ $key ] = self::sanitize_channels( $item );
					break;

				default:
					$clean[ $key ] = self::keep( $item );
			}
		}

		return $clean;
	}

	/**
	 * The `channels` map: a channel id and whether it is on for this article.
	 *
	 * @param mixed $value The map.
	 * @return array<string, bool>
	 */
	private static function sanitize_channels( $value ): array {
		$out = array();

		foreach ( is_array( $value ) ? $value : array() as $id => $on ) {
			$id = sanitize_key( (string) $id );

			if ( '' !== $id ) {
				$out[ $id ] = filter_var( $on, FILTER_VALIDATE_BOOLEAN );
			}
		}

		return $out;
	}

	/**
	 * A value another feature owns: text is cleaned, every other type stays as it is.
	 *
	 * @param mixed $value The value.
	 * @return mixed
	 */
	private static function keep( $value ) {
		if ( is_array( $value ) ) {
			$out = array();

			foreach ( $value as $key => $item ) {
				$out[ is_int( $key ) ? $key : sanitize_text_field( (string) $key ) ] = self::keep( $item );
			}

			return $out;
		}

		if ( is_string( $value ) ) {
			return sanitize_textarea_field( $value );
		}

		return is_scalar( $value ) || null === $value ? $value : '';
	}

	/**
	 * Whether a channel is switched off for one article.
	 *
	 * @param int    $post_id    Article ID.
	 * @param string $channel_id Channel id.
	 * @return bool
	 */
	public static function channel_is_off( int $post_id, string $channel_id ): bool {
		$channels = self::get( $post_id )['channels'];

		return is_array( $channels ) && isset( $channels[ $channel_id ] ) && false === $channels[ $channel_id ];
	}

	/**
	 * What the delivery log says when a push is skipped because the person marked the save as a minor edit.
	 *
	 * @return string
	 */
	public static function minor_edit_message(): string {
		return __( 'Marked as a minor edit, so no update was sent.', 'estasoft-news-distribution' );
	}

	/**
	 * Whether a delivery log message says a push was skipped for a minor edit, whoever wrote it:
	 * this plugin's panel or a channel that reads the date guard.
	 *
	 * @param string $message The message of a skipped row.
	 * @return bool
	 */
	public static function is_minor_edit_message( string $message ): bool {
		$known = array(
			self::minor_edit_message(),
			__( 'A minor edit sends no notice.', 'estasoft-news-distribution' ),
			__( 'A minor edit sends no ping.', 'estasoft-news-distribution' ),
		);

		foreach ( $known as $text ) {
			if ( '' !== $text && 0 === strpos( $message, $text ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The JSON schema of the meta value, for the REST API.
	 *
	 * The two word keys carry no enum: another feature may store a value of its own, and a
	 * save that sends the whole object back must not fail on it.
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		$properties = array(
			'breaking'      => array( 'type' => 'boolean' ),
			'minor_edit'    => array( 'type' => 'boolean' ),
			'channels'      => array(
				'type'                 => 'object',
				'additionalProperties' => array( 'type' => 'boolean' ),
			),
			'content_label' => array( 'type' => 'string' ),
			'access'        => array( 'type' => 'string' ),
			'republished'   => array( 'type' => 'boolean' ),
		);

		/**
		 * Lets a feature declare more keys of the per-article choices.
		 *
		 * Internal. Return the properties of the JSON schema, keyed by the option name.
		 *
		 * @param array<string, array<string, mixed>> $properties The properties.
		 */
		$properties = apply_filters( 'estasoft_nd_internal_options_schema', $properties );

		return array(
			'type'                 => 'object',
			'properties'           => is_array( $properties ) ? $properties : array(),
			'additionalProperties' => true,
		);
	}

	/**
	 * Registers the meta for every post type the site sends, with the REST schema and the permission.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public static function register( Plugin $plugin ): void {
		$access = $plugin->access();

		foreach ( $plugin->settings()->post_types() as $type ) {
			register_post_meta(
				$type,
				self::META,
				array(
					'type'              => 'object',
					'single'            => true,
					'default'           => self::defaults(),
					'show_in_rest'      => array( 'schema' => self::schema() ),
					'sanitize_callback' => array( self::class, 'sanitize' ),
					'auth_callback'     => static function ( $allowed, $meta_key, $post_id, $user_id = 0, $cap = '' ) use ( $access, $plugin ): bool {
						if ( ! $access->can_edit_article( (int) $post_id, (int) $user_id ) ) {
							return false;
						}

						// Deleting the value would also delete a hold or a release plan, which only a person who may set one can take away.
						if ( 'delete_post_meta' === $cap && ! $access->can( Access::VIEW, null, (int) $user_id ) && $plugin->make( Hold::class )->has_decision( (int) $post_id ) ) {
							return false;
						}

						return true;
					},
				)
			);

			add_filter( 'rest_prepare_' . $type, array( self::class, 'hide_from_readers' ), 10, 2 );
		}
	}

	/**
	 * Takes the per-article choices out of a REST answer for a person who cannot edit the article.
	 *
	 * The meta is in the REST schema so the editor panel can read it, but it holds a hold's reason and the release
	 * plan, which are not for readers: the post's address answers to anyone for a published post.
	 *
	 * @param \WP_REST_Response $response The prepared answer.
	 * @param \WP_Post          $post     The post.
	 * @return \WP_REST_Response
	 */
	public static function hide_from_readers( $response, $post ) {
		if ( ! $response instanceof \WP_REST_Response || ! $post instanceof \WP_Post || current_user_can( 'edit_post', $post->ID ) ) {
			return $response;
		}

		$data = $response->get_data();

		if ( is_array( $data ) && isset( $data['meta'] ) && is_array( $data['meta'] ) && array_key_exists( self::META, $data['meta'] ) ) {
			unset( $data['meta'][ self::META ] );
			$response->set_data( $data );
		}

		return $response;
	}
}
