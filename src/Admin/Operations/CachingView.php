<?php
/**
 * Settings, Cache purge: the table of caching plugins and the test and log.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Operations;

use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Grid;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Core\CachePurge;
use Estasoft\NewsDistribution\Core\Operations\Links;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Prints what the Cache purge section shows besides its two forms of check boxes.
 * The test button is a submit button of the settings form that carries its own
 * action, so it works without JavaScript; `operations.js` sends it to the REST
 * API instead, so unsaved changes in the form are not lost.
 */
final class CachingView {

	private CachePurge $purge;

	/**
	 * Creates the view.
	 *
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->purge = $plugin->make( CachePurge::class );
	}

	/**
	 * The table of caching plugins.
	 *
	 * @return string Escaped HTML.
	 */
	public function plugins_table(): string {
		$rows = array();

		foreach ( $this->purge->adapters() as $adapter ) {
			$installed = $adapter->is_installed();
			$last      = $installed ? $this->purge->last_test( $adapter->id() ) : null;

			if ( ! $installed ) {
				$result = StatusView::pill( Status::OFF );
			} elseif ( null === $last ) {
				$result = Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Not tested yet', 'estasoft-news-distribution' ) );
			} elseif ( $last['ok'] ) {
				$result = StatusView::pill( Status::TEST_PASSED );
			} else {
				$result = StatusView::pill( Status::FAILED, __( 'Test failed', 'estasoft-news-distribution' ) );
			}

			$rows[] = array(
				Html::tag( 'strong', array(), esc_html( $adapter->label() ) ),
				StatusView::inline( $installed ? Status::INSTALLED : Status::NOT_INSTALLED ),
				esc_html( $installed ? $adapter->asks() : __( 'Nothing to ask', 'estasoft-news-distribution' ) ),
				$result,
			);
		}

		$developers = Html::tag( 'a', array( 'href' => esc_url( Links::settings( 'developers' ) ) ), esc_html__( 'Settings, Developers', 'estasoft-news-distribution' ) );

		return Grid::render(
			'estasoft-nd-cols-ops-plugins',
			array(
				__( 'Plugin', 'estasoft-news-distribution' ),
				__( 'State', 'estasoft-news-distribution' ),
				__( 'What it is asked', 'estasoft-news-distribution' ),
				__( 'Result', 'estasoft-news-distribution' ),
			),
			$rows,
			__( 'Caching plugins', 'estasoft-news-distribution' )
		) . Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-muted estasoft-nd-small estasoft-nd-ops-foot' ),
			sprintf(
				/* translators: %s: a link with the text "Settings, Developers". */
				esc_html__( 'A caching plugin that is not listed can still be reached. For developers: see %s.', 'estasoft-news-distribution' ),
				$developers
			)
		);
	}

	/**
	 * The test button, the sentence about the last purge, and the log.
	 *
	 * @return string Escaped HTML.
	 */
	public function test_and_log(): string {
		$button = Button::submit(
			__( 'Test purge now', 'estasoft-news-distribution' ),
			array(
				'attrs' => array(
					'name'          => 'action',
					'value'         => 'estasoft_nd_test_purge',
					'data-ops-test' => 'settings/caching/test-purge',
				),
			)
		);

		$top = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-12 estasoft-nd-ops-pad' ),
			$button . Forms::result( 'estasoft-nd-cache-result', $this->last_line() )
		);

		return $top . Html::tag( 'div', array( 'id' => 'estasoft-nd-cache-log' ), $this->log_table() );
	}

	/**
	 * The log as a table, or a line saying there is nothing yet.
	 *
	 * @return string Escaped HTML.
	 */
	public function log_table(): string {
		$log = $this->purge->log();

		if ( array() === $log ) {
			return Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-ops-pad' ), esc_html__( 'Nothing has been purged yet. A row appears here after an article goes live or really changes, after an image copy for a platform is made, replaced or removed, or after you press Test purge now.', 'estasoft-news-distribution' ) );
		}

		$rows = array();

		foreach ( $log as $row ) {
			$through = array();

			foreach ( (array) $row['through'] as $answer ) {
				$through[] = (string) $answer['label'];
			}

			if ( array() === $through ) {
				$through[] = __( 'The purge action only', 'estasoft-news-distribution' );
			}

			$what = Html::tag( 'div', array(), esc_html( CachePurge::describe( (array) $row['what'] ) ) );
			$line = $this->what_line( $row );

			if ( '' !== $line ) {
				$what .= Html::tag(
					'div',
					array(
						'class' => 'estasoft-nd-muted estasoft-nd-small',
						'dir'   => 'auto',
					),
					esc_html( $line )
				);
			}

			$rows[] = array(
				esc_html( Format::when( (int) $row['time'] ) ),
				$this->result_cell( $row ),
				$what,
				esc_html( implode( ', ', $through ) ),
			);
		}

		return Grid::render(
			'estasoft-nd-cols-ops-log',
			array(
				__( 'When', 'estasoft-news-distribution' ),
				__( 'Result', 'estasoft-news-distribution' ),
				__( 'What', 'estasoft-news-distribution' ),
				__( 'Through', 'estasoft-news-distribution' ),
			),
			$rows,
			__( 'Recent purges', 'estasoft-news-distribution' )
		);
	}

	/**
	 * The sentence beside the test button: what the last purge was and how it went.
	 *
	 * @return string Escaped HTML, with its status icon.
	 */
	public function last_line(): string {
		$log = $this->purge->log();

		if ( array() === $log ) {
			return Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'No purge yet. The test asks each caching plugin to drop one made-up address.', 'estasoft-news-distribution' ) );
		}

		$row   = $log[0];
		$count = (int) array_sum( array_map( 'intval', (array) $row['what'] ) );
		$when  = Format::when( (int) $row['time'] );
		$names = array();
		$bad   = array();

		foreach ( (array) $row['through'] as $answer ) {
			$names[] = (string) $answer['label'];

			if ( empty( $answer['ok'] ) ) {
				$bad[] = (string) $answer['label'] . ': ' . (string) $answer['message'];
			}
		}

		if ( array() === $names ) {
			$text = sprintf(
				/* translators: 1: when, such as "Today, 14:23", 2: number of addresses. */
				_n(
					'Last purge: %1$s. No caching plugin is installed, so only what listens to the purge action was told to drop %2$d address.',
					'Last purge: %1$s. No caching plugin is installed, so only what listens to the purge action was told to drop %2$d addresses.',
					$count,
					'estasoft-news-distribution'
				),
				$when,
				$count
			);

			if ( empty( $row['hook'] ) ) {
				$text = sprintf(
					/* translators: %s: when, such as "Today, 14:23". */
					__( 'Last purge: %s. No caching plugin is installed and nothing listens to the purge action, so nobody was asked.', 'estasoft-news-distribution' ),
					$when
				);
			}

			return StatusView::inline( Status::INFO, $text );
		}

		if ( array() !== $bad ) {
			return StatusView::inline(
				Status::FAILED,
				sprintf(
					/* translators: 1: when, such as "Today, 14:23", 2: what the caching plugin answered. */
					__( 'Last purge: %1$s. A caching plugin answered with an error. %2$s', 'estasoft-news-distribution' ),
					$when,
					implode( ' ', $bad )
				)
			);
		}

		return StatusView::inline(
			Status::SENT,
			sprintf(
				/* translators: 1: when, such as "Today, 14:23", 2: names of caching plugins, 3: number of addresses. */
				_n(
					'Last purge: %1$s. Asked %2$s to drop %3$d address. It answered without an error.',
					'Last purge: %1$s. Asked %2$s to drop %3$d addresses. It answered without an error.',
					$count,
					'estasoft-news-distribution'
				),
				$when,
				implode( ', ', $names ),
				$count
			)
		);
	}

	/**
	 * The second line of the "What" cell: "Published: Title".
	 *
	 * @param array<string, mixed> $row A log row.
	 * @return string Plain text.
	 */
	private function what_line( array $row ): string {
		$title = (string) ( $row['title'] ?? '' );

		switch ( (string) ( $row['kind'] ?? '' ) ) {
			case CachePurge::WHEN_PUBLISH:
				/* translators: %s: an article title. */
				return sprintf( __( 'Published: %s', 'estasoft-news-distribution' ), $title );

			case CachePurge::WHEN_CHANGE:
				/* translators: %s: an article title. */
				return sprintf( __( 'Changed: %s', 'estasoft-news-distribution' ), $title );

			case CachePurge::WHEN_REMOVE:
				/* translators: %s: an article title. */
				return sprintf( __( 'Removed: %s', 'estasoft-news-distribution' ), $title );

			case CachePurge::WHEN_IMAGE:
				/* translators: %s: file names of image copies, such as "photo-nd-smartnews.jpg". */
				return sprintf( __( 'Image copy: %s', 'estasoft-news-distribution' ), $title );

			default:
				return __( 'Test: one made-up address', 'estasoft-news-distribution' );
		}
	}

	/**
	 * The "Result" cell of a log row.
	 *
	 * @param array<string, mixed> $row A log row.
	 * @return string Escaped HTML.
	 */
	private function result_cell( array $row ): string {
		if ( array() === (array) $row['through'] ) {
			// No caching plugin answered: either something listens to the action, or nobody was asked.
			return empty( $row['hook'] )
				? StatusView::inline( Status::INFO, __( 'Nobody asked', 'estasoft-news-distribution' ) )
				: StatusView::inline( Status::SENT, __( 'Announced', 'estasoft-news-distribution' ) );
		}

		if ( ! empty( $row['ok'] ) ) {
			return StatusView::inline( Status::SENT );
		}

		$bad = array();

		foreach ( (array) $row['through'] as $answer ) {
			if ( empty( $answer['ok'] ) ) {
				$bad[] = sprintf(
					/* translators: 1: the name of a caching plugin, 2: what it answered. */
					__( '%1$s returned an error. %2$s', 'estasoft-news-distribution' ),
					(string) $answer['label'],
					(string) $answer['message']
				);
			}
		}

		return StatusView::inline(
			Status::FAILED,
			sprintf(
				/* translators: %s: what went wrong, such as "WP Rocket returned an error." */
				__( 'Failed: %s Use Test purge now to check it.', 'estasoft-news-distribution' ),
				implode( ' ', $bad )
			)
		);
	}
}
