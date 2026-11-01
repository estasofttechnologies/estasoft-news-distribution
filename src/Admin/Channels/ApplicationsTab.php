<?php
/**
 * The Applications tab of the Channels page: where each application stands.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Channels;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Page;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\Code;
use Estasoft\NewsDistribution\Admin\Ui\Field;
use Estasoft\NewsDistribution\Admin\Ui\FieldRows;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\PageHeader;
use Estasoft\NewsDistribution\Admin\Ui\SaveBar;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Admin\Ui\Table;
use Estasoft\NewsDistribution\Channels\AppleNews\FeedDocument;
use Estasoft\NewsDistribution\Channels\SmartNews\ReportLinks;
use Estasoft\NewsDistribution\Channels\SmartNews\SmartNewsChannel;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Applications;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Language\Catalog;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * `admin.php?page=estasoft-nd-channels&tab=applications`. A record the person
 * keeps by hand: News Distribution cannot see any platform's answer, so every
 * status, date and note here was typed in, and nothing is sent anywhere. A
 * reminder date that has passed turns an open application to Needs attention.
 *
 * Everything works without a script: choosing a platform is a link, the forms
 * post to this page, and "Add a platform" and "Remove" are pages of their own.
 */
final class ApplicationsTab {

	public const FIELD = 'estasoft_nd_apps';
	public const NONCE = 'estasoft_nd_apps';

	/**
	 * Days a "Remind me to check" choice counts from today.
	 */
	private const REMINDERS = array( '7', '14', '30' );

	private Plugin $plugin;
	private Page $page;

	/**
	 * Messages for the fields of a form that was rejected, by field name.
	 *
	 * @var array<string, string>
	 */
	private array $errors = array();

	/**
	 * What a rejected form had in its boxes.
	 *
	 * @var array<string, string>
	 */
	private array $typed = array();

	/**
	 * Which form was rejected: a platform id, or "add".
	 *
	 * @var string
	 */
	private string $rejected = '';

	/**
	 * Creates the tab.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @param Page   $page   The Channels page, which knows its own address.
	 */
	public function __construct( Plugin $plugin, Page $page ) {
		$this->plugin = $plugin;
		$this->page   = $page;
	}

	/**
	 * Loads the tab's style and the scripts for the copy button and the save bar.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		Assets::screen_style( 'applications' );
		Assets::screen_style( 'address-row' );
		Assets::screen_script( 'address-row' );
		Assets::screen_script( 'dirty-form' );
	}

	/**
	 * The address of this tab.
	 *
	 * @param array<string, scalar> $args Extra query arguments.
	 * @return string
	 */
	public function url( array $args = array() ): string {
		return $this->page->url( array_merge( array( 'tab' => 'applications' ), $args ) );
	}

	/**
	 * Handles a form posted to this tab: save a platform, add one or remove one.
	 *
	 * A rejected form is not redirected: it is shown again with what was typed and
	 * the reason beside the field.
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( ! isset( $_POST[ self::FIELD ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce is checked on the next line.
			return;
		}

		check_admin_referer( self::NONCE );
		$this->plugin->access()->require_or_die( Access::MANAGE );

		$action   = sanitize_key( wp_unslash( $_POST[ self::FIELD ] ) );
		$platform = isset( $_POST['platform'] ) ? sanitize_key( wp_unslash( $_POST['platform'] ) ) : '';
		$input    = isset( $_POST[ Field::INPUT ] ) && is_array( $_POST[ Field::INPUT ] )
			? map_deep( wp_unslash( $_POST[ Field::INPUT ] ), 'sanitize_textarea_field' )
			: array();

		if ( 'add' === $action ) {
			$added = $this->add( $input );

			if ( is_array( $added ) ) {
				$this->errors   = $added;
				$this->typed    = $this->strings( $input );
				$this->rejected = 'add';

				return;
			}

			wp_safe_redirect( $this->url( array( 'platform' => $added ) ) . '&added=1' );
			exit;
		}

		if ( ! isset( Applications::platforms()[ $platform ] ) ) {
			wp_safe_redirect( $this->url( array( 'problem' => 'unknown' ) ) );
			exit;
		}

		if ( 'remove' === $action ) {
			$this->plugin->applications()->remove( $platform );

			wp_safe_redirect( $this->url( array( 'removed' => 1 ) ) );
			exit;
		}

		$errors = $this->save( $platform, $input );

		if ( array() !== $errors ) {
			$this->errors   = $errors;
			$this->typed    = $this->strings( $input );
			$this->rejected = $platform;

			return;
		}

		wp_safe_redirect( $this->url( array( 'platform' => $platform ) ) . '&saved=1' );
		exit;
	}

	/**
	 * Saves one platform from the form's values.
	 *
	 * `remind` is a choice counted from today: keep, 7, 14, 30 or never. Nothing is
	 * saved unless every value is good.
	 *
	 * @param string               $platform Platform id.
	 * @param array<string, mixed> $input    Form values: status, applied_on, contact, remind, note.
	 * @return array<string, string> Messages by field; empty when it was saved.
	 */
	public function save( string $platform, array $input ): array {
		$errors = array();
		$data   = array();

		if ( array_key_exists( 'status', $input ) ) {
			$data['status'] = sanitize_key( (string) $input['status'] );
		}

		if ( array_key_exists( 'applied_on', $input ) ) {
			$day = Applications::parse_date( (string) $input['applied_on'] );

			if ( null === $day ) {
				$errors['applied_on'] = __( 'Write the date as day, month and year, for example 5 Oct 2026.', 'estasoft-news-distribution' );
			} else {
				$data['applied_on'] = $day;
			}
		}

		foreach ( array( 'contact', 'note' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$data[ $key ] = (string) $input[ $key ];
			}
		}

		$remind = isset( $input['remind'] ) ? sanitize_key( (string) $input['remind'] ) : 'keep';

		if ( 'never' === $remind ) {
			$data['reminder_on'] = '';
		} elseif ( in_array( $remind, self::REMINDERS, true ) ) {
			$data['reminder_on'] = $this->day_after( (int) $remind );
		}

		if ( array() !== $errors ) {
			return $errors;
		}

		$saved = $this->plugin->applications()->save( $platform, $data );

		if ( is_wp_error( $saved ) ) {
			$fields = (array) ( $saved->get_error_data()['fields'] ?? array() );

			return array() === $fields ? array( 'status' => $saved->get_error_message() ) : array_map( 'strval', $fields );
		}

		return array();
	}

	/**
	 * Adds a platform from the "Add a platform" form.
	 *
	 * @param array<string, mixed> $input Form values: name, link.
	 * @return string|array<string, string> The new platform's id, or messages by field.
	 */
	public function add( array $input ) {
		$added = $this->plugin->applications()->add( (string) ( $input['name'] ?? '' ), (string) ( $input['link'] ?? '' ) );

		if ( is_wp_error( $added ) ) {
			$fields = (array) ( $added->get_error_data()['fields'] ?? array() );

			return array() === $fields ? array( 'name' => $added->get_error_message() ) : array_map( 'strval', $fields );
		}

		return (string) $added['platform'];
	}

	/**
	 * Prints the tab.
	 *
	 * @return string Escaped HTML.
	 */
	public function render(): string {
		$manage    = $this->plugin->access()->can( Access::MANAGE );
		$platforms = Applications::platforms();
		$selected  = $this->selected( $platforms );
		$adding    = $manage && ( 'add' === $this->rejected || $this->asked( 'add' ) );
		$removing  = $manage && Applications::is_custom( $this->asked_value( 'confirm_remove' ) ) ? $this->asked_value( 'confirm_remove' ) : '';

		$crumbs = Html::tag(
			'nav',
			array(
				'class'      => 'estasoft-nd-breadcrumb estasoft-nd-small',
				'aria-label' => __( 'Breadcrumb', 'estasoft-news-distribution' ),
			),
			Html::tag( 'a', array( 'href' => $this->page->url() ), esc_html__( 'Channels', 'estasoft-news-distribution' ) )
			. Html::tag( 'span', array( 'aria-hidden' => 'true' ), ' / ' )
			. Html::tag( 'span', array( 'aria-current' => 'page' ), esc_html__( 'Applications', 'estasoft-news-distribution' ) )
		);

		$add = $manage && ! $adding
			? Button::link( __( 'Add a platform', 'estasoft-news-distribution' ), $this->url( array( 'add' => 1 ) ) . '#estasoft-nd-app-add' )
			: '';

		$html = $crumbs
			. PageHeader::render(
				__( 'Applications', 'estasoft-news-distribution' ),
				__( 'Where you are with each platform that decides for itself.', 'estasoft-news-distribution' ),
				$add
			)
			. $this->notice()
			. $this->info( $manage );

		if ( $adding ) {
			$html .= $this->add_card();
		}

		if ( '' !== $removing ) {
			$html .= $this->remove_card( $removing );
		}

		$html .= $this->table( $platforms, $selected );

		$cannot = Html::tag(
			'div',
			array(),
			Card::note(
				'applications-cannot',
				__( 'What it cannot do', 'estasoft-news-distribution' ),
				Html::tag(
					'p',
					array( 'class' => 'estasoft-nd-muted' ),
					esc_html__( 'It cannot send an application or read a platform’s answer. “Open in mail” starts a draft in your email app, and you send it. Every status, date and reminder is set by you.', 'estasoft-news-distribution' )
				)
			)
		);

		$detail = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-apps-detail' ),
			( $manage ? $this->detail_form( $selected ) : $this->detail_read( $selected ) ) . $this->email_card( $selected )
		);

		if ( ! $manage ) {
			return $html . $detail . $cannot;
		}

		return $html . Html::tag(
			'form',
			array(
				'class'           => 'estasoft-nd-apps-form',
				'method'          => 'post',
				'action'          => $this->url( array( 'platform' => $selected ) ),
				'data-dirty-form' => true,
			),
			$this->hidden( self::FIELD, 'save' )
			. $this->hidden( 'platform', $selected )
			. $this->hidden( '_wpnonce', wp_create_nonce( self::NONCE ) )
			. $detail
			. $cannot
			. SaveBar::render()
		);
	}

	/**
	 * The platform whose details are open: the one in the address, the one a rejected form was for, or the first that is waiting on an answer.
	 *
	 * @param array<string, string> $platforms Platform id => name.
	 * @return string
	 */
	private function selected( array $platforms ): string {
		if ( '' !== $this->rejected && isset( $platforms[ $this->rejected ] ) ) {
			return $this->rejected;
		}

		$wanted = $this->asked_value( 'platform' );

		if ( isset( $platforms[ $wanted ] ) ) {
			return $wanted;
		}

		$apps = $this->plugin->applications();

		foreach ( array_keys( $platforms ) as $id ) {
			if ( $apps->is_overdue( (string) $id ) ) {
				return (string) $id;
			}
		}

		foreach ( array_keys( $platforms ) as $id ) {
			if ( in_array( $apps->get( (string) $id )['status'], array( Status::REQUESTED, Status::IN_REVIEW ), true ) ) {
				return (string) $id;
			}
		}

		return (string) key( $platforms );
	}

	/**
	 * A value from the address, as a key.
	 *
	 * @param string $name Query argument.
	 * @return string
	 */
	private function asked_value( string $name ): string {
		return Query::key( $name );
	}

	/**
	 * Whether the address carries a flag.
	 *
	 * @param string $name Query argument.
	 * @return bool
	 */
	private function asked( string $name ): bool {
		return Query::has( $name );
	}

	/**
	 * The message after something was saved, added or removed, or a reason it was not.
	 *
	 * @return string
	 */
	private function notice(): string {
		if ( array() !== $this->errors ) {
			return Message::text( 'danger', __( 'Nothing was saved. Fix the field marked below and save again.', 'estasoft-news-distribution' ) );
		}

		if ( 'unknown' === $this->asked_value( 'problem' ) ) {
			return Message::text( 'danger', __( 'That platform is not in the tracker.', 'estasoft-news-distribution' ) );
		}

		$name = Applications::name( $this->asked_value( 'platform' ) );

		if ( $this->asked( 'saved' ) ) {
			return Message::text(
				'success',
				sprintf(
					/* translators: %s: a platform name such as SmartNews. */
					__( '%s is saved.', 'estasoft-news-distribution' ),
					$name
				)
			);
		}

		if ( $this->asked( 'added' ) ) {
			return Message::text(
				'success',
				sprintf(
					/* translators: %s: a platform name. */
					__( '%s is in the tracker. Its status starts at Not requested.', 'estasoft-news-distribution' ),
					$name
				)
			);
		}

		if ( $this->asked( 'removed' ) ) {
			return Message::text( 'success', __( 'The platform was removed from the tracker.', 'estasoft-news-distribution' ) );
		}

		return '';
	}

	/**
	 * The line under the heading that says what this record is.
	 *
	 * @param bool $manage Whether the person may change it.
	 * @return string
	 */
	private function info( bool $manage ): string {
		$text = __( 'News Distribution cannot see the answer a platform gives you, so each status is set by hand. Reminders are a nudge, not a check. “Add a platform” tracks one that is not in the list: give it a name and a link, and its status starts at Not requested.', 'estasoft-news-distribution' );

		if ( ! $manage ) {
			$text = __( 'News Distribution cannot see the answer a platform gives you, so each status is set by hand. You can read them here. Changing them needs permission to change channels and settings.', 'estasoft-news-distribution' );
		}

		return Message::text( 'info', $text );
	}

	/**
	 * The list of platforms with the hand-set status, the dates and the next step.
	 *
	 * @param array<string, string> $platforms Platform id => name.
	 * @param string                $selected  The open platform.
	 * @return string
	 */
	private function table( array $platforms, string $selected ): string {
		$apps = $this->plugin->applications();
		$rows = array();

		foreach ( $platforms as $id => $name ) {
			$id     = (string) $id;
			$row    = $apps->get( $id );
			$open   = $id === $selected;
			$day    = Applications::short_day( $row['applied_on'] );
			$where  = '' !== $row['contact'] ? $row['contact'] : $this->default_where( $id );
			$rows[] = array(
				Html::tag(
					'a',
					array(
						'class'        => 'estasoft-nd-strong',
						'href'         => $this->url( array( 'platform' => $id ) ) . '#estasoft-nd-app-detail',
						'aria-current' => $open ? 'true' : null,
					),
					esc_html( (string) $name )
				),
				StatusView::pill( $apps->display_status( $id ) ),
				'' === $day ? Html::tag( 'span', array( 'aria-label' => __( 'No date', 'estasoft-news-distribution' ) ), '—' ) : esc_html( $day ),
				Html::tag( 'span', array( 'class' => 'estasoft-nd-small' ), $this->where_html( $where ) ),
				Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html( $this->next_step( $id, $row, $apps->is_overdue( $id ) ) ) ),
			);
		}

		return Table::render(
			'estasoft-nd-cols-apps',
			array(
				__( 'Platform', 'estasoft-news-distribution' ),
				__( 'Status', 'estasoft-news-distribution' ),
				__( 'Applied on', 'estasoft-news-distribution' ),
				__( 'Where to apply', 'estasoft-news-distribution' ),
				__( 'Next step', 'estasoft-news-distribution' ),
			),
			$rows,
			__( 'Applications', 'estasoft-news-distribution' )
		);
	}

	/**
	 * Where to apply when the person has not written it in. Each is the route the
	 * design review confirmed for that platform; Yahoo's program name was not
	 * confirmed, so it says only to ask the contact.
	 *
	 * @param string $platform Platform id.
	 * @return string Empty when the platform was added by hand.
	 */
	private function default_where( string $platform ): string {
		switch ( $platform ) {
			case 'microsoft-start':
				return __( 'Microsoft Partner Hub', 'estasoft-news-distribution' );
			case 'yahoo-news':
				return __( 'Your Yahoo contact', 'estasoft-news-distribution' );
			case 'apple-news':
				return __( 'News Publisher', 'estasoft-news-distribution' );
			case 'smartnews':
				return __( 'SmartNews Partner Program', 'estasoft-news-distribution' );
			case 'news-break':
				return __( 'News Break publisher page (Apply to be a Partner)', 'estasoft-news-distribution' );
			case 'ground-news':
				return __( 'News Source Submission Form, or feedback@ground.news', 'estasoft-news-distribution' );
			case 'particle':
				return __( 'Through its website (partnerships)', 'estasoft-news-distribution' );
		}

		return '';
	}

	/**
	 * The "Where to apply" text: a link when it is an address, plain text otherwise.
	 *
	 * @param string $where What the person wrote, or the default.
	 * @return string
	 */
	private function where_html( string $where ): string {
		if ( '' === $where ) {
			return '—';
		}

		if ( 1 === preg_match( '#^https?://#i', $where ) && '' !== esc_url( $where ) ) {
			return Html::tag(
				'a',
				array(
					'href'   => $where,
					'target' => '_blank',
					'rel'    => 'noopener noreferrer',
				),
				esc_html( $where )
			);
		}

		return esc_html( $where );
	}

	/**
	 * What the person can do next, from what they recorded. It never says anything about the platform's side.
	 *
	 * @param string                $platform Platform id.
	 * @param array<string, string> $row      The record.
	 * @param bool                  $overdue  Whether the reminder date has passed.
	 * @return string
	 */
	private function next_step( string $platform, array $row, bool $overdue ): string {
		switch ( $row['status'] ) {
			case Status::LISTED:
				return __( 'Nothing to do', 'estasoft-news-distribution' );
			case Status::DECLINED:
				return __( 'Declined. Apply again when you are ready.', 'estasoft-news-distribution' );
			case Status::REQUESTED:
			case Status::IN_REVIEW:
				$day  = Applications::short_day( $row['reminder_on'] );
				$tail = 'ground-news' === $platform ? ' ' . __( 'No timeframe given.', 'estasoft-news-distribution' ) : '';

				if ( '' === $day ) {
					return __( 'No reminder set.', 'estasoft-news-distribution' ) . $tail;
				}

				if ( $overdue ) {
					return sprintf(
						/* translators: %s: a short date such as "28 Oct". */
						__( 'Your reminder date, %s, has passed. Check on it.', 'estasoft-news-distribution' ),
						$day
					) . $tail;
				}

				return sprintf(
					/* translators: %s: a short date such as "28 Oct". */
					__( 'Reminder %s.', 'estasoft-news-distribution' ),
					$day
				) . $tail;
		}

		return 'particle' === $platform ? __( 'Reach out when ready.', 'estasoft-news-distribution' ) : __( 'Apply when ready.', 'estasoft-news-distribution' );
	}

	/**
	 * The form for one platform.
	 *
	 * @param string $platform Platform id.
	 * @return string
	 */
	private function detail_form( string $platform ): string {
		$record = $this->plugin->applications()->get( $platform );
		$values = array(
			'status'     => $record['status'],
			'applied_on' => Applications::show_date( $record['applied_on'] ),
			'contact'    => $record['contact'],
			'remind'     => '' === $record['reminder_on'] ? 'never' : 'keep',
			'note'       => $record['note'],
		);

		$errors = array();

		if ( $this->rejected === $platform ) {
			$values = array_merge( $values, $this->typed );
			$errors = $this->errors;
		}

		$rows  = new FieldRows( 'apps-' . $platform, $this->fields( $platform, $record ), $values, $errors );
		$inner = Html::tag(
			'h2',
			array(
				'class' => 'estasoft-nd-title',
				'id'    => 'estasoft-nd-app-detail-title',
			),
			esc_html( Applications::name( $platform ) )
		);

		foreach ( array( 'status', 'applied_on', 'contact', 'remind', 'note' ) as $field ) {
			$inner .= $rows->row( array( 'field' => $field ) );
		}

		if ( Applications::is_custom( $platform ) ) {
			$inner .= Html::tag(
				'p',
				array(),
				Html::tag(
					'a',
					array(
						'class' => 'estasoft-nd-btn estasoft-nd-btn--sm',
						'href'  => $this->url( array( 'confirm_remove' => $platform ) ) . '#estasoft-nd-app-remove',
					),
					esc_html__( 'Remove from the tracker', 'estasoft-news-distribution' )
				)
			);
		}

		return $this->detail_card( $inner );
	}

	/**
	 * The details of one platform for a person who may not change them.
	 *
	 * @param string $platform Platform id.
	 * @return string
	 */
	private function detail_read( string $platform ): string {
		$apps   = $this->plugin->applications();
		$record = $apps->get( $platform );
		$day    = Applications::show_date( $record['applied_on'] );
		$next   = Applications::short_day( $record['reminder_on'] );
		$none   = Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Not set', 'estasoft-news-distribution' ) );
		$rows   = array(
			__( 'Status', 'estasoft-news-distribution' ) => StatusView::pill( $apps->display_status( $platform ) ),
			__( 'Applied on', 'estasoft-news-distribution' ) => '' === $day ? $none : esc_html( $day ),
			__( 'Where to apply', 'estasoft-news-distribution' ) => '' === $record['contact'] ? $none : $this->where_html( $record['contact'] ),
			__( 'Next reminder', 'estasoft-news-distribution' ) => '' === $next ? $none : esc_html( $next ),
			__( 'Notes', 'estasoft-news-distribution' )  => '' === $record['note'] ? $none : esc_html( $record['note'] ),
		);

		$inner = Html::tag(
			'h2',
			array(
				'class' => 'estasoft-nd-title',
				'id'    => 'estasoft-nd-app-detail-title',
			),
			esc_html( Applications::name( $platform ) )
		);

		foreach ( $rows as $label => $value ) {
			$inner .= Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-form-row' ),
				Html::tag( 'div', array( 'class' => 'estasoft-nd-form-label' ), esc_html( (string) $label ) )
				. Html::tag( 'div', array( 'class' => 'estasoft-nd-form-control apps-value' ), $value )
			);
		}

		return $this->detail_card( $inner );
	}

	/**
	 * The card around the details of a platform.
	 *
	 * @param string $inner Trusted HTML.
	 * @return string
	 */
	private function detail_card( string $inner ): string {
		return Html::tag(
			'section',
			array(
				'class'           => 'estasoft-nd-card estasoft-nd-card--note estasoft-nd-apps-card',
				'id'              => 'estasoft-nd-app-detail',
				'aria-labelledby' => 'estasoft-nd-app-detail-title',
			),
			$inner
		);
	}

	/**
	 * The field specs of the form for a platform.
	 *
	 * @param string                $platform Platform id.
	 * @param array<string, string> $record   The record.
	 * @return array<string, array<string, mixed>>
	 */
	private function fields( string $platform, array $record ): array {
		$statuses = array();

		foreach ( Applications::statuses() as $code ) {
			$statuses[ $code ] = Status::word( $code );
		}

		$remind = array();

		if ( '' !== $record['reminder_on'] ) {
			$remind['keep'] = sprintf(
				/* translators: %s: a short date such as "4 Nov". */
				__( 'Keep %s', 'estasoft-news-distribution' ),
				Applications::short_day( $record['reminder_on'] )
			);
		}

		foreach ( self::REMINDERS as $days ) {
			$remind[ $days ] = sprintf(
				/* translators: %s: a number of days. */
				_n( 'In %s day', 'In %s days', (int) $days, 'estasoft-news-distribution' ),
				number_format_i18n( (int) $days )
			);
		}

		$remind['never'] = __( 'Never', 'estasoft-news-distribution' );

		$help = __( 'A reminder shows as Needs attention here, on Channels and on the Overview once its date has passed. It is a nudge, not a check.', 'estasoft-news-distribution' );

		if ( '' !== $record['reminder_on'] ) {
			$help = sprintf(
				/* translators: 1: a short date such as "4 Nov", 2: the rest of the sentence about reminders. */
				__( 'Next reminder: %1$s. %2$s', 'estasoft-news-distribution' ),
				Applications::short_day( $record['reminder_on'] ),
				$help
			);
		}

		$where = $this->default_where( $platform );

		return array(
			'status'     => array(
				'type'    => 'enum',
				'label'   => __( 'Status', 'estasoft-news-distribution' ),
				'options' => $statuses,
				'width'   => 200,
				'help'    => sprintf(
					/* translators: %s: a platform name. */
					__( 'Set by hand. %s decides, and News Distribution cannot see its answer.', 'estasoft-news-distribution' ),
					Applications::name( $platform )
				),
			),
			'applied_on' => array(
				'type'  => 'string',
				'label' => __( 'Applied on', 'estasoft-news-distribution' ),
				'width' => 200,
				'max'   => 20,
				'help'  => __( 'Day, month and year, for example 5 Oct 2026.', 'estasoft-news-distribution' ),
			),
			'contact'    => array(
				'type'        => 'string',
				'label'       => __( 'Where to apply', 'estasoft-news-distribution' ),
				'width'       => 360,
				'max'         => 200,
				'placeholder' => $where,
				'help'        => __( 'A name, an email address or a link.', 'estasoft-news-distribution' ),
			),
			'remind'     => array(
				'type'    => 'enum',
				'label'   => __( 'Remind me to check', 'estasoft-news-distribution' ),
				'options' => $remind,
				'width'   => 200,
				'help'    => $help,
			),
			'note'       => array(
				'type'   => 'string',
				'format' => 'textarea',
				'label'  => __( 'Notes', 'estasoft-news-distribution' ),
				'rows'   => 3,
				'max'    => 1000,
				'width'  => 480,
			),
		);
	}

	/**
	 * The "Add a platform" form.
	 *
	 * @return string
	 */
	private function add_card(): string {
		$fields = array(
			'name' => array(
				'type'  => 'string',
				'label' => __( 'Platform name', 'estasoft-news-distribution' ),
				'width' => 260,
				'max'   => 60,
			),
			'link' => array(
				'type'  => 'string',
				'label' => __( 'Where to apply', 'estasoft-news-distribution' ),
				'width' => 360,
				'max'   => 200,
				'help'  => __( 'A name, an email address or a link. You can leave it empty and add it later.', 'estasoft-news-distribution' ),
			),
		);

		$rows  = new FieldRows( 'apps-add', $fields, 'add' === $this->rejected ? $this->typed : array(), 'add' === $this->rejected ? $this->errors : array() );
		$inner = Html::tag(
			'h2',
			array(
				'class' => 'estasoft-nd-title',
				'id'    => 'estasoft-nd-app-add-title',
			),
			esc_html__( 'Add a platform', 'estasoft-news-distribution' )
		)
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'For a platform that is not in the list. It has no feed options here: this only keeps your record. Its status starts at Not requested.', 'estasoft-news-distribution' ) )
			. $rows->row( array( 'field' => 'name' ) )
			. $rows->row( array( 'field' => 'link' ) )
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
				Button::submit( __( 'Add platform', 'estasoft-news-distribution' ), array( 'variant' => 'primary' ) )
				. Button::link( __( 'Cancel', 'estasoft-news-distribution' ), $this->url() )
			);

		return Html::tag(
			'form',
			array(
				'method' => 'post',
				'action' => $this->url( array( 'add' => 1 ) ),
			),
			$this->hidden( self::FIELD, 'add' )
			. $this->hidden( '_wpnonce', wp_create_nonce( self::NONCE ) )
			. Html::tag(
				'section',
				array(
					'class'           => 'estasoft-nd-card estasoft-nd-card--note',
					'id'              => 'estasoft-nd-app-add',
					'aria-labelledby' => 'estasoft-nd-app-add-title',
				),
				$inner
			)
		);
	}

	/**
	 * The page that asks before a platform that was added by hand is taken out.
	 *
	 * @param string $platform Platform id.
	 * @return string
	 */
	private function remove_card( string $platform ): string {
		$name = Applications::name( $platform );

		return Html::tag(
			'form',
			array(
				'method' => 'post',
				'action' => $this->url(),
			),
			$this->hidden( self::FIELD, 'remove' )
			. $this->hidden( 'platform', $platform )
			. $this->hidden( '_wpnonce', wp_create_nonce( self::NONCE ) )
			. Html::tag(
				'section',
				array(
					'class'           => 'estasoft-nd-card estasoft-nd-card--note',
					'id'              => 'estasoft-nd-app-remove',
					'aria-labelledby' => 'estasoft-nd-app-remove-title',
				),
				Html::tag(
					'h2',
					array(
						'class' => 'estasoft-nd-title',
						'id'    => 'estasoft-nd-app-remove-title',
					),
					esc_html(
						sprintf(
							/* translators: %s: a platform name. */
							__( 'Remove %s?', 'estasoft-news-distribution' ),
							$name
						)
					)
				)
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Its status, dates and notes are deleted. Nothing is sent to the platform. You can add it again later.', 'estasoft-news-distribution' ) )
				. Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
					Button::submit( __( 'Remove', 'estasoft-news-distribution' ), array( 'variant' => 'primary' ) )
					. Button::link( __( 'Keep it', 'estasoft-news-distribution' ), $this->url( array( 'platform' => $platform ) ) )
				)
			)
		);
	}

	/**
	 * The email for SmartNews's partner program, filled in with the feed addresses
	 * and what the plugin's own check found. It is shown only for SmartNews, the
	 * one platform whose application takes the feed addresses.
	 *
	 * @param string $platform The open platform.
	 * @return string Empty for any other platform.
	 */
	private function email_card( string $platform ): string {
		$channel = $this->plugin->registry()->get( 'smartnews' );

		if ( 'smartnews' !== $platform || ! $channel instanceof SmartNewsChannel ) {
			return '';
		}

		$text = $this->email_text( $channel );
		$to   = $this->plugin->applications()->get( 'smartnews' )['contact'];

		$mail = 'mailto:' . ( is_email( $to ) ? $to : '' ) . '?subject=' . rawurlencode(
			sprintf(
				/* translators: %s: the site name. */
				__( 'SmartNews partner program: %s', 'estasoft-news-distribution' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
			)
		) . '&body=' . rawurlencode( str_replace( "\n", "\r\n", $text ) );

		return Html::tag(
			'section',
			array(
				'class'           => 'estasoft-nd-card estasoft-nd-card--note estasoft-nd-apps-card estasoft-nd-apps-email',
				'aria-labelledby' => 'estasoft-nd-app-email-title',
			),
			Html::tag(
				'h2',
				array(
					'class' => 'estasoft-nd-title',
					'id'    => 'estasoft-nd-app-email-title',
				),
				esc_html__( 'Application email', 'estasoft-news-distribution' )
			)
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Filled in with your feed addresses and what News Distribution’s own SmartFormat check found. Review it, then copy it into your mail program. News Distribution sends nothing.', 'estasoft-news-distribution' ) )
			. Code::block( $text, __( 'Application email', 'estasoft-news-distribution' ) )
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
				Button::button(
					__( 'Copy email', 'estasoft-news-distribution' ),
					array(
						'class' => 'estasoft-nd-apps-copy',
						'attrs' => array(
							'data-copy'         => $text,
							'data-copied-label' => __( 'Copied', 'estasoft-news-distribution' ),
						),
					)
				)
				. Button::link( __( 'Open in mail', 'estasoft-news-distribution' ), $mail )
			)
		);
	}

	/**
	 * The words of the SmartNews email.
	 *
	 * @param SmartNewsChannel $channel The SmartNews channel.
	 * @return string Plain text.
	 */
	private function email_text( SmartNewsChannel $channel ): string {
		$site     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$names    = array();
		$feeds    = array();
		$counts   = array();
		$problems = array();
		$live     = 0;

		foreach ( $this->plugin->languages()->languages() as $language ) {
			$language = (string) $language;

			if ( ! $channel->accepts_language( $language ) ) {
				continue;
			}

			$name    = Catalog::name( $language );
			$entry   = FeedDocument::entry( $this->plugin, $channel, $language );
			$check   = $channel->inspect( (string) ( $entry['body'] ?? '' ) );
			$names[] = $name;
			$feeds[] = $name . ': ' . $channel->feed_url( $language );
			$live   += (int) $check['live'];

			$counts[] = $name . ' ' . number_format_i18n( (int) $check['live'] );

			foreach ( (array) $check['problems'] as $problem ) {
				$problems[ (string) $problem ] = (string) $problem;
			}
		}

		$parts   = array();
		$parts[] = __( 'Hello,', 'estasoft-news-distribution' );
		$parts[] = sprintf(
			/* translators: 1: the site name, 2: the languages, such as "English, Kurdish (Kurmanji) and Arabic". */
			__( 'We publish %1$s in %2$s, and we would like to join the SmartNews partner program.', 'estasoft-news-distribution' ),
			$site,
			wp_sprintf_l( '%l', $names )
		);

		if ( array() !== $feeds ) {
			$parts[] = __( 'Our SmartFormat feeds:', 'estasoft-news-distribution' ) . "\n" . implode( "\n", $feeds );

			$total = sprintf(
				/* translators: %s: a number of items. */
				_n( '%s item', '%s items', $live, 'estasoft-news-distribution' ),
				number_format_i18n( $live )
			);

			if ( array() !== $problems ) {
				$parts[] = sprintf(
					/* translators: %s: the problems the check found, separated by semicolons. */
					__( 'News Distribution’s own SmartFormat check found problems that we still need to fix: %s.', 'estasoft-news-distribution' ),
					implode( '; ', $problems )
				);
			} else {
				$parts[] = sprintf(
					/* translators: 1: the number of feeds, such as "3 feeds", 2: the number of items in total, such as "14 items", 3: the items per language, such as "English 6, Kurdish 3". */
					__( 'News Distribution’s own SmartFormat check found no problems in %1$s: %2$s in total (%3$s). Please tell us what you need next.', 'estasoft-news-distribution' ),
					sprintf(
						/* translators: %s: a number of feeds. */
						_n( '%s feed', '%s feeds', count( $feeds ), 'estasoft-news-distribution' ),
						number_format_i18n( count( $feeds ) )
					),
					$total,
					implode( ', ', $counts )
				);
			}
		}

		$report = ReportLinks::attached_url( 'smartnews', Clock::now() );

		if ( '' !== $report ) {
			$parts[] = sprintf(
				/* translators: %s: a web address. */
				__( 'A readiness report for the feeds is here: %s', 'estasoft-news-distribution' ),
				$report
			);
		}

		$user    = wp_get_current_user();
		$parts[] = __( 'Thank you,', 'estasoft-news-distribution' ) . "\n" . ( $user->exists() ? $user->display_name . ', ' : '' ) . $site;

		return implode( "\n\n", $parts );
	}

	/**
	 * The day a number of days from today, in the site's time zone.
	 *
	 * @param int $days Days from today.
	 * @return string Y-m-d.
	 */
	private function day_after( int $days ): string {
		$now = new \DateTimeImmutable( '@' . Clock::now() );

		return $now->setTimezone( Clock::zone() )->modify( '+' . $days . ' days' )->format( 'Y-m-d' );
	}

	/**
	 * Form values as plain strings.
	 *
	 * @param array<string, mixed> $input Form values.
	 * @return array<string, string>
	 */
	private function strings( array $input ): array {
		$out = array();

		foreach ( $input as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$out[ (string) $key ] = (string) $value;
			}
		}

		return $out;
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
