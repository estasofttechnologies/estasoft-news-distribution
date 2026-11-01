<?php
/**
 * Settings, Cache purge.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Settings\Sections;

use Estasoft\NewsDistribution\Admin\Operations\CachingView;
use Estasoft\NewsDistribution\Admin\Operations\Forms;
use Estasoft\NewsDistribution\Core\CachePurge;
use Estasoft\NewsDistribution\Core\Settings\Section;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Which addresses are dropped from a caching plugin's stored copies, and after
 * which kinds of change. The work is `Core\CachePurge`; the caching plugins
 * are the adapters in `src/Integrations/CachePlugins/`.
 *
 * Stored as `estasoft_nd_settings.caching`: `purge_what` and `purge_when`, each
 * a list of the values named by `CachePurge::WHAT_*` and `CachePurge::WHEN_*`. The last `WHEN_` value, an image
 * copy made for a platform, is on by default like the others.
 */
final class Caching extends Section {

	public const NAME = 'caching';

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array {
		return array(
			'purge_what' => array(
				'type'      => 'list',
				'label'     => __( 'Addresses', 'estasoft-news-distribution' ),
				'default'   => array( CachePurge::WHAT_ARTICLE, CachePurge::WHAT_SITEMAPS, CachePurge::WHAT_FEEDS ),
				'control'   => 'checkboxes',
				'max_items' => 4,
				'item'      => array(
					'type'    => 'enum',
					'options' => array(
						CachePurge::WHAT_ARTICLE  => array(
							'label' => __( 'The article page', 'estasoft-news-distribution' ),
							'help'  => __( 'And its translations, when a translation plugin links them.', 'estasoft-news-distribution' ),
						),
						CachePurge::WHAT_SITEMAPS => array(
							'label' => __( 'The news and search sitemaps', 'estasoft-news-distribution' ),
							'help'  => '',
						),
						CachePurge::WHAT_FEEDS    => array(
							'label' => __( 'The feeds', 'estasoft-news-distribution' ),
							'help'  => __( 'One feed per language of the article and its translations.', 'estasoft-news-distribution' ),
						),
						CachePurge::WHAT_HOME     => array(
							'label' => __( 'The home page and the section pages', 'estasoft-news-distribution' ),
							'help'  => __( 'Off by default. These change on every article, and purging them often costs speed.', 'estasoft-news-distribution' ),
						),
					),
				),
			),
			'purge_when' => array(
				'type'      => 'list',
				'label'     => __( 'When', 'estasoft-news-distribution' ),
				'default'   => array( CachePurge::WHEN_PUBLISH, CachePurge::WHEN_CHANGE, CachePurge::WHEN_REMOVE, CachePurge::WHEN_IMAGE ),
				'control'   => 'checkboxes',
				'max_items' => 4,
				'item'      => array(
					'type'    => 'enum',
					'options' => array(
						CachePurge::WHEN_PUBLISH => array(
							'label' => __( 'An article is published', 'estasoft-news-distribution' ),
							'help'  => '',
						),
						CachePurge::WHEN_CHANGE  => array(
							'label' => __( 'An article really changes', 'estasoft-news-distribution' ),
							'help'  => __( 'As decided by the date guard, so a typo fix does not purge.', 'estasoft-news-distribution' ),
						),
						CachePurge::WHEN_REMOVE  => array(
							'label' => __( 'An article is unpublished or deleted', 'estasoft-news-distribution' ),
							'help'  => '',
						),
						CachePurge::WHEN_IMAGE   => array(
							'label' => __( 'An image copy is made, replaced or removed', 'estasoft-news-distribution' ),
							'help'  => __( 'The copy keeps its file name when it is made again, so a stored image would stay. The copy and the feeds that print it are dropped, once per request.', 'estasoft-news-distribution' ),
						),
					),
				),
			),
		);
	}

	public function title(): string {
		return __( 'Cache purge', 'estasoft-news-distribution' );
	}

	public function description(): string {
		return __( 'Ask your caching plugin to drop the stored copies of what changed, so readers and crawlers get the new page.', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return 'operations';
	}

	public function order(): int {
		return 20;
	}

	public function affects_output(): bool {
		return false;
	}

	/**
	 * @return array{cards: array<int, array<string, mixed>>, notes: array<int, array<string, string>>, cannot: string[]}
	 */
	public function layout(): array {
		$view = new CachingView( Plugin::instance() );

		return array(
			'cards'  => array(
				array(
					'id'          => 'plugins',
					'title'       => __( 'Caching plugins', 'estasoft-news-distribution' ),
					'description' => __( 'When an article goes live or really changes, or an image copy for a platform is made again, News Distribution asks the caching plugin to drop its stored copies, so readers and crawlers get the new page or picture.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array(
							'wide' => true,
							'html' => array( $view, 'plugins_table' ),
						),
					),
				),
				array(
					'id'          => 'what',
					'title'       => __( 'What is purged', 'estasoft-news-distribution' ),
					'description' => __( 'Only addresses that changed, never the whole cache.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array( 'field' => 'purge_what' ),
						array( 'field' => 'purge_when' ),
					),
				),
				array(
					'id'          => 'test',
					'title'       => __( 'Test and log', 'estasoft-news-distribution' ),
					'description' => __( 'A test asks each caching plugin to drop one made-up address, so no real page is touched, and shows what it answered.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array(
							'wide' => true,
							'html' => array( $view, 'test_and_log' ),
						),
					),
				),
			),
			'notes'  => array(
				Forms::cannot_note(
					array(
						__( 'Reach a cache at your host or a CDN. That cache sits outside WordPress. If you have one, keep the feed cache time short in Settings, General.', 'estasoft-news-distribution' ),
						__( 'Confirm the stored copy is gone. It asks, and records what the plugin answered.', 'estasoft-news-distribution' ),
						__( 'Undo a purge. Once the plugin drops a stored copy, the next visit rebuilds it.', 'estasoft-news-distribution' ),
					)
				),
				array(
					'title' => __( 'Not changed by this', 'estasoft-news-distribution' ),
					'text'  => __( 'The feed cache time in Settings, General is separate. It controls how long News Distribution reuses a feed it built itself.', 'estasoft-news-distribution' ),
				),
			),
			'cannot' => array(),
		);
	}
}
