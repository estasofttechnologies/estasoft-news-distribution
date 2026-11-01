<?php
/**
 * The "News Distribution" column in the Posts list.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Admin\Output;
use Estasoft\NewsDistribution\Admin\Overview\PostsColumnData;
use Estasoft\NewsDistribution\Admin\Overview\WpSurfaces;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Channels\Group;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Adds one column to the list of every post type News Distribution works on (Settings, General).
 * It shows four icons, in this order: Sitemap, Partner feeds, Open readers, Pushes. Each icon says what
 * happened to that article in that group of channels, with the icon and the word from `Core\Status`.
 * Under the icons one line says what needs a look. A draft shows how many things the article check
 * found. A key to the icons sits under the list.
 *
 * The column reads the delivery log once for the whole page (see `PostsColumnData`), so drawing a row
 * costs no query. It never runs a check and never calls out.
 */
final class PostsColumnModule implements ModuleInterface {

	public const COLUMN = 'estasoft_nd_distribution';

	private Plugin $plugin;

	private ?PostsColumnData $data = null;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		add_action( 'admin_init', array( $this, 'columns' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'manage_posts_extra_tablenav', array( $this, 'legend' ) );
	}

	/**
	 * Adds the column to each post type that is on.
	 *
	 * Done on `admin_init`, not on the Posts screen alone, so a row that is drawn again after a quick edit
	 * keeps its column.
	 *
	 * @return void
	 */
	public function columns(): void {
		foreach ( $this->plugin->settings()->post_types() as $type ) {
			if ( 'page' === $type ) {
				add_filter( 'manage_pages_columns', array( $this, 'add_column' ) );
				add_action( 'manage_pages_custom_column', array( $this, 'cell' ), 10, 2 );
				continue;
			}

			add_filter( 'manage_' . $type . '_posts_columns', array( $this, 'add_column' ) );
			add_action( 'manage_' . $type . '_posts_custom_column', array( $this, 'cell' ), 10, 2 );
		}
	}

	/**
	 * Whether this person sees the column.
	 *
	 * @return bool
	 */
	private function visible(): bool {
		return $this->plugin->access()->can( Access::VIEW_OWN );
	}

	/**
	 * Adds the column, and reads what the page's rows need in one go.
	 *
	 * The list asks for its columns once, after it has the page's articles. That is the moment to read the
	 * delivery log for all of them.
	 *
	 * @param mixed $columns Column key => heading.
	 * @return array<string, string>
	 */
	public function add_column( $columns ): array {
		$columns = is_array( $columns ) ? $columns : array();

		if ( ! $this->visible() ) {
			return $columns;
		}

		$columns[ self::COLUMN ] = __( 'News Distribution', 'estasoft-news-distribution' );

		$query = isset( $GLOBALS['wp_query'] ) && $GLOBALS['wp_query'] instanceof \WP_Query ? $GLOBALS['wp_query'] : null;

		if ( null !== $query && is_array( $query->posts ) ) {
			$this->data()->prime( $query->posts );
		}

		return $columns;
	}

	/**
	 * The batch reader.
	 *
	 * @return PostsColumnData
	 */
	private function data(): PostsColumnData {
		if ( null === $this->data ) {
			$this->data = new PostsColumnData( $this->plugin );
		}

		return $this->data;
	}

	/**
	 * Loads the style on the Posts list.
	 *
	 * @param string $hook The screen's hook suffix.
	 * @return void
	 */
	public function enqueue( $hook ): void {
		if ( 'edit.php' === $hook && $this->visible() ) {
			WpSurfaces::enqueue();
		}
	}

	/**
	 * Draws one cell.
	 *
	 * @param string $column  The column key.
	 * @param int    $post_id The article.
	 * @return void
	 */
	public function cell( $column, $post_id = 0 ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$post_id = (int) $post_id;

		if ( ! $this->plugin->access()->can( Access::VIEW_OWN, $post_id ) ) {
			return;
		}

		Output::show( $this->cell_html( $post_id ) );
	}

	/**
	 * One cell's markup.
	 *
	 * @param int $post_id The article.
	 * @return string
	 */
	public function cell_html( int $post_id ): string {
		$cell = $this->data()->get( $post_id );

		if ( null === $cell ) {
			return '';
		}

		$line = (array) $cell['line'];
		$text = StatusView::inline( (string) $line['status'], (string) $line['text'] );

		if ( empty( $cell['published'] ) ) {
			return WpSurfaces::wrap( Html::tag( 'div', array( 'class' => 'estasoft-nd-wp-col' ), $text ), 'estasoft-nd-wp--column' );
		}

		$icons = '';

		foreach ( (array) $cell['groups'] as $group ) {
			$info   = Status::describe( (string) $group['status'] );
			$icons .= Html::tag(
				'span',
				array(
					'class' => 'estasoft-nd-wp-col__icon estasoft-nd-status estasoft-nd-status--' . $info['tone'],
					'title' => (string) $group['title'],
				),
				Icon::svg( $info['icon'] )
				. Html::tag(
					'span',
					array( 'class' => 'screen-reader-text' ),
					esc_html(
						sprintf(
							/* translators: 1: a group of channels such as Sitemap, 2: a result such as In feed. */
							__( '%1$s: %2$s', 'estasoft-news-distribution' ),
							(string) $group['title'],
							$info['word']
						)
					)
				)
			);
		}

		return WpSurfaces::wrap(
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-wp-col' ),
				Html::tag( 'span', array( 'class' => 'estasoft-nd-wp-col__icons' ), $icons )
				. Html::tag( 'span', array( 'class' => 'estasoft-nd-wp-col__line' ), esc_html( (string) $line['text'] ) )
			),
			'estasoft-nd-wp--column'
		);
	}

	/**
	 * The key under the list: what the four icons are and what each can mean.
	 *
	 * @param mixed $which Top or bottom of the list.
	 * @return void
	 */
	public function legend( $which ): void {
		if ( 'bottom' !== $which || ! $this->visible() || ! $this->has_column() ) {
			return;
		}

		$groups = array();

		foreach ( PostsColumnData::groups() as $group ) {
			$groups[] = (string) Group::all()[ $group ]['title'];
		}

		Output::show(
			WpSurfaces::wrap(
				Html::tag(
					'p',
					array( 'class' => 'estasoft-nd-wp-legend estasoft-nd-small estasoft-nd-muted' ),
					Html::tag(
						'span',
						array(),
						esc_html(
							sprintf(
								/* translators: %s: four group names, such as "Sitemap, Partner feeds, Open readers, Pushes". */
								__( 'News Distribution column: four icons, in this order: %s. Each one shows what happened to that article:', 'estasoft-news-distribution' ),
								implode( ', ', $groups )
							)
						)
					)
					. StatusView::inline( Status::IN_FEED, __( 'In feed or sent', 'estasoft-news-distribution' ) )
					. StatusView::inline( Status::QUEUED, __( 'Waiting', 'estasoft-news-distribution' ) )
					. StatusView::inline( Status::RETRY, __( 'Retrying', 'estasoft-news-distribution' ) )
					. StatusView::inline( Status::FAILED, __( 'Skipped or failed', 'estasoft-news-distribution' ) )
				),
				'estasoft-nd-wp--legend'
			)
		);
	}

	/**
	 * Whether the list on screen has the column.
	 *
	 * @return bool
	 */
	private function has_column(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return null !== $screen && 'edit' === $screen->base && in_array( (string) $screen->post_type, $this->plugin->settings()->post_types(), true );
	}
}
