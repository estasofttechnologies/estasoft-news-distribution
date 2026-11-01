<?php
/**
 * The News Distribution box on the WordPress dashboard.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Admin\Output;
use Estasoft\NewsDistribution\Admin\Overview\OverviewData;
use Estasoft\NewsDistribution\Admin\Overview\WpSurfaces;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Channels\Group;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Operations\Links;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * One line for each group of channels, with the same words the Overview uses, and a link to the Overview.
 *
 * The box is off until the person turns it on in Settings, General (`general.dashboard_box`), so the plugin adds
 * nothing to the Dashboard of a site that did not ask for it.
 *
 * It reads the Overview's own data (`Admin\Overview\OverviewData`), kept for five minutes in a transient,
 * so opening the dashboard costs one read of a stored copy and never a check or a request to another
 * server. A change to a channel, an application or a setting drops the copy.
 */
final class DashboardWidgetModule implements ModuleInterface {

	public const WIDGET = 'estasoft_nd_overview';

	private Plugin $plugin;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		add_action( 'wp_dashboard_setup', array( $this, 'add' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Whether the site turned the box on and the person may see the Overview.
	 *
	 * @return bool
	 */
	private function wanted(): bool {
		return (bool) $this->plugin->settings()->get( 'general.dashboard_box' ) && $this->plugin->access()->can( Access::VIEW );
	}

	/**
	 * Adds the box for a person who may see the Overview.
	 *
	 * @return void
	 */
	public function add(): void {
		if ( ! $this->wanted() ) {
			return;
		}

		wp_add_dashboard_widget( self::WIDGET, __( 'News Distribution', 'estasoft-news-distribution' ), array( $this, 'render' ) );
	}

	/**
	 * Loads the style on the dashboard.
	 *
	 * @param string $hook The screen's hook suffix.
	 * @return void
	 */
	public function enqueue( $hook ): void {
		if ( 'index.php' === $hook && $this->wanted() ) {
			WpSurfaces::enqueue();
		}
	}

	/**
	 * Prints the box.
	 *
	 * @return void
	 */
	public function render(): void {
		Output::show( $this->html( ( new OverviewData( $this->plugin ) )->cached() ) );
	}

	/**
	 * The box's markup.
	 *
	 * @param array<string, mixed> $data What `OverviewData` gives.
	 * @return string
	 */
	public function html( array $data ): string {
		$rows   = '';
		$groups = (array) $data['groups'];
		$count  = (int) $data['summary']['attention'];

		foreach ( $this->lines( $data ) as $line ) {
			$rows .= Html::tag(
				'li',
				array( 'class' => 'estasoft-nd-wp-widget__row' ),
				Html::tag( 'span', array( 'class' => 'estasoft-nd-wp-widget__name' ), esc_html( (string) $line['title'] ) )
				. Html::tag( 'span', array( 'class' => 'estasoft-nd-wp-widget__result' ), StatusView::inline( (string) $line['status'], (string) $line['text'] ) )
			);
		}

		$badge = $count > 0
			? StatusView::pill(
				Status::ATTENTION,
				sprintf(
					/* translators: %s: a number of things that need attention. */
					_n( '%s needs attention', '%s need attention', $count, 'estasoft-news-distribution' ),
					number_format_i18n( $count )
				)
			)
			: StatusView::pill( Status::HEALTHY, __( 'Nothing needs attention', 'estasoft-news-distribution' ) );

		return WpSurfaces::wrap(
			Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-wp-widget__head' ),
				$badge
				. Html::tag(
					'span',
					array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
					esc_html(
						sprintf(
							/* translators: %s: a number of channel groups. */
							_n( 'across %s channel group', 'across %s channel groups', count( $groups ), 'estasoft-news-distribution' ),
							number_format_i18n( count( $groups ) )
						)
					)
				)
			)
			. Html::tag( 'ul', array( 'class' => 'estasoft-nd-wp-widget__list' ), $rows )
			. Html::tag(
				'a',
				array(
					'class' => 'estasoft-nd-wp-widget__link',
					'href'  => Links::admin( 'estasoft-nd' ),
				),
				esc_html__( 'Open News Distribution', 'estasoft-news-distribution' )
			),
			'estasoft-nd-wp--widget'
		);
	}

	/**
	 * The group a thing that needs attention belongs to.
	 *
	 * @param array<string, mixed> $need One item of `needs`.
	 * @return string A group id.
	 */
	private function group_of( array $need ): string {
		$channel = '' === (string) $need['channel'] ? null : $this->plugin->registry()->get( (string) $need['channel'] );

		if ( null !== $channel ) {
			return $channel->group();
		}

		switch ( (string) $need['kind'] ) {
			case 'push':
				return Group::PUSHES;

			case 'plugin':
				return Group::SITEMAP;
		}

		// Skipped articles and reminders are about partner platforms.
		return Group::PARTNER;
	}

	/**
	 * One line for each group: its name, a status and a few words.
	 *
	 * @param array<string, mixed> $data What `OverviewData` gives.
	 * @return array<int, array{title: string, status: string, text: string}>
	 */
	private function lines( array $data ): array {
		$needs = array();

		foreach ( (array) $data['needs'] as $need ) {
			$needs[ $this->group_of( (array) $need ) ][] = (string) $need['title'];
		}

		$lines = array();

		foreach ( (array) $data['groups'] as $group ) {
			$id   = (string) $group['id'];
			$line = array(
				'title'  => (string) $group['title'],
				'status' => Status::NOT_SET_UP,
				'text'   => Status::word( Status::NOT_SET_UP ),
			);

			if ( isset( $needs[ $id ] ) ) {
				$line['status'] = Status::ATTENTION;
				$line['text']   = $this->attention_text( $needs[ $id ] );
			} elseif ( (int) $group['attention'] > 0 ) {
				// A channel says it needs attention but no item of "needs" is about it: name the channel.
				$line['status'] = Status::ATTENTION;
				$line['text']   = $this->attention_text( $this->attention_names( $group ) );
			} else {
				$line = array_merge( $line, $this->quiet_line( $group ) );
			}

			$lines[] = $line;
		}

		return $lines;
	}

	/**
	 * The names of the channels in a group that say they need attention.
	 *
	 * @param array<string, mixed> $group One item of `groups`.
	 * @return string[] At least one name.
	 */
	private function attention_names( array $group ): array {
		$names = array();

		foreach ( (array) $group['rows'] as $row ) {
			if ( Status::ATTENTION === (string) $row['status'] ) {
				$names[] = (string) $row['name'];
			}
		}

		return array() === $names ? array( (string) $group['title'] ) : $names;
	}

	/**
	 * "Needs attention: 3 articles are skipped for Microsoft Start", with a count when there are more.
	 *
	 * @param string[] $titles The titles of the things that need attention in a group.
	 * @return string
	 */
	private function attention_text( array $titles ): string {
		$more = count( $titles ) - 1;

		if ( $more < 1 ) {
			return sprintf(
				/* translators: 1: the words "Needs attention", 2: what needs attention, such as "3 articles are skipped for Microsoft Start". */
				__( '%1$s: %2$s', 'estasoft-news-distribution' ),
				Status::word( Status::ATTENTION ),
				$titles[0]
			);
		}

		return sprintf(
			/* translators: 1: the words "Needs attention", 2: what needs attention, 3: how many more things need attention. */
			_n( '%1$s: %2$s, and %3$d more', '%1$s: %2$s, and %3$d more', $more, 'estasoft-news-distribution' ),
			Status::word( Status::ATTENTION ),
			$titles[0],
			$more
		);
	}

	/**
	 * The line of a group with nothing that needs attention: what is waiting on someone else, or "Healthy".
	 *
	 * @param array<string, mixed> $group One item of `groups`.
	 * @return array{status: string, text: string}
	 */
	private function quiet_line( array $group ): array {
		$waiting = array();
		$status  = '';
		$healthy = false;

		foreach ( (array) $group['rows'] as $row ) {
			$code = (string) $row['status'];

			if ( in_array( $code, array( Status::REQUESTED, Status::IN_REVIEW, Status::PROCESSING ), true ) ) {
				$status    = '' === $status ? $code : $status;
				$waiting[] = (string) $row['name'];
			}

			if ( in_array( $code, array( Status::HEALTHY, Status::LISTED ), true ) ) {
				$healthy = true;
			}
		}

		if ( array() !== $waiting ) {
			return array(
				'status' => $status,
				'text'   => sprintf(
					/* translators: 1: a status such as Requested or In review, 2: platform names. */
					__( '%1$s: %2$s', 'estasoft-news-distribution' ),
					Status::word( $status ),
					wp_sprintf( '%l', $waiting )
				),
			);
		}

		if ( $healthy ) {
			return array(
				'status' => Status::HEALTHY,
				'text'   => Status::word( Status::HEALTHY ),
			);
		}

		return array(
			'status' => Status::NOT_SET_UP,
			'text'   => Status::word( Status::NOT_SET_UP ),
		);
	}
}
