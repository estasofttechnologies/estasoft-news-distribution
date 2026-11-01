<?php
/**
 * Which recent articles are labeled syndicated or AI-assisted.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\AppleNews;

use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Apple News asks publishers to mark syndicated and AI-generated content in
 * News Publisher. News Distribution cannot mark an article there; it lists the
 * articles the owner labeled so they can find them and mark them.
 *
 * The labels are read from post meta that the editor panel writes:
 * `_estasoft_nd_options` (`content_label` is `syndicated` or `ai_assisted`,
 * and `republished` is set for wire or partner copy) and `_estasoft_nd_ai`
 * (`assisted` is true when AI wrote text). Nothing is written here.
 */
final class Markings {

	/**
	 * Post meta with the owner's per-article choices.
	 */
	private const OPTIONS = '_estasoft_nd_options';

	/**
	 * Post meta with the AI record.
	 */
	private const AI = '_estasoft_nd_ai';

	/**
	 * The labels of one article.
	 *
	 * @param int $post_id Article ID.
	 * @return array{syndicated: bool, ai: bool}
	 */
	public static function of( int $post_id ): array {
		$options = get_post_meta( $post_id, self::OPTIONS, true );
		$options = is_array( $options ) ? $options : array();
		$record  = get_post_meta( $post_id, self::AI, true );
		$record  = is_array( $record ) ? $record : array();
		$label   = isset( $options['content_label'] ) ? (string) $options['content_label'] : '';

		return array(
			'syndicated' => 'syndicated' === $label || self::is_set( $options['republished'] ?? null ),
			'ai'         => 'ai_assisted' === $label || self::is_set( $record['assisted'] ?? null ),
		);
	}

	/**
	 * Recent articles with a label, newest first.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @param int    $days   How many days back to look.
	 * @param int    $limit  Most articles to read for each language.
	 * @return array<int, array{id: int, title: string, language: string, syndicated: bool, ai: bool}>
	 */
	public static function recent( Plugin $plugin, int $days = 30, int $limit = 100 ): array {
		$found = array();

		foreach ( $plugin->languages()->languages() as $language ) {
			$articles = $plugin->reader()->recent( (string) $language, $days * DAY_IN_SECONDS, $limit );

			foreach ( $articles as $article ) {
				if ( ! $article instanceof Article ) {
					continue;
				}

				$labels = self::of( $article->id() );

				if ( $labels['syndicated'] || $labels['ai'] ) {
					$found[] = array(
						'id'         => $article->id(),
						'title'      => $article->title(),
						'language'   => (string) $language,
						'syndicated' => $labels['syndicated'],
						'ai'         => $labels['ai'],
						'published'  => $article->published_ts(),
					);
				}
			}
		}

		usort(
			$found,
			static function ( array $a, array $b ): int {
				return $b['published'] <=> $a['published'];
			}
		);

		return array_map(
			static function ( array $row ): array {
				unset( $row['published'] );

				return $row;
			},
			$found
		);
	}

	/**
	 * How many of the listed articles carry each label.
	 *
	 * @param array<int, array{syndicated: bool, ai: bool}> $rows Rows from recent().
	 * @return array{syndicated: int, ai: int}
	 */
	public static function counts( array $rows ): array {
		$counts = array(
			'syndicated' => 0,
			'ai'         => 0,
		);

		foreach ( $rows as $row ) {
			$counts['syndicated'] += $row['syndicated'] ? 1 : 0;
			$counts['ai']         += $row['ai'] ? 1 : 0;
		}

		return $counts;
	}

	/**
	 * Whether a stored value counts as on: true, 1, a non-empty list, or text that is not 0 or false.
	 *
	 * @param mixed $value A stored value.
	 * @return bool
	 */
	private static function is_set( $value ): bool {
		if ( is_array( $value ) ) {
			return array() !== $value;
		}

		return null !== $value && '' !== $value && false !== $value && '0' !== $value && 0 !== $value && 'false' !== $value;
	}
}
