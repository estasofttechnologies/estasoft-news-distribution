<?php
/**
 * Settings, General.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Settings\Sections;

use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Core\Settings\Section;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Publication name, post types, timing and the log period.
 */
final class General extends Section {

	public const NAME = 'general';

	/**
	 * Post types a person may pick: every public type except attachments.
	 *
	 * @return array<string, string> Post type => plural name.
	 */
	public static function post_type_options(): array {
		$options = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $slug => $type ) {
			if ( 'attachment' === $slug ) {
				continue;
			}
			$options[ $slug ] = (string) $type->labels->name;
		}

		return $options;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array {
		return array(
			'view'              => array(
				'type'    => 'enum',
				'label'   => __( 'View', 'estasoft-news-distribution' ),
				'default' => 'simple',
				'storage' => 'user_meta:estasoft_nd_view',
				'control' => 'radio',
				'options' => array(
					'simple' => array(
						'label' => __( 'Simple', 'estasoft-news-distribution' ),
						'help'  => __( 'Overview, Channels and Article check. Reports, the delivery log and Settings sit under More. New sites start here.', 'estasoft-news-distribution' ),
					),
					'full'   => array(
						'label' => __( 'Full', 'estasoft-news-distribution' ),
						'help'  => __( 'Every screen in the app bar.', 'estasoft-news-distribution' ),
					),
				),
				'help'    => __( 'Hiding a screen never turns its feature off.', 'estasoft-news-distribution' ),
			),
			'dashboard_box'     => array(
				'type'    => 'bool',
				'label'   => __( 'Dashboard box', 'estasoft-news-distribution' ),
				'default' => false,
				'control' => 'checkbox',
				'title'   => __( 'Show a News Distribution box on the WordPress Dashboard', 'estasoft-news-distribution' ),
				'help'    => __( 'Off by default, so News Distribution adds nothing to the Dashboard unless you ask. The box is one short status list with a link to the Overview, and it can be hidden from Screen Options.', 'estasoft-news-distribution' ),
			),
			'publication_name'  => array(
				'type'    => 'string',
				'label'   => __( 'Publication name', 'estasoft-news-distribution' ),
				'default' => '',
				'max'     => 80,
				'width'   => 360,
				'help'    => __( 'Must match the name shown on your articles in Google News. Leave it empty to use the site title.', 'estasoft-news-distribution' ),
			),
			'names'             => array(
				'type'        => 'map',
				'label'       => __( 'Name per language', 'estasoft-news-distribution' ),
				'default'     => array(),
				'control'     => 'lang_map',
				'item'        => array(
					'type' => 'string',
					'max'  => 80,
				),
				'key_pattern' => '/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$/',
				'width'       => 280,
				'help'        => __( 'Use a different name for a language if Google News shows your publication under another name there.', 'estasoft-news-distribution' ),
			),
			'post_types'        => array(
				'type'      => 'list',
				'label'     => __( 'Post types', 'estasoft-news-distribution' ),
				'default'   => array( 'post' ),
				'control'   => 'checkboxes',
				'item'      => array(
					'type'    => 'enum',
					'options' => array( self::class, 'post_type_options' ),
				),
				'options'   => array( self::class, 'post_type_options' ),
				'max_items' => 50,
			),
			'index_first_delay' => array(
				'type'    => 'int',
				'label'   => __( 'Index-first delay', 'estasoft-news-distribution' ),
				'default' => 15,
				'min'     => 0,
				'max'     => 120,
				'unit'    => __( 'minutes', 'estasoft-news-distribution' ),
				'width'   => 90,
				'help'    => __( 'How long partner feeds and outlets wait after publishing, so your own site is crawled first. Each article can override this. Whole minutes, 0 to 120. 0 sends at once.', 'estasoft-news-distribution' ),
			),
			'cache_ttl_minutes' => array(
				'type'    => 'int',
				'label'   => __( 'Feed cache', 'estasoft-news-distribution' ),
				'default' => 5,
				'min'     => 1,
				'max'     => 60,
				'unit'    => __( 'minutes', 'estasoft-news-distribution' ),
				'width'   => 90,
				'help'    => __( 'How long a built feed is reused before it is built again. Feeds are also cleared whenever a post is published, updated or removed. Platforms such as Yahoo News fetch often, so keep this short. Whole minutes, 1 to 60.', 'estasoft-news-distribution' ),
			),
			'short_replies'     => array(
				'type'    => 'bool',
				'label'   => __( 'Short replies', 'estasoft-news-distribution' ),
				'default' => true,
				'control' => 'checkbox',
				'title'   => __( 'Reply “nothing new” when a feed has not changed', 'estasoft-news-distribution' ),
				'help'    => __( 'A platform that asks whether a feed changed since its last visit gets a short answer instead of the whole feed. This saves your server’s work. Technical name: conditional requests.', 'estasoft-news-distribution' ),
			),
			'log_days'          => array(
				'type'    => 'int',
				'label'   => __( 'Keep the logs', 'estasoft-news-distribution' ),
				'default' => 30,
				'min'     => 7,
				'max'     => 90,
				'unit'    => __( 'days', 'estasoft-news-distribution' ),
				'width'   => 90,
				'help'    => __( 'How long the delivery log and the fetch log are kept. Whole days, 7 to 90. Older entries are removed every day.', 'estasoft-news-distribution' ),
			),
		);
	}

	public function title(): string {
		return __( 'General', 'estasoft-news-distribution' );
	}

	public function description(): string {
		return __( 'These apply to every channel unless a channel says otherwise.', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return 'site';
	}

	public function order(): int {
		return 10;
	}

	/**
	 * @return array{cards: array<int, array<string, mixed>>, cannot: string[]}
	 */
	public function layout(): array {
		return array(
			'cards'  => array(
				array(
					'id'          => 'interface',
					'title'       => __( 'Interface', 'estasoft-news-distribution' ),
					'description' => __( 'How much of News Distribution you see.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array( 'field' => 'view' ),
						array( 'field' => 'dashboard_box' ),
					),
				),
				array(
					'id'          => 'publication',
					'title'       => __( 'Publication', 'estasoft-news-distribution' ),
					'description' => __( 'Who you are to the platforms that read your feeds.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array( 'field' => 'publication_name' ),
						array( 'field' => 'names' ),
						array( 'field' => 'post_types' ),
					),
				),
				array(
					'id'          => 'timing',
					'title'       => __( 'Timing and cache', 'estasoft-news-distribution' ),
					'description' => __( 'How soon articles leave your site, and how long feeds are reused.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array( 'field' => 'index_first_delay' ),
						array( 'field' => 'cache_ttl_minutes' ),
					),
				),
				array(
					'id'          => 'performance',
					'title'       => __( 'Feed performance', 'estasoft-news-distribution' ),
					'description' => __( 'Answer “has it changed?” with a short “nothing new”.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array( 'field' => 'short_replies' ),
						array(
							'label' => __( 'Last 7 days', 'estasoft-news-distribution' ),
							'html'  => array( $this, 'render_performance' ),
						),
					),
				),
				array(
					'id'    => 'data',
					'title' => __( 'Data', 'estasoft-news-distribution' ),
					'rows'  => array(
						array( 'field' => 'log_days' ),
						array(
							'label' => __( 'When the plugin is deleted', 'estasoft-news-distribution' ),
							'html'  => array( $this, 'render_uninstall_note' ),
						),
					),
				),
			),
			'cannot' => array(
				__( 'Make a platform fetch your feeds more often. Each platform decides that', 'estasoft-news-distribution' ),
				__( 'Change the name a platform shows for your publication on its own side', 'estasoft-news-distribution' ),
				__( 'Bring back data after it has been deleted', 'estasoft-news-distribution' ),
			),
		);
	}

	/**
	 * The "Last 7 days" row: how many feed requests were answered with a short reply.
	 *
	 * @return string Escaped HTML.
	 */
	public function render_performance(): string {
		$stats = Plugin::instance()->fetch_log()->performance( 7 );

		if ( $stats['requests'] < 1 ) {
			return Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'No feed requests have been counted yet.', 'estasoft-news-distribution' ) )
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'News Distribution cannot make a platform ask this way. It counts which ones do.', 'estasoft-news-distribution' ) );
		}

		$items = array(
			/* translators: %s: a percentage. */
			sprintf( esc_html__( 'Requests that asked whether the feed changed: %s', 'estasoft-news-distribution' ), '<strong>' . esc_html( number_format_i18n( $stats['asked_percent'] ) ) . '%</strong>' ),
			/* translators: %s: a percentage. */
			sprintf( esc_html__( 'Answered with the short reply: %s', 'estasoft-news-distribution' ), '<strong>' . esc_html( number_format_i18n( $stats['short_percent'] ) ) . '%</strong>' ),
			/* translators: %s: a percentage. */
			sprintf( esc_html__( 'Bandwidth saved: %s', 'estasoft-news-distribution' ), '<strong>' . esc_html( number_format_i18n( $stats['saved_percent'] ) ) . '%</strong>' ),
		);

		return Html::tag( 'ul', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-6' ), '<li>' . implode( '</li><li>', $items ) . '</li>' )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Counted from the fetch log. A request that a cache answers is not counted.', 'estasoft-news-distribution' ) );
	}

	/**
	 * The "When the plugin is deleted" row. The choice lives in Backup and uninstall.
	 *
	 * @return string Escaped HTML.
	 */
	public function render_uninstall_note(): string {
		$url = add_query_arg(
			array(
				'page'    => 'estasoft-nd-settings',
				'section' => 'data',
			),
			admin_url( 'admin.php' )
		);

		return Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-muted' ),
			sprintf(
				/* translators: %s: link to the Backup and uninstall page. */
				esc_html__( 'What stays and what goes is set in %s. Deactivating the plugin never deletes anything.', 'estasoft-news-distribution' ),
				'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Backup and uninstall', 'estasoft-news-distribution' ) . '</a>'
			)
		);
	}
}
