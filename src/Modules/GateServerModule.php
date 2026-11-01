<?php
/**
 * The pre-publish gate on the server: the REST API, the classic editor, Quick Edit, bulk edit and XML-RPC.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Admin\Output;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Core\Gate;
use Estasoft\NewsDistribution\Core\GateXmlRpc;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks `Core\Gate::server_check()` into every way a save can make an article public.
 *
 * - `rest_pre_insert_{type}`: a REST save that publishes is refused with a 409, code `estasoft_nd_gate_blocked`,
 *   the findings and the way to override. Nothing is written.
 * - `wp_insert_post_data`: the classic editor, Quick Edit, bulk edit and XML-RPC. The article keeps the status it
 *   had (a draft for a new one), and the person's next page says why. A REST save the first hook already
 *   passed is not asked again.
 * - `transition_post_status`: when an article really goes live past the gate, the audit row is written, once.
 * - `xmlrpc_methods`: the XML-RPC methods that can publish answer with an error, not `true` or an ID, when the
 *   article was kept back (`Core\GateXmlRpc`).
 *
 * It does nothing unless Settings, Access and gate says Block publishing, and `Core\Gate` stands aside for WP-Cron,
 * WP-CLI, imports, a save nobody signed in made and an article that is already public. A check that throws or runs
 * past its time budget lets the article through and writes an audit row.
 *
 * The classic editor also gets a box, shown only while the stored check says publishing is on hold, with the
 * reason field (`estasoft_nd_override_reason`) and a "Publish anyway" button. It works without a script.
 */
final class GateServerModule implements ModuleInterface {

	public const BOX = 'estasoft-nd-gate-box';

	private Plugin $plugin;

	/**
	 * What the REST hook decided, by the token it left on the save, until `wp_insert_post_data` reads it.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $decided = array();

	/**
	 * The override waiting for the article to go live: who, why, which channels, and the status before.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $pending = null;

	/**
	 * What the classic editor's box shows, when it is shown.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $hold = null;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		// Late, so the data is what will really be written; another feature may already have kept the article a draft.
		add_filter( 'wp_insert_post_data', array( $this, 'filter_post_data' ), 99, 4 );
		add_action( 'rest_api_init', array( $this, 'add_rest_hooks' ) );
		add_action( 'transition_post_status', array( $this, 'transitioned' ), 10, 3 );
		add_filter( 'xmlrpc_methods', array( new GateXmlRpc( $plugin ), 'wrap' ), 99 );

		if ( is_admin() ) {
			add_action( 'admin_notices', array( $this, 'notices' ) );
			add_action( 'add_meta_boxes', array( $this, 'add_box' ), 10, 2 );
			add_action( 'load-post.php', array( $this, 'map_publish_anyway' ) );
		}
	}

	/**
	 * `rest_api_init`: one hook per post type the REST API serves. The gate decides which types it guards.
	 *
	 * @return void
	 */
	public function add_rest_hooks(): void {
		foreach ( get_post_types( array( 'show_in_rest' => true ), 'names' ) as $type ) {
			add_filter( 'rest_pre_insert_' . $type, array( $this, 'rest_pre_insert' ), 99, 2 );
		}
	}

	/**
	 * `rest_pre_insert_{type}`: refuses a REST save that would publish an article that fails the gate.
	 *
	 * @param mixed $prepared The post the REST API is about to write.
	 * @param mixed $request  The request.
	 * @return mixed The post, or a 409 error.
	 */
	public function rest_pre_insert( $prepared, $request = null ) {
		if ( ! is_object( $prepared ) || is_wp_error( $prepared ) || ! $request instanceof \WP_REST_Request || ! isset( $prepared->post_status ) ) {
			return $prepared;
		}

		$post_id  = (int) ( $prepared->ID ?? 0 );
		$old      = $post_id > 0 ? (string) get_post_status( $post_id ) : '';
		$incoming = (array) $prepared;

		if ( $request->has_param( 'featured_media' ) ) {
			$incoming['_thumbnail_id'] = (int) $request->get_param( 'featured_media' );
		}

		if ( $request->has_param( 'categories' ) ) {
			$incoming['post_category'] = array_map( 'intval', (array) $request->get_param( 'categories' ) );
		}

		if ( $request->has_param( 'tags' ) ) {
			$incoming['tax_input'] = array( 'post_tag' => (array) $request->get_param( 'tags' ) );
		}

		$gate   = $this->gate();
		$result = $gate->server_check( $incoming, $old, (string) $request->get_header( Gate::OVERRIDE_HEADER ) );

		if ( ! $result['applies'] ) {
			return $prepared;
		}

		if ( ! $result['allowed'] ) {
			return $gate->refusal( $result, $post_id );
		}

		$token                      = wp_generate_uuid4();
		$this->decided[ $token ]    = $result;
		$prepared->estasoft_nd_gate = $token;

		return $prepared;
	}

	/**
	 * `wp_insert_post_data`: keeps an article that fails the gate from going live.
	 *
	 * The data is slashed here, as WordPress passes it.
	 *
	 * @param mixed $data        What WordPress is about to write.
	 * @param mixed $postarr     What the caller passed.
	 * @param mixed $unsanitized What the caller passed, unsanitized.
	 * @param mixed $update      Whether it is an update of an existing post.
	 * @return mixed
	 */
	public function filter_post_data( $data, $postarr = array(), $unsanitized = array(), $update = false ) {
		// What an insert that failed left behind is no longer true.
		$this->pending = null;

		if ( ! is_array( $data ) || ! is_array( $postarr ) || ! in_array( (string) ( $data['post_status'] ?? '' ), array( 'publish', 'future' ), true ) ) {
			return $data;
		}

		$gate    = $this->gate();
		$post_id = (int) ( $postarr['ID'] ?? 0 );
		$status  = $post_id > 0 ? get_post_status( $post_id ) : false;
		$old     = false === $status ? '' : (string) $status;
		$token   = isset( $postarr['estasoft_nd_gate'] ) ? (string) $postarr['estasoft_nd_gate'] : '';

		if ( '' !== $token && isset( $this->decided[ $token ] ) ) {
			$result = $this->decided[ $token ];
			unset( $this->decided[ $token ] );
		} else {
			$result = $gate->server_check( wp_unslash( array_merge( $postarr, $data ) ), $old );
		}

		if ( ! $result['applies'] ) {
			return $data;
		}

		if ( $result['allowed'] ) {
			if ( $result['override'] ) {
				$this->pending = array(
					'post_id' => $post_id,
					'reason'  => (string) $result['reason'],
					'failing' => (array) $result['failing'],
					'user'    => get_current_user_id(),
				);
			}

			return $data;
		}

		$data['post_status'] = $gate->kept_status( $old );

		// A draft has no date of its own yet; the publish that did not happen must not give it one.
		$existing = $post_id > 0 ? get_post( $post_id ) : null;
		$floating = ! $existing instanceof \WP_Post || '0000-00-00 00:00:00' === $existing->post_date_gmt;

		if ( $floating && ( empty( $postarr['post_date_gmt'] ) || '0000-00-00 00:00:00' === $postarr['post_date_gmt'] ) ) {
			$data['post_date_gmt'] = '0000-00-00 00:00:00';
		}

		$messages = array();

		foreach ( (array) $result['findings'] as $finding ) {
			$messages[] = implode( ', ', (array) $finding['channels'] ) . ': ' . $finding['message'];
		}

		$gate->note_held( $result, $post_id );
		$gate->keep(
			array(
				'post_id'  => $post_id,
				'title'    => wp_strip_all_tags( wp_unslash( (string) ( $data['post_title'] ?? '' ) ) ),
				'failing'  => (array) $result['failing'],
				'messages' => $messages,
				'problem'  => (string) $result['problem'],
			)
		);

		return $data;
	}

	/**
	 * `transition_post_status`: the article went live past the gate, so the reason is written down, once.
	 *
	 * @param mixed $new_status The status now.
	 * @param mixed $old_status The status before.
	 * @param mixed $post       The article.
	 * @return void
	 */
	public function transitioned( $new_status, $old_status = '', $post = null ): void {
		$pending = $this->pending;

		if ( null === $pending || ! $post instanceof \WP_Post || ! in_array( $new_status, array( 'publish', 'future' ), true ) || in_array( $old_status, array( 'publish', 'future' ), true ) ) {
			return;
		}

		$same = (int) $pending['post_id'] > 0
			? (int) $post->ID === (int) $pending['post_id']
			: in_array( $old_status, array( 'new', 'auto-draft' ), true );

		if ( ! $same ) {
			return;
		}

		$this->pending = null;

		$this->gate()->record_publish_override( (int) $post->ID, (string) $pending['reason'], (array) $pending['failing'], (int) $pending['user'] );
	}

	/**
	 * Whether the open screen is one where a notice about publishing belongs: the article list and the editor, or a
	 * News Distribution screen. Never the Dashboard or another plugin's screen.
	 *
	 * @return bool
	 */
	private function on_publishing_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen instanceof \WP_Screen && in_array( $screen->base, array( 'edit', 'post' ), true ) ) {
			return true;
		}

		$page = Query::key( 'page' );

		return 0 === strpos( $page, 'estasoft-nd' );
	}

	/**
	 * `admin_notices`: what the server kept unpublished since the person last looked, and on the classic edit
	 * screen of an article that is on hold, a line above the title. Only on the article list, the editor and the
	 * plugin's own screens; a note that was kept waits for the next of those the person opens.
	 *
	 * @return void
	 */
	public function notices(): void {
		if ( ! $this->on_publishing_screen() ) {
			return;
		}

		$gate = $this->gate();
		$kept = $gate->take_kept();

		if ( array() !== $kept ) {
			wp_admin_notice(
				$this->kept_html( $kept ),
				array(
					'type'           => 'warning',
					'paragraph_wrap' => false,
				)
			);
		}

		if ( null !== $this->hold ) {
			$first = $this->hold['findings'][0] ?? null;

			wp_admin_notice(
				Html::tag(
					'p',
					array(),
					Html::tag( 'strong', array(), esc_html__( 'Publishing is on hold.', 'estasoft-news-distribution' ) )
					. ' '
					. esc_html( null === $first ? implode( ', ', (array) $this->hold['failing'] ) : implode( ', ', (array) $first['channels'] ) . ': ' . $first['message'] )
					. ' '
					. esc_html__( 'The News Distribution box has the details.', 'estasoft-news-distribution' )
				),
				array(
					'type'           => 'warning',
					'paragraph_wrap' => false,
				)
			);
		}
	}

	/**
	 * `add_meta_boxes`: the box that says publishing is on hold, in the classic editor only.
	 *
	 * @param mixed $post_type The post type.
	 * @param mixed $post      The article being edited.
	 * @return void
	 */
	public function add_box( $post_type = '', $post = null ): void {
		if ( ! $post instanceof \WP_Post || in_array( $post->post_status, array( 'publish', 'future' ), true ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen && $screen->is_block_editor() ) {
			return;
		}

		if ( ! in_array( $post->post_type, $this->plugin->settings()->post_types(), true ) || ! $this->plugin->access()->can_edit_article( (int) $post->ID ) ) {
			return;
		}

		$hold = $this->gate()->hold_for( (int) $post->ID );

		if ( null === $hold ) {
			return;
		}

		$this->hold = $hold;

		add_meta_box(
			self::BOX,
			__( 'News Distribution: publishing is on hold', 'estasoft-news-distribution' ),
			array( $this, 'render_box' ),
			$post->post_type,
			'side',
			'high',
			array( '__back_compat_meta_box' => true )
		);
	}

	/**
	 * The box: why, the reason field and the buttons.
	 *
	 * @return void
	 */
	public function render_box(): void {
		if ( null === $this->hold ) {
			return;
		}

		$gate  = $this->gate();
		$hold  = $this->hold;
		$can   = (bool) $hold['can_override'];
		$names = implode( ', ', (array) $hold['failing'] );
		$title = false;
		$lines = '';

		foreach ( (array) $hold['findings'] as $finding ) {
			$title  = $title || 0 === strpos( (string) $finding['code'], 'title.' );
			$lines .= Html::tag(
				'li',
				array(),
				Html::tag( 'strong', array(), esc_html( implode( ', ', (array) $finding['channels'] ) . ': ' ) ) . esc_html( (string) $finding['message'] )
			);
		}

		$html = Html::tag(
			'p',
			array(),
			esc_html(
				sprintf(
					$title
						/* translators: %s: channel names such as "Google News, Microsoft Start". */
						? __( 'An administrator requires %s to pass before this article goes out. Fix the title, or publish anyway if you are allowed to.', 'estasoft-news-distribution' )
						/* translators: %s: channel names such as "Google News, Microsoft Start". */
						: __( 'An administrator requires %s to pass before this article goes out. Fix the problem, or publish anyway if you are allowed to.', 'estasoft-news-distribution' ),
					$names
				)
			)
		) . Html::tag( 'ul', array( 'class' => 'ul-disc' ), $lines );

		if ( ! $can ) {
			$html .= Html::tag( 'p', array(), esc_html__( 'You cannot publish anyway. Fix the problem above, or ask an editor or administrator.', 'estasoft-news-distribution' ) );
			Output::show( $html );

			return;
		}

		$html .= Html::tag( 'p', array( 'class' => 'description' ), esc_html__( 'Your role can do this.', 'estasoft-news-distribution' ) )
			. Html::tag( 'p', array(), Html::tag( 'label', array( 'for' => self::BOX . '-reason' ), Html::tag( 'strong', array(), esc_html__( 'Reason', 'estasoft-news-distribution' ) ) ) )
			. Html::tag(
				'textarea',
				array(
					'id'        => self::BOX . '-reason',
					'name'      => Gate::OVERRIDE_FIELD,
					'class'     => 'large-text',
					'rows'      => 3,
					'maxlength' => Gate::MAX_REASON,
				),
				''
			)
			. Html::tag(
				'p',
				array( 'class' => 'description' ),
				$gate->reason_required()
					? esc_html(
						sprintf(
							/* translators: %d: the least number of characters. */
							__( 'Required, at least %d characters. It is kept in the Delivery log.', 'estasoft-news-distribution' ),
							Gate::MIN_REASON
						)
					)
					: esc_html__( 'Optional. It is kept in the Delivery log.', 'estasoft-news-distribution' )
			);

		$buttons = '';

		if ( $title ) {
			$buttons .= Html::tag(
				'a',
				array(
					'class' => 'button',
					'href'  => '#title',
				),
				esc_html__( 'Fix the title', 'estasoft-news-distribution' )
			) . ' ';
		}

		$buttons .= Html::tag(
			'button',
			array(
				'type'  => 'submit',
				'name'  => Gate::ANYWAY_FIELD,
				'value' => '1',
				'class' => 'button',
			),
			esc_html__( 'Publish anyway', 'estasoft-news-distribution' )
		);

		Output::show( $html . Html::tag( 'p', array(), $buttons ) );
	}

	/**
	 * `load-post.php`: the box's "Publish anyway" button posts its own name. WordPress publishes when the
	 * form carries `publish`, so the button is mapped to it. The form's nonce is still checked by post.php.
	 *
	 * @return void
	 */
	public function map_publish_anyway(): void {
		if ( ! isset( $_POST[ Gate::ANYWAY_FIELD ], $_POST['action'], $_POST['post_ID'], $_POST['_wpnonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only asks whether the editor's form was posted; the nonce is checked on the next line, before any value is read.
			return;
		}

		// The editor's own nonce for this article, the one post.php checks next. Without it nothing is changed here.
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'update-post_' . absint( wp_unslash( $_POST['post_ID'] ) ) ) ) {
			return;
		}

		if ( empty( $_POST[ Gate::ANYWAY_FIELD ] ) || 'editpost' !== sanitize_key( wp_unslash( $_POST['action'] ) ) || ! empty( $_POST['publish'] ) ) {
			return;
		}

		$_POST['publish'] = 'Publish'; // WordPress only looks for a value here; it does not show it.
	}

	/**
	 * The notice for articles the server kept unpublished.
	 *
	 * @param array<int, array{post_id: int, title: string, failing: string[], messages: string[], problem: string}> $kept The articles.
	 * @return string Escaped HTML.
	 */
	private function kept_html( array $kept ): string {
		$gate  = $this->gate();
		$count = count( $kept );

		if ( 1 === $count ) {
			$item = $kept[0];
			$text = $gate->refusal_text(
				array(
					'problem' => $item['problem'],
					'failing' => $item['failing'],
				),
				$gate->can_override( (int) $item['post_id'] )
			);

			return Html::tag(
				'p',
				array(),
				Html::tag(
					'strong',
					array(),
					esc_html(
						sprintf(
							/* translators: %s: the title of an article. */
							__( '“%s” was not published.', 'estasoft-news-distribution' ),
							$item['title']
						)
					)
				) . ' ' . esc_html( $text ) . ( array() === $item['messages'] ? '' : ' ' . esc_html( implode( ' ', $item['messages'] ) ) )
			);
		}

		$list = '';

		foreach ( $kept as $item ) {
			$list .= Html::tag( 'li', array(), esc_html( $item['title'] . ': ' . implode( ', ', $item['failing'] ) ) );
		}

		return Html::tag(
			'p',
			array(),
			Html::tag(
				'strong',
				array(),
				esc_html(
					sprintf(
						/* translators: %d: how many articles. */
						_n( '%d article was not published.', '%d articles were not published.', $count, 'estasoft-news-distribution' ),
						$count
					)
				)
			) . ' ' . esc_html__( 'They fail the checks you require, so each stays as it was. Fix the problems, or publish anyway with a reason if your role allows it.', 'estasoft-news-distribution' )
		) . Html::tag( 'ul', array( 'class' => 'ul-disc' ), $list );
	}

	/**
	 * The gate service.
	 *
	 * @return Gate
	 */
	private function gate(): Gate {
		return $this->plugin->make( Gate::class );
	}
}
