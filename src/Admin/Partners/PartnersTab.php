<?php
/**
 * The Partners tab of the Channels page: partners that read an API-key feed.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Partners;

use Estasoft\NewsDistribution\Admin\Admin;
use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Page;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\EmptyState;
use Estasoft\NewsDistribution\Admin\Ui\Field;
use Estasoft\NewsDistribution\Admin\Ui\FieldRows;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\PageHeader;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Admin\Ui\Table;
use Estasoft\NewsDistribution\Admin\Ui\Tag;
use Estasoft\NewsDistribution\Channels\SearchSitemap\FetchSummary;
use Estasoft\NewsDistribution\Channels\SearchSitemap\SitemapProbe;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Language\Catalog;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Frontend\PartnerFeedStore;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * `admin.php?page=estasoft-nd-channels&tab=partners`, for the `admin` permission, in the Full view.
 *
 * A partner has its own feed address and its own API key. This screen and the `/partners` routes
 * share one store (`Frontend\PartnerFeedStore`), so a key is made, replaced, revoked and deleted the
 * same way, with the same audit rows, from either.
 *
 * A key is shown once: in the answer to the post that made it, and nowhere else. That answer is not a
 * redirect, so the key is never in an address, a flash message or the database; a form carries a
 * one-use token, so reloading the answer does not make a second partner or a second key. Rotating,
 * revoking and deleting each ask first, on a page of their own, so nothing needs a script.
 *
 * To move this tab elsewhere, build it with `new PartnersTab( $plugin, $page )` and call `handle()`,
 * `enqueue()` and `render()` as `ChannelsPage` does; it only needs a page that has `url()`.
 */
final class PartnersTab {

	public const FIELD = 'estasoft_nd_partner_do';
	public const NONCE = 'estasoft_nd_partners';
	public const TOKEN = 'estasoft_nd_partner_token';

	/**
	 * The tab's name in the address.
	 */
	public const SLUG = 'partners';

	/**
	 * Actions that ask first, and the button that confirms each.
	 */
	private const CONFIRMS = array( 'rotate', 'revoke', 'delete' );

	private Plugin $plugin;
	private Page $page;
	private PartnerFeedStore $store;

	/**
	 * Messages for the fields of a form that was rejected, by field name.
	 *
	 * @var array<string, string>
	 */
	private array $errors = array();

	/**
	 * What a rejected form had in its boxes.
	 *
	 * @var array<string, mixed>
	 */
	private array $typed = array();

	/**
	 * Which form was rejected: "add", or a partner id.
	 *
	 * @var string
	 */
	private string $rejected = '';

	/**
	 * A partner and the key that was just made for it. Held for this one answer and printed once.
	 *
	 * `what` is "created" (a new partner), "rotated" (a key that was replaced) or "renewed" (a key for a partner that had none).
	 *
	 * @var array{partner: array<string, mixed>, key: string, what: string}|null
	 */
	private ?array $made = null;

	/**
	 * Whether a post was a repeat of one that already did its work.
	 *
	 * @var bool
	 */
	private bool $repeated = false;

	/**
	 * Creates the tab.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @param Page   $page   The page that shows the tab; it knows its own address.
	 */
	public function __construct( Plugin $plugin, Page $page ) {
		$this->plugin = $plugin;
		$this->page   = $page;
		$this->store  = $plugin->make( PartnerFeedStore::class );
	}

	/**
	 * The header button that opens the tab, for the screens that link to it.
	 *
	 * It shows in the Full view to a person with the `admin` permission, and nowhere else. The tab
	 * itself opens by its address for any `admin`, whichever view they chose.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @param Page   $page   The page the button is on.
	 * @return string Trusted HTML, or an empty string.
	 */
	public static function header_link( Plugin $plugin, Page $page ): string {
		if ( ! $plugin->access()->can( Access::ADMIN ) || 'full' !== (string) get_user_meta( get_current_user_id(), Admin::VIEW_META, true ) ) {
			return '';
		}

		return Button::link( __( 'Partners', 'estasoft-news-distribution' ), $page->url( array( 'tab' => self::SLUG ) ) );
	}

	/**
	 * Loads the tab's style and the script for the Copy buttons.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		Assets::screen_style( 'address-row' );
		Assets::screen_style( 'partner-keys' );
		Assets::screen_script( 'address-row' );
	}

	/**
	 * The address of this tab.
	 *
	 * @param array<string, scalar> $args Extra query arguments.
	 * @return string
	 */
	public function url( array $args = array() ): string {
		return $this->page->url( array_merge( array( 'tab' => self::SLUG ), $args ) );
	}

	/**
	 * Handles a form posted to the tab, before any output.
	 *
	 * A form that was refused is shown again with what was typed. A key that was made is shown in
	 * this answer. Everything else goes back to the tab with a message in the address.
	 *
	 * @return void
	 */
	public function handle(): void {
		$this->plugin->access()->require_or_die( Access::ADMIN );

		if ( ! isset( $_POST[ self::FIELD ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce is checked on the next line.
			return;
		}

		check_admin_referer( self::NONCE );

		$input = isset( $_POST[ Field::INPUT ] ) && is_array( $_POST[ Field::INPUT ] )
			? map_deep( wp_unslash( $_POST[ Field::INPUT ] ), 'sanitize_textarea_field' )
			: array();

		$to = $this->perform(
			sanitize_key( wp_unslash( $_POST[ self::FIELD ] ) ),
			isset( $_POST['partner'] ) ? sanitize_key( wp_unslash( $_POST['partner'] ) ) : '',
			$input,
			isset( $_POST[ self::TOKEN ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::TOKEN ] ) ) : ''
		);

		if ( null === $to ) {
			// The answer carries a key or a message, so it must not be kept by anything on the way.
			nocache_headers();

			return;
		}

		wp_safe_redirect( $to );
		exit;
	}

	/**
	 * Does what a posted form asks. `handle()` without the redirect.
	 *
	 * @param string               $action One of create, save, turn_on, turn_off, rotate, revoke, delete.
	 * @param string               $id     Partner id, for every action except create.
	 * @param array<string, mixed> $input  The form's values.
	 * @param string               $token  The form's one-use token, for the actions that make a key.
	 * @return string|null Where to go next, or null to show this answer (a key, or a form to fix).
	 */
	public function perform( string $action, string $id, array $input, string $token = '' ): ?string {
		$this->store->flush();

		if ( 'create' === $action ) {
			return $this->create( $input, $token );
		}

		$partner = $this->store->get( $id );

		if ( null === $partner ) {
			return $this->url( array( 'done' => 'missing' ) );
		}

		switch ( $action ) {
			case 'save':
				return $this->save( $id, $input );

			case 'turn_on':
			case 'turn_off':
				$on = 'turn_on' === $action;

				$this->store->update( $id, array( 'enabled' => $on ) );

				return $this->url(
					array(
						'done'    => $on ? 'on' : 'off',
						'partner' => $id,
					)
				);

			case 'rotate':
				return $this->rotate( $id, $token );

			case 'revoke':
				$this->store->revoke( $id );

				return $this->url(
					array(
						'done'    => 'revoked',
						'partner' => $id,
					)
				);

			case 'delete':
				$this->store->delete( $id );

				return $this->url( array( 'done' => 'deleted' ) );
		}

		return $this->url();
	}

	/**
	 * Adds a partner. Its key is held for this answer only.
	 *
	 * @param array<string, mixed> $input Form values.
	 * @param string               $token One-use token.
	 * @return string|null
	 */
	private function create( array $input, string $token ): ?string {
		if ( $this->used( $token ) ) {
			$this->repeated = true;

			return null;
		}

		$values = $this->values( $input );
		$made   = $this->store->create( $values );

		if ( is_wp_error( $made ) ) {
			$this->errors   = $this->fields( $made );
			$this->typed    = $values;
			$this->rejected = 'add';

			return null;
		}

		$this->use_up( $token );
		$this->made = array(
			'partner' => $made['partner'],
			'key'     => $made['key'],
			'what'    => 'created',
		);

		return null;
	}

	/**
	 * Changes a partner's name, languages, categories and rate limit.
	 *
	 * @param string               $id    Partner id.
	 * @param array<string, mixed> $input Form values.
	 * @return string|null
	 */
	private function save( string $id, array $input ): ?string {
		$values  = $this->values( $input );
		$updated = $this->store->update( $id, $values );

		if ( is_wp_error( $updated ) ) {
			$this->errors   = $this->fields( $updated );
			$this->typed    = $values;
			$this->rejected = $id;

			return null;
		}

		return $this->url(
			array(
				'done'    => 'saved',
				'partner' => $id,
			)
		);
	}

	/**
	 * Replaces a partner's key. The new one is held for this answer only.
	 *
	 * @param string $id    Partner id.
	 * @param string $token One-use token.
	 * @return string|null
	 */
	private function rotate( string $id, string $token ): ?string {
		if ( $this->used( $token ) ) {
			$this->repeated = true;

			return null;
		}

		$had  = '' !== (string) ( $this->store->get( $id )['key_hash'] ?? '' );
		$made = $this->store->rotate( $id );

		if ( is_wp_error( $made ) ) {
			return $this->url( array( 'done' => 'missing' ) );
		}

		$this->use_up( $token );
		$this->made = array(
			'partner' => $made['partner'],
			'key'     => $made['key'],
			'what'    => $had ? 'rotated' : 'renewed',
		);

		return null;
	}

	/**
	 * The four things a form sets, as the store wants them.
	 *
	 * @param array<string, mixed> $input Form values.
	 * @return array<string, mixed>
	 */
	private function values( array $input ): array {
		return array(
			'name'       => isset( $input['name'] ) && is_scalar( $input['name'] ) ? (string) $input['name'] : '',
			'languages'  => $this->list( $input['languages'] ?? array() ),
			'categories' => $this->list( $input['categories'] ?? array() ),
			'rate_limit' => isset( $input['rate_limit'] ) && is_scalar( $input['rate_limit'] ) ? trim( (string) $input['rate_limit'] ) : '',
		);
	}

	/**
	 * The ticked boxes of a group: the values without the empty marker that keeps an empty group in the post.
	 *
	 * @param mixed $raw What the form sent.
	 * @return array<int, string>
	 */
	private function list( $raw ): array {
		$list = array();

		foreach ( is_array( $raw ) ? $raw : array() as $value ) {
			if ( is_scalar( $value ) && '' !== (string) $value ) {
				$list[] = (string) $value;
			}
		}

		return array_values( array_unique( $list ) );
	}

	/**
	 * The field messages of a refused change.
	 *
	 * @param \WP_Error $error The store's error.
	 * @return array<string, string>
	 */
	private function fields( \WP_Error $error ): array {
		$data   = $error->get_error_data();
		$fields = is_array( $data ) && isset( $data['fields'] ) ? (array) $data['fields'] : array();

		return array() === $fields ? array( 'name' => $error->get_error_message() ) : array_map( 'strval', $fields );
	}

	/**
	 * Whether a form's token was already used.
	 *
	 * @param string $token The token, or an empty string when the post had none.
	 * @return bool
	 */
	private function used( string $token ): bool {
		return '' !== $token && false !== get_transient( $this->token_key( $token ) );
	}

	/**
	 * Marks a form's token as used. Only the token is kept, never the key.
	 *
	 * @param string $token The token.
	 * @return void
	 */
	private function use_up( string $token ): void {
		if ( '' !== $token ) {
			set_transient( $this->token_key( $token ), 1, 10 * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * The name a used token is kept under.
	 *
	 * @param string $token The token.
	 * @return string
	 */
	private function token_key( string $token ): string {
		return 'estasoft_nd_pt_' . md5( $token );
	}

	/**
	 * Prints the tab.
	 *
	 * @return string Escaped HTML.
	 */
	public function render(): string {
		$this->plugin->access()->require_or_die( Access::ADMIN );
		$this->store->flush();

		$partners = $this->store->views();
		$selected = $this->selected( $partners );
		$adding   = 'add' === $this->rejected || ( null === $this->made && $this->asked( 'add' ) );
		$confirm  = '' !== $selected && in_array( $this->value( 'confirm' ), self::CONFIRMS, true ) && null === $this->made ? $this->value( 'confirm' ) : '';
		$open     = '' !== $selected ? $this->view_of( $partners, $selected ) : array();

		$crumbs = Html::tag(
			'nav',
			array(
				'class'      => 'estasoft-nd-breadcrumb estasoft-nd-small',
				'aria-label' => __( 'Breadcrumb', 'estasoft-news-distribution' ),
			),
			Html::tag( 'a', array( 'href' => $this->page->url() ), esc_html__( 'Channels', 'estasoft-news-distribution' ) )
			. Html::tag( 'span', array( 'aria-hidden' => 'true' ), ' / ' )
			. Html::tag( 'span', array( 'aria-current' => 'page' ), esc_html__( 'Partners', 'estasoft-news-distribution' ) )
		);

		$add = array() !== $partners && ! $adding
			? Button::link( __( 'Add partner', 'estasoft-news-distribution' ), $this->url( array( 'add' => 1 ) ) . '#estasoft-nd-partner-new' )
			: '';

		$html = $crumbs
			. PageHeader::render(
				__( 'Partners', 'estasoft-news-distribution' ),
				__( 'Give partners their own filtered feeds, with keys and rate limits.', 'estasoft-news-distribution' ),
				$add
			)
			. $this->permalinks()
			. $this->notice( $partners );

		if ( null !== $this->made ) {
			$html .= $this->made_card( $this->made );
		}

		if ( '' !== $confirm && array() !== $open ) {
			$html .= $this->confirm_card( $confirm, $open );
		}

		$html .= array() === $partners && ! $adding
			? EmptyState::render(
				__( 'No partners yet.', 'estasoft-news-distribution' ),
				__( 'Add a partner to give it its own feed address and API key.', 'estasoft-news-distribution' ),
				Button::link( __( 'Add partner', 'estasoft-news-distribution' ), $this->url( array( 'add' => 1 ) ) . '#estasoft-nd-partner-new', array( 'variant' => 'primary' ) )
			)
			: ( array() === $partners ? '' : $this->table( $partners, $selected ) );

		if ( $adding ) {
			$html .= $this->new_card();
		}

		if ( array() !== $open && null === $this->made ) {
			$html .= $this->manage_card( $open ) . $this->settings_card( $open );
		}

		return $html . $this->notes();
	}

	/**
	 * The partner whose details are open: the one a refused form was for, the one that just got a key, or the one in the address.
	 *
	 * @param array<int, array<string, mixed>> $partners The partners.
	 * @return string An id, or an empty string.
	 */
	private function selected( array $partners ): string {
		$wanted = 'add' === $this->rejected ? '' : $this->rejected;

		if ( null !== $this->made ) {
			$wanted = (string) $this->made['partner']['id'];
		}

		if ( '' === $wanted && ! $this->asked( 'add' ) ) {
			$wanted = $this->value( 'partner' );
		}

		return array() !== $this->view_of( $partners, $wanted ) ? $wanted : '';
	}

	/**
	 * One partner of a list.
	 *
	 * @param array<int, array<string, mixed>> $partners The partners.
	 * @param string                           $id       Partner id.
	 * @return array<string, mixed> Empty when there is no such partner.
	 */
	private function view_of( array $partners, string $id ): array {
		foreach ( $partners as $partner ) {
			if ( (string) $partner['id'] === $id ) {
				return $partner;
			}
		}

		return array();
	}

	/**
	 * A value from the address, as a key.
	 *
	 * @param string $name Query argument.
	 * @return string
	 */
	private function value( string $name ): string {
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
	 * A warning while the site uses plain permalinks: a feed address cannot answer then.
	 *
	 * @return string
	 */
	private function permalinks(): string {
		return SitemapProbe::plain_permalinks()
			? Message::text( 'warning', __( 'This site uses plain permalinks, so a partner’s feed address cannot answer yet. Choose another structure in Settings, Permalinks.', 'estasoft-news-distribution' ) )
			: '';
	}

	/**
	 * The message after a change, or the reason a form was refused.
	 *
	 * @param array<int, array<string, mixed>> $partners The partners.
	 * @return string
	 */
	private function notice( array $partners ): string {
		if ( $this->repeated ) {
			return Message::text( 'warning', __( 'That form was already sent, so nothing new was made. A key is shown only once. To get a new key, open Manage for the partner and choose Rotate key.', 'estasoft-news-distribution' ) );
		}

		if ( array() !== $this->errors ) {
			return Message::text(
				'danger',
				'add' === $this->rejected
					? __( 'The partner was not added. Fix the field marked below, then try again.', 'estasoft-news-distribution' )
					: __( 'Nothing was saved. Fix the field marked below, then save again.', 'estasoft-news-distribution' )
			);
		}

		$done = $this->value( 'done' );
		$name = (string) ( $this->view_of( $partners, $this->value( 'partner' ) )['name'] ?? '' );

		switch ( $done ) {
			case 'saved':
				return Message::text(
					'success',
					sprintf(
						/* translators: %s: a partner name. */
						__( '%s was saved.', 'estasoft-news-distribution' ),
						$name
					)
				);
			case 'on':
				return Message::text(
					'success',
					sprintf(
						/* translators: %s: a partner name. */
						__( '%s is on. Its feed answers again.', 'estasoft-news-distribution' ),
						$name
					)
				);
			case 'off':
				return Message::text(
					'success',
					sprintf(
						/* translators: %s: a partner name. */
						__( '%s is off. Its feed answers “Not found” until you turn it on.', 'estasoft-news-distribution' ),
						$name
					)
				);
			case 'revoked':
				return Message::text(
					'success',
					sprintf(
						/* translators: %s: a partner name. */
						__( 'The key for %s was revoked. Its feed answers with an error until you make a new key.', 'estasoft-news-distribution' ),
						$name
					)
				);
			case 'deleted':
				return Message::text( 'success', __( 'The partner was deleted. Its feed address and its key stopped working.', 'estasoft-news-distribution' ) );
			case 'missing':
				return Message::text( 'danger', __( 'That partner does not exist.', 'estasoft-news-distribution' ) );
		}

		return '';
	}

	/**
	 * The list of partners.
	 *
	 * @param array<int, array<string, mixed>> $partners The partners.
	 * @param string                           $selected The open partner's id.
	 * @return string
	 */
	private function table( array $partners, string $selected ): string {
		$categories = $this->store->categories_available();
		$rows       = array();

		foreach ( $partners as $partner ) {
			$id    = (string) $partner['id'];
			$name  = (string) $partner['name'];
			$names = array();

			foreach ( (array) $partner['categories'] as $slug ) {
				$names[] = $categories[ (string) $slug ] ?? (string) $slug;
			}

			$tags = array_map(
				static function ( $code ): string {
					return Catalog::tag( (string) $code );
				},
				(array) $partner['languages']
			);

			$rows[] = array(
				Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-partner-name' ),
					Html::tag( 'span', array( 'class' => 'estasoft-nd-strong' ), esc_html( $name ) )
					. ( ! empty( $partner['enabled'] ) ? StatusView::pill( Status::HEALTHY, __( 'On', 'estasoft-news-distribution' ) ) : StatusView::pill( Status::OFF ) )
				),
				Html::tag( 'span', array( 'dir' => 'ltr' ), esc_html( implode( ', ', $tags ) ) ),
				esc_html( array() === $names ? __( 'All categories', 'estasoft-news-distribution' ) : implode( ', ', $names ) ),
				esc_html(
					sprintf(
						/* translators: %s: a number of requests. */
						__( '%s per minute', 'estasoft-news-distribution' ),
						number_format_i18n( (int) $partner['rate_limit'] )
					)
				),
				esc_html( $this->fetched( $partner ) ),
				Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-partner-actions-cell' ),
					Html::tag(
						'a',
						array(
							'class'        => 'estasoft-nd-strong',
							'href'         => $this->url( array( 'partner' => $id ) ) . '#estasoft-nd-partner-manage',
							'aria-current' => $id === $selected ? 'true' : null,
							'aria-label'   => sprintf(
								/* translators: %s: a partner name. */
								__( 'Manage %s', 'estasoft-news-distribution' ),
								$name
							),
						),
						esc_html__( 'Manage', 'estasoft-news-distribution' )
					)
					. $this->switch_form( $partner )
				),
			);
		}

		return Table::render(
			'estasoft-nd-cols-partners',
			array(
				__( 'Partner', 'estasoft-news-distribution' ),
				__( 'Languages', 'estasoft-news-distribution' ),
				__( 'Categories', 'estasoft-news-distribution' ),
				__( 'Rate limit', 'estasoft-news-distribution' ),
				__( 'Last fetch', 'estasoft-news-distribution' ),
				__( 'Actions', 'estasoft-news-distribution' ),
			),
			$rows,
			__( 'Partners', 'estasoft-news-distribution' ),
			esc_html(
				sprintf(
					/* translators: 1: how many partners are shown, 2: how many there are. */
					_n( 'Showing %1$s of %2$s partner', 'Showing %1$s of %2$s partners', count( $partners ), 'estasoft-news-distribution' ),
					number_format_i18n( count( $partners ) ),
					number_format_i18n( count( $partners ) )
				)
			)
		);
	}

	/**
	 * When a partner last fetched its feed.
	 *
	 * @param array<string, mixed> $partner A partner view.
	 * @return string
	 */
	private function fetched( array $partner ): string {
		$when = empty( $partner['last_fetch'] ) ? 0 : (int) strtotime( (string) $partner['last_fetch'] );

		return $when > 0 ? FetchSummary::ago( $when ) : Status::describe( Status::NEVER )['word'];
	}

	/**
	 * The small form in the list that turns a partner's feed off or on.
	 *
	 * @param array<string, mixed> $partner A partner view.
	 * @return string
	 */
	private function switch_form( array $partner ): string {
		$on   = ! empty( $partner['enabled'] );
		$name = (string) $partner['name'];

		return Html::tag(
			'form',
			array(
				'class'  => 'estasoft-nd-partner-inline',
				'method' => 'post',
				'action' => $this->url(),
			),
			$this->hidden( self::FIELD, $on ? 'turn_off' : 'turn_on' )
			. $this->hidden( 'partner', (string) $partner['id'] )
			. $this->hidden( '_wpnonce', wp_create_nonce( self::NONCE ) )
			. Button::submit(
				$on ? __( 'Turn off', 'estasoft-news-distribution' ) : __( 'Turn on', 'estasoft-news-distribution' ),
				array(
					'size'  => 'sm',
					'attrs' => array(
						'aria-label' => sprintf(
							$on
								/* translators: %s: a partner name. */
								? __( 'Turn off %s', 'estasoft-news-distribution' )
								/* translators: %s: a partner name. */
								: __( 'Turn on %s', 'estasoft-news-distribution' ),
							$name
						),
					),
				)
			)
		);
	}

	/**
	 * The "New partner" form. The key is made when the form is sent.
	 *
	 * @return string
	 */
	private function new_card(): string {
		$rejected = 'add' === $this->rejected;
		$values   = $rejected ? $this->typed : array(
			'name'       => '',
			'languages'  => array( (string) $this->plugin->languages()->default_language() ),
			'categories' => array(),
			'rate_limit' => (string) PartnerFeedStore::RATE_DEFAULT,
		);
		$errors   = $rejected ? $this->errors : array();

		$key = Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-muted' ),
			esc_html__( 'Made when you press Create partner.', 'estasoft-news-distribution' )
		)
			. Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
				esc_html__( 'Shown once. Copy it now; News Distribution cannot show it again. The key exists only after you press Create partner. Cancel discards it.', 'estasoft-news-distribution' )
			);

		$rows = $this->setting_rows( 'partner-new', $values, $errors )
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-form-row' ),
				Html::tag( 'div', array( 'class' => 'estasoft-nd-form-label' ), esc_html__( 'API key', 'estasoft-news-distribution' ) )
				. Html::tag( 'div', array( 'class' => 'estasoft-nd-form-control' ), $key )
			)
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-partner-footer' ),
				Button::submit( __( 'Create partner', 'estasoft-news-distribution' ), array( 'variant' => 'primary' ) )
				. Button::link( __( 'Cancel', 'estasoft-news-distribution' ), $this->url() )
			);

		return Html::tag(
			'form',
			array(
				'id'         => 'estasoft-nd-partner-new',
				'class'      => 'estasoft-nd-partner-form',
				'method'     => 'post',
				'action'     => $this->url( array( 'add' => 1 ) ),
				'novalidate' => true,
			),
			$this->hidden( self::FIELD, 'create' )
			. $this->hidden( self::TOKEN, wp_generate_password( 20, false ) )
			. $this->hidden( '_wpnonce', wp_create_nonce( self::NONCE ) )
			. Card::flush(
				'partner-new',
				__( 'New partner', 'estasoft-news-distribution' ),
				__( 'Each partner gets its own feed URL and API key. Partners never see your other feeds.', 'estasoft-news-distribution' ),
				$rows
			)
		);
	}

	/**
	 * Name, languages, categories and rate limit: the rows the new-partner form and the settings form share.
	 *
	 * @param string                $scope  Makes the control ids unique.
	 * @param array<string, mixed>  $values What to show.
	 * @param array<string, string> $errors Messages by field.
	 * @return string
	 */
	private function setting_rows( string $scope, array $values, array $errors ): string {
		$fields = array(
			'name'       => array(
				'type'  => 'string',
				'label' => __( 'Name (required)', 'estasoft-news-distribution' ),
				'width' => 320,
				'max'   => PartnerFeedStore::NAME_MAX,
				'help'  => __( 'Shown only to you.', 'estasoft-news-distribution' ),
			),
			'rate_limit' => array(
				'type'  => 'int',
				'label' => __( 'Rate limit', 'estasoft-news-distribution' ),
				'width' => 90,
				'min'   => PartnerFeedStore::RATE_MIN,
				'max'   => PartnerFeedStore::RATE_MAX,
				'unit'  => __( 'requests per minute', 'estasoft-news-distribution' ),
				'help'  => sprintf(
					/* translators: 1: the smallest number of requests a minute, 2: the largest. */
					__( 'From %1$d to %2$d. A partner above its limit gets a “Too many requests” answer and can try again in the next minute.', 'estasoft-news-distribution' ),
					PartnerFeedStore::RATE_MIN,
					PartnerFeedStore::RATE_MAX
				),
			),
		);
		$rows   = new FieldRows( $scope, $fields, $values, $errors );

		return $rows->row( array( 'field' => 'name' ) )
			. $this->group_row( __( 'Languages', 'estasoft-news-distribution' ), $this->language_boxes( (array) ( $values['languages'] ?? array() ), $errors['languages'] ?? '' ) )
			. $this->group_row( __( 'Categories', 'estasoft-news-distribution' ), $this->category_boxes( (array) ( $values['categories'] ?? array() ), $errors['categories'] ?? '' ) )
			. $rows->row( array( 'field' => 'rate_limit' ) );
	}

	/**
	 * A form row for a group of check boxes.
	 *
	 * @param string $label The group's name.
	 * @param string $body  Trusted HTML.
	 * @return string
	 */
	private function group_row( string $label, string $body ): string {
		$id = 'estasoft-nd-partner-group-' . sanitize_key( $label );

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-form-row' ),
			Html::tag(
				'div',
				array(
					'class' => 'estasoft-nd-form-label',
					'id'    => $id,
				),
				esc_html( $label )
			)
			. Html::tag(
				'div',
				array(
					'class'           => 'estasoft-nd-form-control',
					'role'            => 'group',
					'aria-labelledby' => $id,
				),
				$body
			)
		);
	}

	/**
	 * One check box of a group.
	 *
	 * @param string $field   Field name.
	 * @param string $value   The box's value.
	 * @param bool   $checked Whether it is ticked.
	 * @param string $label   Trusted HTML for the label text.
	 * @return string
	 */
	private function box( string $field, string $value, bool $checked, string $label ): string {
		return Html::tag(
			'label',
			array( 'class' => 'estasoft-nd-check' ),
			Html::void(
				'input',
				array(
					'type'    => 'checkbox',
					'name'    => Field::name( $field ) . '[]',
					'value'   => $value,
					'checked' => $checked,
				)
			) . $label
		);
	}

	/**
	 * The group's empty marker, which keeps "nothing ticked" in the post, and an error line.
	 *
	 * @param string $field Field name.
	 * @return string
	 */
	private function marker( string $field ): string {
		return Html::void(
			'input',
			array(
				'type'  => 'hidden',
				'name'  => Field::name( $field ) . '[]',
				'value' => '',
			)
		);
	}

	/**
	 * A field's error line.
	 *
	 * @param string $message The message, or an empty string.
	 * @return string
	 */
	private function error_line( string $message ): string {
		return '' === $message ? '' : Html::tag(
			'p',
			array(
				'class' => 'estasoft-nd-field-error',
				'role'  => 'alert',
			),
			Icon::svg( 'x-circle' ) . Html::tag( 'span', array(), esc_html( $message ) )
		);
	}

	/**
	 * The language boxes: one per language the site publishes in.
	 *
	 * @param array<int, mixed> $chosen The ticked codes.
	 * @param string            $error  A message, or an empty string.
	 * @return string
	 */
	private function language_boxes( array $chosen, string $error ): string {
		$chosen = array_map( 'strval', $chosen );
		$site   = array_map( 'strval', $this->plugin->languages()->languages() );
		$html   = $this->marker( 'languages' );

		foreach ( $site as $code ) {
			$html .= $this->box(
				'languages',
				$code,
				in_array( $code, $chosen, true ),
				Html::tag( 'span', array(), esc_html( Catalog::name( $code ) ) ) . Tag::lang( $code )
			);
		}

		$html   .= $this->error_line( $error );
		$source  = $this->plugin->languages()->name();
		$have    = array_map( array( Catalog::class, 'primary' ), $site );
		$missing = array();

		foreach ( array_keys( Catalog::names() ) as $code ) {
			if ( ! in_array( (string) $code, $have, true ) ) {
				$missing[] = Catalog::name( (string) $code ) . ' ' . Catalog::tag( (string) $code );
			}
		}

		if ( array() !== $missing && in_array( $source, array( 'polylang', 'wpml' ), true ) ) {
			$html .= Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
				esc_html(
					sprintf(
						/* translators: 1: the translation plugin, such as Polylang; 2: language names with their tags, such as "Spanish ES". */
						__( 'Not set up in %1$s: %2$s.', 'estasoft-news-distribution' ),
						'polylang' === $source ? 'Polylang' : 'WPML',
						implode( ', ', $missing )
					)
				)
			);
		}

		return $html;
	}

	/**
	 * The category boxes.
	 *
	 * @param array<int, mixed> $chosen The ticked slugs.
	 * @param string            $error  A message, or an empty string.
	 * @return string
	 */
	private function category_boxes( array $chosen, string $error ): string {
		$chosen = array_map( 'strval', $chosen );
		$all    = $this->store->categories_available();
		$html   = $this->marker( 'categories' );

		foreach ( $all as $slug => $name ) {
			$html .= $this->box( 'categories', (string) $slug, in_array( (string) $slug, $chosen, true ), esc_html( $name ) );
		}

		if ( array() === $all ) {
			$html .= Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Nothing to choose from yet.', 'estasoft-news-distribution' ) );
		}

		return $html
			. $this->error_line( $error )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Tick one or more. With none ticked, the partner gets every category.', 'estasoft-news-distribution' ) );
	}

	/**
	 * "Manage {partner}": the feed address, the key and the way to delete.
	 *
	 * @param array<string, mixed> $partner The open partner.
	 * @return string
	 */
	private function manage_card( array $partner ): string {
		$name = (string) $partner['name'];
		$id   = (string) $partner['id'];
		$key  = (array) $partner['key'];
		$set  = 'set' === (string) $key['status'];

		$feed = $this->row(
			__( 'Feed address', 'estasoft-news-distribution' ),
			$this->copy_box(
				(string) $partner['feed_url'],
				sprintf(
					/* translators: %s: a partner name. */
					__( 'Feed address of %s', 'estasoft-news-distribution' ),
					$name
				),
				sprintf(
					/* translators: %s: a partner name. */
					__( 'Copy the feed address of %s', 'estasoft-news-distribution' ),
					$name
				)
			)
		);

		if ( $set ) {
			$created = empty( $key['created'] ) ? 0 : (int) strtotime( (string) $key['created'] );
			$seen    = empty( $partner['last_fetch'] ) ? 0 : (int) strtotime( (string) $partner['last_fetch'] );
			$facts   = $created > 0 && $seen > 0
				? sprintf(
					/* translators: 1: a day such as "2 Oct", 2: how long ago, such as "4 min ago". */
					__( 'Created %1$s. Last used %2$s.', 'estasoft-news-distribution' ),
					Clock::format( $created, 'j M' ),
					FetchSummary::ago( $seen )
				)
				: sprintf(
					/* translators: %s: a day such as "2 Oct". */
					__( 'Created %s. Never used.', 'estasoft-news-distribution' ),
					$created > 0 ? Clock::format( $created, 'j M' ) : ''
				);

			$key_body = Html::tag(
				'code',
				array(
					'class' => 'estasoft-nd-mono estasoft-nd-partner-key',
					'dir'   => 'ltr',
				),
				esc_html( (string) $key['masked'] )
			)
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $facts ) )
				. Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
					Button::link(
						__( 'Rotate key', 'estasoft-news-distribution' ),
						$this->url(
							array(
								'partner' => $id,
								'confirm' => 'rotate',
							)
						) . '#estasoft-nd-partner-confirm'
					)
					. Button::link(
						__( 'Revoke key', 'estasoft-news-distribution' ),
						$this->url(
							array(
								'partner' => $id,
								'confirm' => 'revoke',
							)
						) . '#estasoft-nd-partner-confirm'
					)
				);
		} else {
			$key_body = Html::tag( 'p', array(), esc_html__( 'No key. The feed answers with an error until you make one.', 'estasoft-news-distribution' ) )
				. Html::tag(
					'form',
					array(
						'method' => 'post',
						'action' => $this->url( array( 'partner' => $id ) ),
					),
					$this->hidden( self::FIELD, 'rotate' )
					. $this->hidden( 'partner', $id )
					. $this->hidden( self::TOKEN, wp_generate_password( 20, false ) )
					. $this->hidden( '_wpnonce', wp_create_nonce( self::NONCE ) )
					. Button::submit( __( 'Make a new key', 'estasoft-news-distribution' ) )
				);
		}

		$body = $feed
			. $this->row( __( 'API key', 'estasoft-news-distribution' ), $key_body )
			. $this->row(
				__( 'Delete partner', 'estasoft-news-distribution' ),
				Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
					Button::link(
						__( 'Delete partner', 'estasoft-news-distribution' ),
						$this->url(
							array(
								'partner' => $id,
								'confirm' => 'delete',
							)
						) . '#estasoft-nd-partner-confirm'
					)
				)
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'To pause a partner instead, turn it Off in the list.', 'estasoft-news-distribution' ) )
			);

		return Html::tag(
			'div',
			array( 'id' => 'estasoft-nd-partner-manage' ),
			Card::flush(
				'partner-manage',
				sprintf(
					/* translators: %s: a partner name. */
					__( 'Manage %s', 'estasoft-news-distribution' ),
					$name
				),
				__( 'Opened from Manage in the list. Each action asks you to confirm first.', 'estasoft-news-distribution' ),
				$body
			)
		);
	}

	/**
	 * The form that changes a partner's name, languages, categories and rate limit.
	 *
	 * @param array<string, mixed> $partner The open partner.
	 * @return string
	 */
	private function settings_card( array $partner ): string {
		$id       = (string) $partner['id'];
		$rejected = $this->rejected === $id;
		$values   = $rejected ? $this->typed : array(
			'name'       => (string) $partner['name'],
			'languages'  => (array) $partner['languages'],
			'categories' => (array) $partner['categories'],
			'rate_limit' => (string) $partner['rate_limit'],
		);
		$rows     = $this->setting_rows( 'partner-edit', $values, $rejected ? $this->errors : array() )
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-partner-footer' ),
				Button::submit( __( 'Save changes', 'estasoft-news-distribution' ), array( 'variant' => 'primary' ) )
			);

		return Html::tag(
			'form',
			array(
				'class'      => 'estasoft-nd-partner-form',
				'method'     => 'post',
				'action'     => $this->url( array( 'partner' => $id ) ),
				'novalidate' => true,
			),
			$this->hidden( self::FIELD, 'save' )
			. $this->hidden( 'partner', $id )
			. $this->hidden( '_wpnonce', wp_create_nonce( self::NONCE ) )
			. Card::flush(
				'partner-settings',
				sprintf(
					/* translators: %s: a partner name. */
					__( 'Settings for %s', 'estasoft-news-distribution' ),
					(string) $partner['name']
				),
				__( 'Changes apply to the feed at once.', 'estasoft-news-distribution' ),
				$rows
			)
		);
	}

	/**
	 * The question before a key is replaced or revoked or a partner is deleted.
	 *
	 * @param string               $action rotate, revoke or delete.
	 * @param array<string, mixed> $partner The partner.
	 * @return string
	 */
	private function confirm_card( string $action, array $partner ): string {
		$name = (string) $partner['name'];
		$id   = (string) $partner['id'];

		if ( 'rotate' === $action ) {
			$title = sprintf(
				/* translators: %s: a partner name. */
				__( 'Rotate the key for %s?', 'estasoft-news-distribution' ),
				$name
			);
			$text  = __( 'The old key stops working at once, and you see the new key once. This cannot be undone.', 'estasoft-news-distribution' );
			$label = __( 'Rotate key', 'estasoft-news-distribution' );
		} elseif ( 'revoke' === $action ) {
			$title = sprintf(
				/* translators: %s: a partner name. */
				__( 'Revoke the key for %s?', 'estasoft-news-distribution' ),
				$name
			);
			$text  = __( 'Its feed stops at once and answers with an error until you create a new key. This cannot be undone.', 'estasoft-news-distribution' );
			$label = __( 'Revoke key', 'estasoft-news-distribution' );
		} else {
			$title = sprintf(
				/* translators: %s: a partner name. */
				__( 'Delete %s?', 'estasoft-news-distribution' ),
				$name
			);
			$text  = __( 'Its feed address stops working. This cannot be undone.', 'estasoft-news-distribution' );
			$label = __( 'Delete partner', 'estasoft-news-distribution' );
		}

		return Html::tag(
			'form',
			array(
				'id'     => 'estasoft-nd-partner-confirm',
				'method' => 'post',
				'action' => $this->url( array( 'partner' => $id ) ),
			),
			$this->hidden( self::FIELD, $action )
			. $this->hidden( 'partner', $id )
			. ( 'rotate' === $action ? $this->hidden( self::TOKEN, wp_generate_password( 20, false ) ) : '' )
			. $this->hidden( '_wpnonce', wp_create_nonce( self::NONCE ) )
			. Html::tag(
				'section',
				array(
					'class'           => 'estasoft-nd-card estasoft-nd-card--note',
					'aria-labelledby' => 'estasoft-nd-partner-confirm-title',
				),
				Html::tag(
					'h2',
					array(
						'class' => 'estasoft-nd-title',
						'id'    => 'estasoft-nd-partner-confirm-title',
					),
					esc_html( $title )
				)
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( $text ) )
				. Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
					Button::submit( $label, array( 'variant' => 'primary' ) )
					. Button::link( __( 'Cancel', 'estasoft-news-distribution' ), $this->url( array( 'partner' => $id ) ) . '#estasoft-nd-partner-manage' )
				)
			)
		);
	}

	/**
	 * The card that shows a new key, once.
	 *
	 * @param array{partner: array<string, mixed>, key: string, what: string} $made The partner and its key.
	 * @return string
	 */
	private function made_card( array $made ): string {
		$name = (string) $made['partner']['name'];

		$title = sprintf(
			/* translators: %s: a partner name. */
			__( 'API key for %s', 'estasoft-news-distribution' ),
			$name
		);

		$lead = 'rotated' === $made['what']
			? __( 'The old key stopped working. Give the partner this one. It sends the key in an X-API-Key header or an Authorization: Bearer header.', 'estasoft-news-distribution' )
			: __( 'Give the partner its feed address and this key. It sends the key in an X-API-Key header or an Authorization: Bearer header.', 'estasoft-news-distribution' );

		$body = $this->row(
			__( 'API key', 'estasoft-news-distribution' ),
			$this->copy_box(
				$made['key'],
				__( 'API key', 'estasoft-news-distribution' ),
				sprintf(
						/* translators: %s: a partner name. */
					__( 'Copy the API key of %s', 'estasoft-news-distribution' ),
					$name
				)
			)
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Shown once. Copy it now; News Distribution cannot show it again.', 'estasoft-news-distribution' ) )
		)
			. $this->row(
				__( 'Feed address', 'estasoft-news-distribution' ),
				$this->copy_box(
					(string) $made['partner']['feed_url'],
					sprintf(
						/* translators: %s: a partner name. */
						__( 'Feed address of %s', 'estasoft-news-distribution' ),
						$name
					),
					sprintf(
						/* translators: %s: a partner name. */
						__( 'Copy the feed address of %s', 'estasoft-news-distribution' ),
						$name
					)
				)
			)
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-partner-footer' ),
				Button::link( __( 'Done', 'estasoft-news-distribution' ), $this->url( array( 'partner' => (string) $made['partner']['id'] ) ) . '#estasoft-nd-partner-manage', array( 'variant' => 'primary' ) )
			);

		if ( 'created' === $made['what'] ) {
			$said = sprintf(
				/* translators: %s: a partner name. */
				__( '%s was added. Copy its key now: it is shown once.', 'estasoft-news-distribution' ),
				$name
			);
		} elseif ( 'rotated' === $made['what'] ) {
			$said = sprintf(
				/* translators: %s: a partner name. */
				__( 'The key for %s was replaced. Copy the new key now: it is shown once.', 'estasoft-news-distribution' ),
				$name
			);
		} else {
			$said = sprintf(
				/* translators: %s: a partner name. */
				__( 'A new key was made for %s. Copy it now: it is shown once.', 'estasoft-news-distribution' ),
				$name
			);
		}

		return Message::text( 'success', $said ) . Card::flush( 'partner-made', $title, $lead, $body );
	}

	/**
	 * A form row with a label and a control, the way the settings forms print one.
	 *
	 * @param string $label The row's name.
	 * @param string $body  Trusted HTML.
	 * @return string
	 */
	private function row( string $label, string $body ): string {
		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-form-row' ),
			Html::tag( 'div', array( 'class' => 'estasoft-nd-form-label' ), esc_html( $label ) )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-form-control' ), $body )
		);
	}

	/**
	 * Text a person can select, with a Copy button that works when the script has loaded.
	 *
	 * @param string $text       What to show and copy.
	 * @param string $label      The text's accessible name.
	 * @param string $copy_label The button's accessible name.
	 * @return string
	 */
	private function copy_box( string $text, string $label, string $copy_label ): string {
		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-address' ),
			Html::tag(
				'code',
				array(
					'class'      => 'estasoft-nd-url estasoft-nd-mono',
					'dir'        => 'ltr',
					'tabindex'   => '0',
					'aria-label' => $label,
				),
				esc_html( $text )
			)
			. Button::button(
				__( 'Copy', 'estasoft-news-distribution' ),
				array(
					'size'  => 'sm',
					'icon'  => 'copy',
					'class' => 'estasoft-nd-partner-copy',
					'attrs' => array(
						'aria-label'        => $copy_label,
						'data-copy'         => $text,
						'data-copied-label' => __( 'Copied', 'estasoft-news-distribution' ),
					),
				)
			)
		);
	}

	/**
	 * What this tab cannot do, and where it appears.
	 *
	 * @return string
	 */
	private function notes(): string {
		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-notes' ),
			Card::cannot(
				array(
					__( 'Limit what a partner does with articles after it fetches them', 'estasoft-news-distribution' ),
					__( 'Show a partner unpublished articles, or any feed you did not give it', 'estasoft-news-distribution' ),
					__( 'Stop a partner from sharing its key. Rotate the key if you think it leaked.', 'estasoft-news-distribution' ),
				)
			)
			. Card::note(
				'partner-where',
				__( 'Where this screen appears', 'estasoft-news-distribution' ),
				Html::tag(
					'p',
					array( 'class' => 'estasoft-nd-muted' ),
					esc_html__( 'The Partners button on the Channels page shows in Full view (Settings, General, View). A partner is someone who reads your articles with an API key. The platforms you apply to, such as Microsoft Start, are under Channels. The screens for a whole network are in Network Admin, not here.', 'estasoft-news-distribution' )
				)
			)
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
