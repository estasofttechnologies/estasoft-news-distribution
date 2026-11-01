<?php
/**
 * What the block editor panel and the classic editor box know about one article.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Check;

use Estasoft\NewsDistribution\Channels\ChannelInterface;
use Estasoft\NewsDistribution\Channels\Group;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\ArticleService;
use Estasoft\NewsDistribution\Core\Language\Catalog;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Core\Translations;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Read once when the editor opens and handed to the script as the starting state. After that the panel
 * asks the REST API (`GET` and `POST /articles/{id}/check`), which answers with the same shape in
 * `check`. The starting state is also what the panel shows to a person whose role the REST routes refuse,
 * so it never goes blank.
 */
final class PanelState {

	/**
	 * The state for one article.
	 *
	 * @param Plugin $plugin  The running plugin.
	 * @param int    $post_id The article.
	 * @return array<string, mixed>
	 */
	public static function build( Plugin $plugin, int $post_id ): array {
		$access   = $plugin->access();
		$links    = new Links( $plugin );
		$post     = get_post( $post_id );
		$type     = $post instanceof \WP_Post ? $post->post_type : '';
		$check    = $plugin->make( ArticleService::class )->check( $post_id, false );
		$language = $plugin->languages()->language_of_post( $post_id );
		$labels   = array();
		$urls     = array();

		foreach ( array( 'add-image', 'add-alt', 'add-credit', 'edit-title', 'fix-date', 'set-author' ) as $slug ) {
			$labels[ $slug ] = Findings::fix_label( $slug );
			$urls[ $slug ]   = in_array( $slug, array( 'add-alt', 'add-credit' ), true ) ? Findings::fix_url( $slug, $post_id ) : '';
		}

		$words = array();

		foreach ( array( Status::IN_FEED, Status::SENT, Status::QUEUED, Status::RETRY, Status::FAILED, Status::SKIPPED, Status::OFF, Status::CLEAN ) as $code ) {
			$words[ $code ] = Status::word( $code );
		}

		return array(
			'postId'        => $post_id,
			'postType'      => $type,
			'language'      => $language,
			'languageName'  => Catalog::name( $language ),
			'check'         => $check,
			'options'       => ArticleOptions::get( $post_id ),
			'metaKey'       => ArticleOptions::META,
			'canSave'       => '' !== $type && post_type_supports( $type, 'custom-fields' ),
			'canEdit'       => $access->can_edit_article( $post_id ),
			'contentLabels' => self::choices( ArticleOptions::content_labels() ),
			'accessChoices' => self::choices( ArticleOptions::access_choices() ),
			'channels'      => self::channels( $plugin, $language ),
			'notSetUp'      => self::not_set_up( $plugin ),
			'translations'  => self::translations( $plugin, $post_id, $language ),
			'fixLabels'     => $labels,
			'fixUrls'       => $urls,
			'statusWords'   => $words,
			'pageUrl'       => $access->can( Access::VIEW_OWN, $post_id ) ? $links->page( 'estasoft-nd-check', array( 'post' => $post_id ) ) : '',
			'channelsUrl'   => $links->page( 'estasoft-nd-channels' ),
		);
	}

	/**
	 * A map as the list of choices a select takes.
	 *
	 * @param array<string, string> $map Value => words.
	 * @return array<int, array{value: string, label: string}>
	 */
	private static function choices( array $map ): array {
		$out = array();

		foreach ( $map as $value => $label ) {
			$out[] = array(
				'value' => (string) $value,
				'label' => (string) $label,
			);
		}

		return $out;
	}

	/**
	 * The channels that are on and take the article's language, in hub order. Channels set up by hand
	 * (listings, site verification) do nothing for a single article and are left out.
	 *
	 * @param Plugin $plugin   The running plugin.
	 * @param string $language The article's language.
	 * @return array<int, array{id: string, label: string, mode: string, group: string, groupTitle: string}>
	 */
	private static function channels( Plugin $plugin, string $language ): array {
		$titles = Group::all();
		$found  = array();

		foreach ( $plugin->registry()->enabled() as $channel ) {
			if ( ChannelInterface::MODE_MANUAL === $channel->mode() || ! $channel->accepts_language( $language ) ) {
				continue;
			}

			$found[] = $channel;
		}

		$rank = array_flip( array_keys( $titles ) );

		usort(
			$found,
			static function ( ChannelInterface $a, ChannelInterface $b ) use ( $rank ): int {
				return array( $rank[ $a->group() ] ?? 99, $a->order() ) <=> array( $rank[ $b->group() ] ?? 99, $b->order() );
			}
		);

		$out = array();

		foreach ( $found as $channel ) {
			$out[] = array(
				'id'         => $channel->id(),
				'label'      => $channel->label(),
				'mode'       => $channel->mode(),
				'group'      => $channel->group(),
				'groupTitle' => (string) ( $titles[ $channel->group() ]['title'] ?? '' ),
			);
		}

		return $out;
	}

	/**
	 * Names of the languages the article has no version in, which the panel and the classic box count as things to fix.
	 *
	 * @param Plugin $plugin  The running plugin.
	 * @param int    $post_id The article.
	 * @return string[] Empty on a site with one language, or when every language has a version.
	 */
	public static function missing_translations( Plugin $plugin, int $post_id ): array {
		$names = array();

		foreach ( self::translations( $plugin, $post_id, $plugin->languages()->language_of_post( $post_id ) ) as $item ) {
			if ( 0 === $item['id'] ) {
				$names[] = $item['name'];
			}
		}

		return $names;
	}

	/**
	 * Names of the channels that are off, so the panel can say they are not set up.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return string[]
	 */
	private static function not_set_up( Plugin $plugin ): array {
		$out = array();

		foreach ( $plugin->registry()->all() as $channel ) {
			if ( ChannelInterface::MODE_MANUAL !== $channel->mode() && ! $channel->is_enabled() ) {
				$out[] = $channel->label();
			}
		}

		return $out;
	}

	/**
	 * The other languages of the site and whether this article has a version in each.
	 *
	 * @param Plugin $plugin   The running plugin.
	 * @param int    $post_id  The article.
	 * @param string $language The article's language.
	 * @return array<int, array{code: string, name: string, id: int, status: string, editUrl: string, createUrl: string}> Empty on a site with one language.
	 */
	private static function translations( Plugin $plugin, int $post_id, string $language ): array {
		$codes = array_values( array_unique( array_map( 'strval', $plugin->languages()->languages() ) ) );

		if ( count( $codes ) < 2 ) {
			return array();
		}

		$versions = $plugin->make( Translations::class )->versions_of( $post_id, $language );
		$out      = array();

		foreach ( $codes as $code ) {
			if ( $code === $language ) {
				continue;
			}

			$id     = (int) ( $versions[ $code ] ?? 0 );
			$status = $id > 0 ? (string) get_post_status( $id ) : '';

			$out[] = array(
				'code'      => $code,
				'name'      => Catalog::name( $code ),
				'id'        => $id,
				'status'    => $status,
				'editUrl'   => $id > 0 && current_user_can( 'edit_post', $id ) ? (string) get_edit_post_link( $id, 'raw' ) : '',
				'createUrl' => 0 === $id ? self::create_url( $post_id, $code ) : '',
			);
		}

		return $out;
	}

	/**
	 * The address that creates a translation, or an empty string when the translation plugin gives none.
	 *
	 * @param int    $source_id Article to translate from.
	 * @param string $language  Language to create.
	 * @return string
	 */
	private static function create_url( int $source_id, string $language ): string {
		/** This filter is documented in Admin/Reports/TranslationsReport.php. */
		$given = apply_filters( 'estasoft_nd_internal_new_translation_url', '', $source_id, $language );

		if ( is_string( $given ) && '' !== $given ) {
			return $given;
		}

		if ( function_exists( 'PLL' ) ) {
			$polylang = PLL(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Polylang's own function.

			if ( is_object( $polylang ) && isset( $polylang->links ) && is_object( $polylang->links ) && method_exists( $polylang->links, 'get_new_post_translation_link' ) ) {
				$url = $polylang->links->get_new_post_translation_link( $source_id, $language );

				return is_string( $url ) ? $url : '';
			}
		}

		return '';
	}
}
