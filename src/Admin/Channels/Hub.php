<?php
/**
 * The Channels hub: every channel in five groups.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Channels;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Page;
use Estasoft\NewsDistribution\Admin\Partners\PartnersTab;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\CommandBar;
use Estasoft\NewsDistribution\Admin\Ui\EmptyState;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\PageHeader;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Admin\Ui\Tag;
use Estasoft\NewsDistribution\Channels\ChannelInterface;
use Estasoft\NewsDistribution\Channels\Group;
use Estasoft\NewsDistribution\Channels\Outlets\OutletsChannel;
use Estasoft\NewsDistribution\Channels\Outlets\OutletsSwitch;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Applications;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * `admin.php?page=estasoft-nd-channels`. The rows come from each channel's
 * `hub_rows()`, so a new channel appears here with no edit. Groups sit in the
 * fixed order of `Channels\Group`; a group that has nothing for the person to do
 * starts closed.
 *
 * Without a script the filter is a set of links, the search is a plain GET
 * form and each switch is a "Turn on" or "Turn off" button in its own form.
 * With the script a switch saves through `PUT /channels/{id}` and the search
 * filters the rows as the person types.
 */
final class Hub {

	public const FIELD    = 'estasoft_nd_hub';
	public const NONCE    = 'estasoft_nd_hub_switch';
	private const FILTERS = array( 'all', 'attention', 'not_set_up' );

	private Plugin $plugin;
	private Page $page;

	/**
	 * Creates the hub.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @param Page   $page   The Channels page, which knows its own address.
	 */
	public function __construct( Plugin $plugin, Page $page ) {
		$this->plugin = $plugin;
		$this->page   = $page;
	}

	/**
	 * Loads the hub's style and script.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		Assets::screen_style( 'hub' );
		Assets::screen_script(
			'hub',
			array(
				'text' => array(
					'showing' => $this->showing_template(),
					/* translators: %s: a channel name. */
					'on'      => __( '%s is on.', 'estasoft-news-distribution' ),
					/* translators: %s: a channel name. */
					'off'     => __( '%s is off.', 'estasoft-news-distribution' ),
					/* translators: %s: a channel name such as Outlets. */
					'own'     => __( '%s has its own switches. Open it to turn each part on or off.', 'estasoft-news-distribution' ),
					'failed'  => __( 'Nothing was changed. Open the channel to see what needs fixing.', 'estasoft-news-distribution' ),
					'offline' => __( 'Could not reach your site. Check your connection and try again.', 'estasoft-news-distribution' ),
				),
			)
		);
	}

	/**
	 * Handles a switch pressed without the script: a plain post to this page.
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( ! isset( $_POST[ self::FIELD ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only asks whether the form was posted; the nonce is checked next, before its value is read.
			return;
		}

		check_admin_referer( self::NONCE );

		if ( 'switch' !== sanitize_key( wp_unslash( $_POST[ self::FIELD ] ) ) ) {
			return;
		}

		$this->plugin->access()->require_or_die( Access::MANAGE );

		$id   = isset( $_POST['channel'] ) ? sanitize_key( wp_unslash( $_POST['channel'] ) ) : '';
		$turn = isset( $_POST['turn'] ) && 'on' === sanitize_key( wp_unslash( $_POST['turn'] ) ) ? 'on' : 'off';
		$back = array();

		foreach ( array( 'filter', 'q' ) as $key ) {
			$value = isset( $_POST[ $key ] ) ? ( 'q' === $key ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : sanitize_key( wp_unslash( $_POST[ $key ] ) ) ) : '';

			if ( '' !== $value ) {
				$back[ $key ] = $value;
			}
		}

		$channel = $this->plugin->registry()->get( $id );

		if ( null === $channel ) {
			$back['problem'] = 'unknown';
		} else {
			$problem = $this->switch_channel( $channel, 'on' === $turn );

			if ( null === $problem ) {
				$back['switched'] = $id;
				$back['to']       = $turn;
			} else {
				// The channel goes by "switched": a "channel" address would open that channel's own page.
				$back['problem']  = $problem;
				$back['switched'] = $id;
			}
		}

		wp_safe_redirect( $this->page->url( $back ) );
		exit;
	}

	/**
	 * Switches a channel and checks that it took.
	 *
	 * Outlets has no flag of its own: its switch turns its outlets off and on, the
	 * same way `PUT /channels/outlets` does. Any other channel whose "on" follows
	 * its own parts ignores the switch; that is undone and reported, so the screen
	 * never shows a state the channel does not have.
	 *
	 * @param ChannelInterface $channel The channel.
	 * @param bool             $on      The wanted state.
	 * @return string|null Null when it worked, else a short code: `own_switches`, `set_up_first` or `rejected`.
	 */
	public function switch_channel( ChannelInterface $channel, bool $on ): ?string {
		if ( $channel instanceof OutletsChannel ) {
			$switch = $this->plugin->make( OutletsSwitch::class );
			$done   = $switch->check( $channel, $on );

			if ( null === $done ) {
				$done = $switch->apply( $channel, $on );
			}

			if ( ! is_wp_error( $done ) ) {
				return null;
			}

			return OutletsSwitch::SET_UP_FIRST === $done->get_error_code() ? 'set_up_first' : 'rejected';
		}

		$settings = $this->plugin->channel_settings();
		$before   = $settings->is_enabled( $channel->id() );
		$saved    = $settings->save( $channel->id(), $channel->fields(), array( 'enabled' => $on ) );

		if ( is_wp_error( $saved ) ) {
			return 'rejected';
		}

		if ( $channel->is_enabled() !== $on ) {
			$settings->set_enabled( $channel->id(), $before );

			return 'own_switches';
		}

		return null;
	}

	/**
	 * Prints the hub.
	 *
	 * @return string Escaped HTML.
	 */
	public function render(): string {
		$rows    = $this->rows();
		$counts  = $this->counts( $rows );
		$filter  = $this->filter();
		$query   = $this->query();
		$shown   = $this->narrow( $rows, $filter, $query );
		$manage  = $this->plugin->access()->can( Access::MANAGE );
		$actions = '';

		if ( $manage && null !== $this->plugin->registry()->get( 'verification' ) ) {
			$actions .= Button::link( __( 'Site verification', 'estasoft-news-distribution' ), $this->page->url( array( 'channel' => 'verification' ) ) );
		}

		$actions .= Button::link( __( 'Applications', 'estasoft-news-distribution' ), $this->page->url( array( 'tab' => 'applications' ) ) );
		$actions .= PartnersTab::header_link( $this->plugin, $this->page );

		return PageHeader::render(
			__( 'Channels', 'estasoft-news-distribution' ),
			__( 'Grouped by how each one connects: what you do, and what News Distribution does.', 'estasoft-news-distribution' ),
			$actions
		)
			. $this->notice()
			. $this->command_bar( $counts, $filter, $query, count( $shown ), count( $rows ) )
			. Html::tag(
				'p',
				array(
					'class'           => 'estasoft-nd-sr',
					'data-hub-status' => true,
					'role'            => 'status',
				),
				''
			)
			. $this->groups( $shown, '' !== $query || 'all' !== $filter, $manage )
			. Html::tag(
				'div',
				array(),
				Card::note(
					'hub-cannot',
					__( 'What it cannot do', 'estasoft-news-distribution' ),
					Html::tag(
						'p',
						array( 'class' => 'estasoft-nd-muted' ),
						esc_html__( 'It cannot see a platform’s decision. Applications and listings are set by hand, and a platform decides whether and when it shows your articles. A channel shows Healthy only after the platform has fetched your feed or sitemap, and platforms that are In review show no visits.', 'estasoft-news-distribution' )
					)
				)
			);
	}

	/**
	 * Every row of every channel, in hub order.
	 *
	 * Each row has the keys of `ChannelInterface::hub_rows()` plus `channel` (the
	 * channel that owns it), `group` and `switchable` (whether the row has a switch
	 * of its own). A row of an open application whose reminder date has passed shows
	 * Needs attention.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function rows(): array {
		$channels = array_values( $this->plugin->registry()->all() );

		usort(
			$channels,
			static function ( ChannelInterface $a, ChannelInterface $b ): int {
				return $a->order() <=> $b->order();
			}
		);

		$order = array_flip( array_keys( Group::all() ) );
		$apps  = $this->plugin->applications();
		$rows  = array();

		foreach ( $channels as $channel ) {
			foreach ( $channel->hub_rows() as $row ) {
				$key     = (string) $row['key'];
				$status  = (string) $row['status'];
				$summary = (string) $row['summary'];

				if ( Status::ATTENTION !== $status && $apps->is_overdue( $key ) ) {
					$status  = Status::ATTENTION;
					$summary = trim(
						$summary . ' ' . sprintf(
							/* translators: %s: a short date such as "28 Oct". */
							__( 'Your reminder date, %s, has passed. Check on your application.', 'estasoft-news-distribution' ),
							Applications::short_day( $apps->get( $key )['reminder_on'] )
						)
					);
				}

				$enabled = isset( $row['enabled'] ) ? (bool) $row['enabled'] : null;
				$rows[]  = array(
					'key'        => $key,
					'name'       => (string) $row['name'],
					'summary'    => $summary,
					'status'     => $status,
					'enabled'    => $enabled,
					'action'     => 'set_up' === $row['action'] ? 'set_up' : 'open',
					'channel'    => $channel->id(),
					'group'      => $channel->group(),
					'group_rank' => $order[ $channel->group() ] ?? count( $order ),
					'switchable' => null !== $enabled && $key === $channel->id(),
				);
			}
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return $a['group_rank'] <=> $b['group_rank'];
			}
		);

		return $rows;
	}

	/**
	 * The numbers on the filter buttons.
	 *
	 * "Not set up" also counts platforms the person has not applied to yet.
	 *
	 * @param array<int, array<string, mixed>> $rows All rows.
	 * @return array{all: int, attention: int, not_set_up: int}
	 */
	private function counts( array $rows ): array {
		$counts = array(
			'all'        => count( $rows ),
			'attention'  => 0,
			'not_set_up' => 0,
		);

		foreach ( $rows as $row ) {
			foreach ( array( 'attention', 'not_set_up' ) as $filter ) {
				if ( $this->matches_filter( $row, $filter ) ) {
					++$counts[ $filter ];
				}
			}
		}

		return $counts;
	}

	/**
	 * Whether a row belongs to a filter.
	 *
	 * @param array<string, mixed> $row    A row.
	 * @param string               $filter all, attention or not_set_up.
	 * @return bool
	 */
	private function matches_filter( array $row, string $filter ): bool {
		switch ( $filter ) {
			case 'attention':
				return Status::ATTENTION === $row['status'];
			case 'not_set_up':
				return in_array( $row['status'], array( Status::NOT_SET_UP, Status::NOT_REQUESTED ), true );
		}

		return true;
	}

	/**
	 * The chosen filter.
	 *
	 * @return string
	 */
	private function filter(): string {
		$filter = Query::key( 'filter', 'all' );

		return in_array( $filter, self::FILTERS, true ) ? $filter : 'all';
	}

	/**
	 * The search text.
	 *
	 * @return string
	 */
	private function query(): string {
		return trim( Query::text( 'q' ) );
	}

	/**
	 * Rows that pass the filter and the search.
	 *
	 * @param array<int, array<string, mixed>> $rows   All rows.
	 * @param string                           $filter The filter.
	 * @param string                           $query  The search text.
	 * @return array<int, array<string, mixed>>
	 */
	private function narrow( array $rows, string $filter, string $query ): array {
		return array_values(
			array_filter(
				$rows,
				function ( array $row ) use ( $filter, $query ): bool {
					return $this->matches_filter( $row, $filter ) && ( '' === $query || false !== mb_stripos( $this->haystack( $row ), $query ) );
				}
			)
		);
	}

	/**
	 * The text a search looks in: the name, the summary and the status word.
	 *
	 * @param array<string, mixed> $row A row.
	 * @return string
	 */
	private function haystack( array $row ): string {
		return $row['name'] . ' ' . $row['summary'] . ' ' . Status::word( (string) $row['status'] );
	}

	/**
	 * The message after a switch was pressed.
	 *
	 * @return string
	 */
	private function notice(): string {
		$id      = Query::key( 'switched' );
		$problem = Query::key( 'problem' );
		$to      = Query::key( 'to' );

		$channel = '' === $id ? null : $this->plugin->registry()->get( $id );

		if ( '' !== $problem ) {
			if ( 'own_switches' === $problem && null !== $channel ) {
				return Message::text(
					'warning',
					sprintf(
						/* translators: %s: a channel name such as Outlets. */
						__( '%s has its own switches. Open it to turn each part on or off.', 'estasoft-news-distribution' ),
						$channel->label()
					)
				);
			}

			if ( 'set_up_first' === $problem ) {
				return Message::text( 'warning', OutletsSwitch::set_up_first_text() );
			}

			return Message::text( 'danger', __( 'Nothing was changed. Open the channel to see what needs fixing.', 'estasoft-news-distribution' ) );
		}

		if ( null === $channel ) {
			return '';
		}

		return Message::text(
			'success',
			sprintf(
				/* translators: %s: a channel name such as Google News. */
				'on' === $to ? __( '%s is on.', 'estasoft-news-distribution' ) : __( '%s is off.', 'estasoft-news-distribution' ),
				$channel->label()
			)
		);
	}

	/**
	 * Search, the filter and the line that says how many channels are shown.
	 *
	 * @param array{all: int, attention: int, not_set_up: int} $counts Numbers for the buttons.
	 * @param string                                           $filter The chosen filter.
	 * @param string                                           $query  The search text.
	 * @param int                                              $shown  Rows shown.
	 * @param int                                              $total  All rows.
	 * @return string
	 */
	private function command_bar( array $counts, string $filter, string $query, int $shown, int $total ): string {
		$segments = array(
			'all'        => array(
				/* translators: %s: number of channels. */
				'label' => sprintf( __( 'All (%s)', 'estasoft-news-distribution' ), number_format_i18n( $counts['all'] ) ),
				'url'   => $this->page->url( '' !== $query ? array( 'q' => $query ) : array() ),
			),
			'attention'  => array(
				/* translators: %s: number of channels that need attention. */
				'label' => sprintf( __( 'Needs attention (%s)', 'estasoft-news-distribution' ), number_format_i18n( $counts['attention'] ) ),
				'url'   => $this->page->url( array_merge( array( 'filter' => 'attention' ), '' !== $query ? array( 'q' => $query ) : array() ) ),
			),
			'not_set_up' => array(
				/* translators: %s: number of channels that are not set up. */
				'label' => sprintf( __( 'Not set up (%s)', 'estasoft-news-distribution' ), number_format_i18n( $counts['not_set_up'] ) ),
				'url'   => $this->page->url( array_merge( array( 'filter' => 'not_set_up' ), '' !== $query ? array( 'q' => $query ) : array() ) ),
			),
		);

		$search = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-hub-search' ),
			Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-sr',
					'for'   => 'estasoft-nd-hub-q',
				),
				esc_html__( 'Search channels', 'estasoft-news-distribution' )
			)
			. Html::void(
				'input',
				array(
					'class'           => 'estasoft-nd-field estasoft-nd-w-full',
					'type'            => 'search',
					'id'              => 'estasoft-nd-hub-q',
					'name'            => 'q',
					'value'           => $query,
					'placeholder'     => __( 'Search channels', 'estasoft-news-distribution' ),
					'data-hub-search' => true,
					'autocomplete'    => 'off',
				)
			)
			. Button::submit( __( 'Search', 'estasoft-news-distribution' ), array( 'class' => 'estasoft-nd-hub-go' ) )
		);

		$note = Html::tag(
			'p',
			array(
				'class'          => 'estasoft-nd-muted estasoft-nd-small estasoft-nd-hub-note',
				'data-hub-note'  => true,
				'data-hub-total' => $total,
			),
			esc_html( $this->showing( $shown, $total ) )
		);

		$keep = 'all' !== $filter ? array( 'filter' => $filter ) : array();

		return CommandBar::render(
			$this->page->slug(),
			$search . Html::tag( 'div', array( 'class' => 'estasoft-nd-hub-filter' ), CommandBar::segments( $segments, $filter, __( 'Show', 'estasoft-news-distribution' ) ) ) . $note,
			'',
			$keep
		);
	}

	/**
	 * The text of the "Showing N of M channels" line with its numbers filled in.
	 *
	 * @param int $shown Rows shown.
	 * @param int $total All rows.
	 * @return string
	 */
	private function showing( int $shown, int $total ): string {
		return sprintf( $this->showing_template(), number_format_i18n( $shown ), number_format_i18n( $total ) );
	}

	/**
	 * The line with its two placeholders, which the script fills in as the search narrows the list.
	 *
	 * @return string
	 */
	private function showing_template(): string {
		/* translators: 1: number of channels shown, 2: number of channels in all. */
		return __( 'Showing %1$s of %2$s channels. Not set up also counts platforms you have not applied to yet.', 'estasoft-news-distribution' );
	}

	/**
	 * The groups in two columns, in the fixed order.
	 *
	 * @param array<int, array<string, mixed>> $rows     Rows to show.
	 * @param bool                             $narrowed Whether a filter or a search is on, which opens every group that has a match.
	 * @param bool                             $manage   Whether the person may change channels.
	 * @return string
	 */
	private function groups( array $rows, bool $narrowed, bool $manage ): string {
		if ( array() === $rows ) {
			return Html::tag(
				'div',
				array( 'data-hub-body' => true ),
				EmptyState::render(
					__( 'No channels match', 'estasoft-news-distribution' ),
					__( 'Try another word, or show every channel.', 'estasoft-news-distribution' ),
					Button::link( __( 'Show every channel', 'estasoft-news-distribution' ), $this->page->url(), array( 'variant' => 'primary' ) )
				)
			);
		}

		$by_group = array();

		foreach ( $rows as $row ) {
			$by_group[ (string) $row['group'] ][] = $row;
		}

		$known   = Group::all();
		$columns = array(
			0 => '',
			1 => '',
		);
		$index   = 0;

		foreach ( $known as $id => $info ) {
			if ( isset( $by_group[ $id ] ) ) {
				$columns[ $index < 2 ? 0 : 1 ] .= $this->group( $id, $info['title'], $info['help'], $by_group[ $id ], $narrowed, $manage );
			}

			++$index;
		}

		foreach ( $by_group as $id => $list ) {
			if ( ! isset( $known[ $id ] ) ) {
				$columns[1] .= $this->group( (string) $id, ucfirst( str_replace( array( '-', '_' ), ' ', (string) $id ) ), '', $list, $narrowed, $manage );
			}
		}

		return Html::tag(
			'div',
			array(
				'class'         => 'estasoft-nd-hub-columns',
				'data-hub-body' => true,
			),
			Html::tag( 'div', array( 'class' => 'estasoft-nd-hub-column' ), $columns[0] )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-hub-column' ), $columns[1] )
		);
	}

	/**
	 * One group: a heading with its count, then its rows. It starts closed when nothing in it needs the person.
	 *
	 * @param string                           $id       Group id.
	 * @param string                           $title    Heading.
	 * @param string                           $help     One line under the heading.
	 * @param array<int, array<string, mixed>> $rows     The group's rows.
	 * @param bool                             $narrowed Whether a filter or a search is on.
	 * @param bool                             $manage   Whether the person may change channels.
	 * @return string
	 */
	private function group( string $id, string $title, string $help, array $rows, bool $narrowed, bool $manage ): string {
		$quiet = true;
		$list  = '';

		foreach ( $rows as $row ) {
			if ( ! in_array( $row['status'], array( Status::HEALTHY, Status::LISTED ), true ) ) {
				$quiet = false;
			}

			$list .= $this->row( $row, $manage );
		}

		$open    = $narrowed || ! $quiet;
		$heading = Html::tag(
			'h2',
			array(
				'class' => 'estasoft-nd-title',
				'id'    => 'estasoft-nd-hub-group-' . sanitize_key( $id ),
			),
			esc_html( $title )
		);

		if ( '' !== $help ) {
			$heading .= Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( $help ) );
		}

		$end = Html::tag(
			'span',
			array( 'class' => 'estasoft-nd-hub-group__end' ),
			Html::tag(
				'span',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small estasoft-nd-hub-group__quiet' ),
				esc_html__( 'Nothing needs you', 'estasoft-news-distribution' )
			)
			. Tag::count( count( $rows ) )
			. Icon::svg( 'chevron-down', 'estasoft-nd-hub-group__chevron' )
		);

		return Html::tag(
			'section',
			array(
				'class'           => 'estasoft-nd-card estasoft-nd-card--flush estasoft-nd-hub-group',
				'aria-labelledby' => 'estasoft-nd-hub-group-' . sanitize_key( $id ),
				'data-hub-group'  => $id,
			),
			Html::tag(
				'details',
				array(
					'open'       => $open,
					'data-quiet' => $quiet ? 'true' : 'false',
				),
				Html::tag( 'summary', array( 'class' => 'estasoft-nd-hub-group__summary' ), Html::tag( 'div', array(), $heading ) . $end ) . $list
			)
		);
	}

	/**
	 * One row: the switch, the name and summary, the status and the way in.
	 *
	 * @param array<string, mixed> $row    The row.
	 * @param bool                 $manage Whether the person may change channels.
	 * @return string
	 */
	private function row( array $row, bool $manage ): string {
		$name   = (string) $row['name'];
		$action = '';

		if ( $manage ) {
			$set_up = 'set_up' === $row['action'];
			$action = Html::tag(
				'a',
				array(
					'class'      => 'estasoft-nd-btn estasoft-nd-btn--sm',
					'href'       => $this->page->url( array( 'channel' => (string) $row['channel'] ) ),
					'aria-label' => sprintf(
						/* translators: %s: a channel or platform name. */
						$set_up ? __( 'Set up %s', 'estasoft-news-distribution' ) : __( 'Open %s', 'estasoft-news-distribution' ),
						$name
					),
				),
				$set_up ? esc_html__( 'Set up', 'estasoft-news-distribution' ) : esc_html__( 'Open', 'estasoft-news-distribution' )
			);
		}

		return Html::tag(
			'div',
			array(
				'class'            => 'estasoft-nd-group-row estasoft-nd-hub-row',
				'data-hub-row'     => (string) $row['key'],
				'data-hub-channel' => (string) $row['channel'],
				'data-search'      => mb_strtolower( $this->haystack( $row ) ),
			),
			$this->switch_cell( $row, $manage )
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-group-row__name' ),
				Html::tag( 'span', array( 'class' => 'estasoft-nd-strong' ), esc_html( $name ) )
				. ( '' !== $row['summary'] ? Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( (string) $row['summary'] ) ) : '' )
			)
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-hub-row__end' ), StatusView::pill( (string) $row['status'] ) . $action )
		);
	}

	/**
	 * The cell before the name. A row with a switch of its own gets the switch; the others keep the space so names line up.
	 *
	 * @param array<string, mixed> $row    The row.
	 * @param bool                 $manage Whether the person may change channels.
	 * @return string
	 */
	private function switch_cell( array $row, bool $manage ): string {
		if ( ! $row['switchable'] ) {
			return Html::tag(
				'span',
				array(
					'class'       => 'estasoft-nd-hub-switch',
					'aria-hidden' => 'true',
				),
				''
			);
		}

		$name = (string) $row['name'];
		$on   = (bool) $row['enabled'];

		/* translators: %s: a channel name. The switch is on. */
		$label_on = sprintf( __( '%s on', 'estasoft-news-distribution' ), $name );
		/* translators: %s: a channel name. The switch is off. */
		$label_off = sprintf( __( '%s off', 'estasoft-news-distribution' ), $name );

		$toggle = Html::void(
			'input',
			array(
				'class'           => Html::classes( 'estasoft-nd-toggle', 'estasoft-nd-hub-toggle', $manage ? '' : 'estasoft-nd-hub-toggle--static' ),
				'type'            => 'checkbox',
				'checked'         => $on,
				'disabled'        => true,
				'aria-label'      => $on ? $label_on : $label_off,
				'data-hub-toggle' => $manage ? (string) $row['channel'] : null,
				'data-name'       => $manage ? $name : null,
			)
		);

		if ( ! $manage ) {
			return Html::tag( 'span', array( 'class' => 'estasoft-nd-hub-switch' ), $toggle );
		}

		$back   = '';
		$filter = $this->filter();

		if ( 'all' !== $filter ) {
			$back .= $this->hidden( 'filter', $filter );
		}

		if ( '' !== $this->query() ) {
			$back .= $this->hidden( 'q', $this->query() );
		}

		$apply = Html::tag(
			'button',
			array(
				'class'      => 'estasoft-nd-btn estasoft-nd-btn--sm estasoft-nd-hub-apply',
				'type'       => 'submit',
				'aria-label' => sprintf(
					/* translators: %s: a channel name. */
					$on ? __( 'Turn off %s', 'estasoft-news-distribution' ) : __( 'Turn on %s', 'estasoft-news-distribution' ),
					$name
				),
			),
			$on ? esc_html__( 'Turn off', 'estasoft-news-distribution' ) : esc_html__( 'Turn on', 'estasoft-news-distribution' )
		);

		return Html::tag(
			'form',
			array(
				'class'  => 'estasoft-nd-hub-switch',
				'method' => 'post',
				'action' => $this->page->url(),
			),
			$this->hidden( self::FIELD, 'switch' )
			. $this->hidden( 'channel', (string) $row['channel'] )
			. $this->hidden( 'turn', $on ? 'off' : 'on' )
			. $this->hidden( '_wpnonce', wp_create_nonce( self::NONCE ) )
			. $back
			. $toggle
			. $apply
		);
	}

	/**
	 * A hidden input.
	 *
	 * @param string $name  Field name.
	 * @param string $value Field value.
	 * @return string
	 */
	private function hidden( string $name, string $value ): string {
		return Html::void(
			'input',
			array(
				'type'  => 'hidden',
				'name'  => $name,
				'value' => $value,
			)
		);
	}
}
