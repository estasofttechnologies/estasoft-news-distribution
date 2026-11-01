<?php
/**
 * News Distribution on the Posts list: bulk actions, a Quick Edit field and a row action.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Output;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Control\Bulk;
use Estasoft\NewsDistribution\Core\Hold;
use Estasoft\NewsDistribution\Core\Operations\Links;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Four things on the Posts list of every post type the site sends:
 *
 * - A "News Distribution" group in the Bulk actions menu: Check again, Send now, Hold partner feeds and
 *   Release hold. Each goes through `Core\Control\Bulk`, the same class `POST /articles/bulk` uses, so it
 *   does what the same action on one article does. A short selection is done before the page comes back;
 *   a longer one runs in the background and the notice at the top follows it (`GET /jobs/{id}`).
 * - A notice with the result of the run, with the posts that were skipped and why.
 * - A "Hold partner feeds" field in Quick Edit. It is applied when the person presses Update, by the
 *   same `Core\Hold` that the Release plan tab uses.
 * - A "Check article" row action that opens the article's check.
 *
 * It also adds a help tab to the screen that says what each action does and what it cannot do.
 *
 * No script is needed for the bulk actions or the row action. The script (`assets/js/screens/posts-bulk.js`)
 * asks for the reason when Hold is chosen, follows a running job and adds the Quick Edit behavior.
 *
 * Another feature can add fields to the Quick Edit box with `estasoft_nd_internal_quick_edit_fields`.
 * The action runs inside the News Distribution fieldset and should print the fields' own markup.
 */
final class PostsBulkModule implements ModuleInterface {

	/**
	 * The query argument that carries the job the notice follows.
	 */
	public const ARG = 'estasoft_nd_job';

	public const HANDLE = 'estasoft-nd-posts-bulk';

	/**
	 * The names of the Quick Edit fields.
	 */
	public const FIELD_HOLD   = 'estasoft_nd_hold';
	public const FIELD_REASON = 'estasoft_nd_hold_reason';
	public const FIELD_MARKER = 'estasoft_nd_qe';

	/**
	 * The hidden field a hold's reason travels in, from the bulk dialog.
	 */
	public const FIELD_BULK_REASON = 'estasoft_nd_reason';

	private const PREFIX = 'estasoft_nd_';

	private Plugin $plugin;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin The running plugin.
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		add_action( 'admin_init', array( $this, 'list_hooks' ) );
		add_filter( 'removable_query_args', array( $this, 'removable' ) );
		add_action( 'current_screen', array( $this, 'help' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_action( 'admin_footer', array( $this, 'dialog' ) );
		add_action( 'quick_edit_custom_box', array( $this, 'quick_edit' ), 10, 2 );
		add_action( 'add_inline_data', array( $this, 'inline_data' ), 10, 2 );
		add_action( 'save_post', array( $this, 'save_quick_edit' ), 30, 2 );
	}

	/**
	 * `admin_init`: the bulk actions and the row action, for each post type the site sends.
	 *
	 * @return void
	 */
	public function list_hooks(): void {
		foreach ( $this->plugin->settings()->post_types() as $type ) {
			add_filter( 'bulk_actions-edit-' . $type, array( $this, 'bulk_actions' ) );
			add_filter( 'handle_bulk_actions-edit-' . $type, array( $this, 'handle' ), 10, 3 );
		}

		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'row_actions' ), 10, 2 );
	}

	/**
	 * `removable_query_args`: WordPress takes the job out of the address after the page loads, so a
	 * later filter or page change does not show the same notice again.
	 *
	 * @param mixed $args Argument names.
	 * @return array<int, string>
	 */
	public function removable( $args ): array {
		$args   = is_array( $args ) ? $args : array();
		$args[] = self::ARG;

		return $args;
	}

	// ---------------------------------------------------------------------
	// Bulk actions.
	// ---------------------------------------------------------------------

	/**
	 * `bulk_actions-edit-{type}`: the News Distribution group.
	 *
	 * @param mixed $actions The menu: value => label, or label => array of those for a group.
	 * @return array<string, mixed>
	 */
	public function bulk_actions( $actions ): array {
		$actions = is_array( $actions ) ? $actions : array();
		$allowed = $this->plugin->make( Bulk::class )->allowed();

		if ( array() === $allowed ) {
			return $actions;
		}

		$group = array();

		foreach ( $allowed as $action => $label ) {
			$group[ self::PREFIX . $action ] = $label;
		}

		$actions[ __( 'News Distribution', 'estasoft-news-distribution' ) ] = $group;

		return $actions;
	}

	/**
	 * `handle_bulk_actions-edit-{type}`: starts the run and sends the person back to the list with the job.
	 *
	 * The list's nonce is checked here again, before the reason is read. `Core\Control\Bulk` checks who may do what,
	 * and skips each post the person may not change.
	 *
	 * @param mixed $redirect Where the list goes next.
	 * @param mixed $action   The chosen action.
	 * @param mixed $post_ids The selected posts.
	 * @return mixed
	 */
	public function handle( $redirect, $action = '', $post_ids = array() ) {
		$action = (string) $action;

		if ( 0 !== strpos( $action, self::PREFIX ) || ! is_string( $redirect ) ) {
			return $redirect;
		}

		$run = substr( $action, strlen( self::PREFIX ) );

		if ( ! array_key_exists( $run, Bulk::actions() ) ) {
			return $redirect;
		}

		// The list's own nonce, the one edit.php checked before it ran this filter. Without it nothing is started.
		if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ), 'bulk-posts' ) ) {
			return $redirect;
		}

		$reason = isset( $_REQUEST[ self::FIELD_BULK_REASON ] ) ? sanitize_textarea_field( wp_unslash( $_REQUEST[ self::FIELD_BULK_REASON ] ) ) : '';

		$started = $this->plugin->make( Bulk::class )->start( $run, array_map( 'absint', (array) $post_ids ), array( 'reason' => $reason ) );

		if ( is_wp_error( $started ) ) {
			// The notice reads a job, so the refusal becomes a job that failed, with the sentence the person should read.
			$jobs = $this->plugin->jobs();
			$id   = $jobs->start(
				'bulk-' . $run,
				0,
				array(
					'action' => $run,
					'label'  => Bulk::actions()[ $run ],
				)
			);
			$jobs->finish( $id, false, array(), $started->get_error_message() );

			return add_query_arg( self::ARG, $id, $redirect );
		}

		return add_query_arg( self::ARG, $started['job'], $redirect );
	}

	// ---------------------------------------------------------------------
	// The row action.
	// ---------------------------------------------------------------------

	/**
	 * `post_row_actions` and `page_row_actions`: "Check article".
	 *
	 * @param mixed $actions The row's actions.
	 * @param mixed $post    The row's post.
	 * @return array<string, string>
	 */
	public function row_actions( $actions, $post = null ): array {
		$actions = is_array( $actions ) ? $actions : array();

		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, $this->plugin->settings()->post_types(), true ) ) {
			return $actions;
		}

		if ( ! $this->plugin->access()->can( Access::VIEW_OWN, (int) $post->ID ) ) {
			return $actions;
		}

		$actions[ self::PREFIX . 'check' ] = sprintf(
			'<a href="%1$s" aria-label="%2$s">%3$s</a>',
			esc_url( Links::check( (int) $post->ID ) ),
			esc_attr(
				sprintf(
					/* translators: %s: the post's title. */
					__( 'Check article “%s”', 'estasoft-news-distribution' ),
					wp_strip_all_tags( get_the_title( $post ) )
				)
			),
			esc_html__( 'Check article', 'estasoft-news-distribution' )
		);

		return $actions;
	}

	// ---------------------------------------------------------------------
	// Quick Edit.
	// ---------------------------------------------------------------------

	/**
	 * `quick_edit_custom_box`: the News Distribution fieldset.
	 *
	 * WordPress fires this once for each custom column, so the fieldset is printed for the News Distribution
	 * column only, which is there for everyone who may open the list's column.
	 *
	 * @param mixed $column    The column key.
	 * @param mixed $post_type The post type.
	 * @return void
	 */
	public function quick_edit( $column, $post_type = '' ): void {
		if ( PostsColumnModule::COLUMN !== $column || ! in_array( (string) $post_type, $this->plugin->settings()->post_types(), true ) ) {
			return;
		}

		if ( ! $this->plugin->access()->can( Access::VIEW ) ) {
			return;
		}

		ob_start();

		/**
		 * Another News Distribution feature adds its Quick Edit fields here, inside the fieldset.
		 *
		 * @param string $post_type The post type being edited.
		 */
		do_action( 'estasoft_nd_internal_quick_edit_fields', (string) $post_type );

		$extra = (string) ob_get_clean();

		$hold = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-qe__hold' ),
			Html::tag(
				'label',
				array( 'class' => 'estasoft-nd-qe__check' ),
				Html::void(
					'input',
					array(
						'type'  => 'checkbox',
						'name'  => self::FIELD_HOLD,
						'value' => '1',
					)
				)
				. Html::tag( 'span', array( 'class' => 'checkbox-title' ), esc_html__( 'Hold partner feeds', 'estasoft-news-distribution' ) )
			)
			. Html::tag(
				'label',
				array(
					'class'  => 'estasoft-nd-qe__reason',
					'hidden' => true,
				),
				Html::tag( 'span', array( 'class' => 'title' ), esc_html__( 'Why', 'estasoft-news-distribution' ) )
				. Html::tag(
					'span',
					array( 'class' => 'input-text-wrap' ),
					Html::void(
						'input',
						array(
							'type'        => 'text',
							'name'        => self::FIELD_REASON,
							'maxlength'   => (string) Hold::REASON_MAX,
							'placeholder' => __( 'For the delivery log', 'estasoft-news-distribution' ),
						)
					)
				)
			)
			. Html::tag(
				'p',
				array(
					'class'  => 'estasoft-nd-qe__error',
					'role'   => 'alert',
					'hidden' => true,
				),
				esc_html__( 'Write why you are holding the article.', 'estasoft-news-distribution' )
			)
			. Html::tag( 'p', array( 'class' => 'description' ), esc_html__( 'Applies when you press Update. Holding keeps the article out of partner feeds, feed readers and pushes until you release it.', 'estasoft-news-distribution' ) )
		);

		Output::show(
			Html::tag(
				'fieldset',
				array( 'class' => 'estasoft-nd-qe' ),
				Html::tag( 'legend', array( 'class' => 'inline-edit-legend' ), esc_html__( 'News Distribution', 'estasoft-news-distribution' ) )
				. Html::tag(
					'div',
					array( 'class' => 'inline-edit-col' ),
					( '' === trim( $extra ) ? '' : Html::tag( 'div', array( 'class' => 'estasoft-nd-qe__extra' ), $extra ) )
					. $hold
					. Html::void(
						'input',
						array(
							'type'  => 'hidden',
							'name'  => self::FIELD_MARKER,
							'value' => '1',
						)
					)
				)
			)
		);
	}

	/**
	 * `add_inline_data`: what Quick Edit needs to know about one row, in the row's hidden data.
	 *
	 * @param mixed $post      The row's post.
	 * @param mixed $post_type The post type object.
	 * @return void
	 */
	public function inline_data( $post, $post_type = null ): void {
		unset( $post_type );

		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, $this->plugin->settings()->post_types(), true ) ) {
			return;
		}

		$state  = 'na';
		$reason = '';

		if ( in_array( $post->post_status, array( 'publish', 'future' ), true ) && $this->plugin->access()->can( Access::VIEW, (int) $post->ID ) ) {
			$info   = $this->plugin->make( Hold::class )->info( (int) $post->ID );
			$state  = null === $info ? 'free' : 'held';
			$reason = null === $info ? '' : (string) $info['reason'];
		}

		Output::show(
			Html::tag( 'div', array( 'class' => 'estasoft_nd_hold_state' ), esc_html( $state ) )
			. Html::tag( 'div', array( 'class' => 'estasoft_nd_hold_reason' ), esc_html( $reason ) )
		);
	}

	/**
	 * `save_post`: applies the Quick Edit hold field when the person presses Update.
	 *
	 * Only the Quick Edit save is read: it carries WordPress's own inline-edit nonce and this module's marker
	 * field. The person must be allowed to hold the article, the same rule as the Release plan tab.
	 *
	 * @param mixed $post_id The saved post.
	 * @param mixed $post    The saved post.
	 * @return void
	 */
	public function save_quick_edit( $post_id, $post = null ): void {
		$post_id = (int) $post_id;

		if ( ! $post instanceof \WP_Post || ! wp_doing_ajax() || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! in_array( $post->post_type, $this->plugin->settings()->post_types(), true ) ) {
			return;
		}

		// WordPress's own Quick Edit nonce. Without it nothing below is read.
		if ( ! isset( $_POST['_inline_edit'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_inline_edit'] ) ), 'inlineeditnonce' ) ) {
			return;
		}

		// Only a Quick Edit that carries this module's marker is read.
		if ( ! isset( $_POST[ self::FIELD_MARKER ] ) ) {
			return;
		}

		if ( ! $this->plugin->access()->can( Access::VIEW, $post_id ) || ! in_array( $post->post_status, array( 'publish', 'future' ), true ) ) {
			return;
		}

		$hold    = $this->plugin->make( Hold::class );
		$wanted  = ! empty( $_POST[ self::FIELD_HOLD ] );
		$reason  = isset( $_POST[ self::FIELD_REASON ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ self::FIELD_REASON ] ) ) : '';
		$is_held = $hold->is_held( $post_id );

		if ( $wanted && ! $is_held ) {
			$why = trim( $reason );

			if ( '' === $why ) {
				$why = __( 'Held in Quick Edit.', 'estasoft-news-distribution' );
			}

			$hold->hold( $post_id, $why );
		} elseif ( ! $wanted && $is_held ) {
			$hold->lift( $post_id );
		}
	}

	// ---------------------------------------------------------------------
	// The notice.
	// ---------------------------------------------------------------------

	/**
	 * `admin_notices`: the result of a run, or its progress.
	 *
	 * @return void
	 */
	public function notice(): void {
		if ( ! $this->on_list() ) {
			return;
		}

		$id = Query::key( self::ARG );

		if ( '' === $id ) {
			return;
		}

		$job = $this->plugin->jobs()->get( $id );

		if ( null === $job || get_current_user_id() !== (int) $job['user'] || 0 !== strpos( (string) $job['type'], 'bulk-' ) ) {
			Output::show(
				Html::tag(
					'div',
					array( 'class' => 'notice notice-warning estasoft-nd-bulk-notice' ),
					Html::tag( 'div', array( 'class' => 'estasoft-nd-bulk-notice__body' ), Html::tag( 'p', array(), esc_html__( 'That task is no longer listed. Run the action again if you still need it.', 'estasoft-news-distribution' ) ) )
					. $this->dismiss()
				)
			);

			return;
		}

		Output::show( $this->notice_html( $job ) );
	}

	/**
	 * The notice for one job.
	 *
	 * @param array<string, mixed> $job The job.
	 * @return string
	 */
	public function notice_html( array $job ): string {
		$state   = (string) $job['state'];
		$result  = (array) $job['result'];
		$skipped = array_values( (array) ( $result['skipped'] ?? array() ) );
		$meta    = (array) $job['meta'];
		$action  = (string) ( $meta['action'] ?? '' );
		$running = in_array( $state, array( 'queued', 'running' ), true );

		if ( 'failed' === $state ) {
			$tone = 'error';
		} elseif ( $running ) {
			$tone = 'info';
		} else {
			$tone = array() === $skipped ? 'success' : 'warning';
		}

		$attrs = array(
			'class'      => 'notice notice-' . $tone . ' estasoft-nd-bulk-notice',
			'data-job'   => (string) $job['id'],
			'data-state' => $state,
			'data-done'  => (string) (int) $job['done'],
			'data-total' => (string) (int) $job['total'],
			'data-url'   => esc_url_raw( add_query_arg( self::ARG, (string) $job['id'] ) ),
		);

		if ( $running ) {
			$label = (string) ( $meta['label'] ?? '' );

			$body = Html::tag(
				'p',
				array(
					'role'      => 'status',
					'class'     => 'estasoft-nd-bulk-notice__line',
					'aria-live' => 'polite',
				),
				Html::tag(
					'strong',
					array(),
					esc_html(
						sprintf(
							/* translators: 1: the name of the action such as "Check again", 2: how many articles. */
							_n( 'Running “%1$s” on %2$d article.', 'Running “%1$s” on %2$d articles.', (int) $job['total'], 'estasoft-news-distribution' ),
							$label,
							(int) $job['total']
						)
					)
				)
				. ' '
				. Html::tag(
					'span',
					array( 'class' => 'estasoft-nd-bulk-notice__progress' ),
					esc_html(
						sprintf(
							/* translators: 1: how many are done, 2: how many in all. */
							__( '%1$d of %2$d done.', 'estasoft-news-distribution' ),
							(int) $job['done'],
							(int) $job['total']
						)
					)
				)
			)
			. Html::tag(
				'progress',
				array(
					'class' => 'estasoft-nd-bulk-notice__bar',
					'max'   => '100',
					'value' => (string) (int) $job['percent'],
				)
			)
			. Html::tag( 'p', array( 'class' => 'description' ), esc_html__( 'The posts are handled in the background, 20 at a time. This notice updates when the run ends.', 'estasoft-news-distribution' ) );

			return Html::tag( 'div', $attrs, Html::tag( 'div', array( 'class' => 'estasoft-nd-bulk-notice__body' ), $body ) );
		}

		$message = trim( (string) $job['message'] );
		$lead    = $message;
		$rest    = '';

		if ( 1 === preg_match( '/^(.+?[.!?])(\s+.*)?$/su', $message, $parts ) ) {
			$lead = $parts[1];
			$rest = isset( $parts[2] ) ? $parts[2] : '';
		}

		$body = Html::tag( 'p', array( 'class' => 'estasoft-nd-bulk-notice__line' ), Html::tag( 'strong', array(), esc_html( $lead ) ) . esc_html( $rest ) );

		if ( array() !== $skipped ) {
			$items = '';

			foreach ( $skipped as $one ) {
				$items .= Html::tag(
					'li',
					array(),
					Html::tag( 'strong', array(), esc_html( '' === (string) $one['title'] ? '#' . (int) $one['id'] : (string) $one['title'] ) )
					. esc_html( ': ' . (string) $one['reason'] )
				);
			}

			$body .= Html::tag( 'ul', array( 'class' => 'estasoft-nd-bulk-notice__skipped' ), $items );
		}

		$buttons = '';

		if ( 'done' === $state && Bulk::CHECK === $action ) {
			$buttons = Html::tag(
				'a',
				array(
					'class' => 'button',
					'href'  => Links::check(),
				),
				esc_html__( 'Open Article check', 'estasoft-news-distribution' )
			);
		} elseif ( 'done' === $state && Bulk::SEND === $action ) {
			$buttons = Html::tag(
				'a',
				array(
					'class' => 'button',
					'href'  => Links::delivery_log(),
				),
				esc_html__( 'Open the delivery log', 'estasoft-news-distribution' )
			);
		}

		return Html::tag(
			'div',
			$attrs,
			Html::tag( 'div', array( 'class' => 'estasoft-nd-bulk-notice__body' ), $body ) . $this->dismiss( $buttons )
		);
	}

	/**
	 * The buttons at the end of a notice.
	 *
	 * @param string $before Markup of buttons that come before Dismiss.
	 * @return string
	 */
	private function dismiss( string $before = '' ): string {
		return Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-bulk-notice__actions' ),
			$before . Html::tag(
				'a',
				array(
					'class' => 'estasoft-nd-bulk-notice__dismiss',
					'href'  => remove_query_arg( self::ARG ),
				),
				esc_html__( 'Dismiss', 'estasoft-news-distribution' )
			)
		);
	}

	// ---------------------------------------------------------------------
	// The reason for a hold, asked in a dialog.
	// ---------------------------------------------------------------------

	/**
	 * `admin_footer`: the dialog that asks why, when Hold partner feeds is chosen.
	 *
	 * @return void
	 */
	public function dialog(): void {
		if ( ! $this->on_list() || ! array_key_exists( Bulk::HOLD, $this->plugin->make( Bulk::class )->allowed() ) ) {
			return;
		}

		Output::show(
			Html::tag(
				'div',
				array(
					'class'           => 'estasoft-nd-bulk-dialog',
					'id'              => 'estasoft-nd-hold-dialog',
					'role'            => 'dialog',
					'aria-modal'      => 'true',
					'aria-labelledby' => 'estasoft-nd-hold-dialog-title',
					'hidden'          => true,
				),
				Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-bulk-dialog__panel' ),
					Html::tag( 'h2', array( 'id' => 'estasoft-nd-hold-dialog-title' ), esc_html__( 'Hold partner feeds', 'estasoft-news-distribution' ) )
					. Html::tag( 'p', array(), esc_html__( 'While an article is on hold, partner feeds, feed readers and pushes do not carry it. Your own site, the search sitemaps and Google News are not held.', 'estasoft-news-distribution' ) )
					. Html::tag(
						'p',
						array(),
						Html::tag( 'label', array( 'for' => 'estasoft-nd-hold-dialog-reason' ), esc_html__( 'Why are you holding them? (required)', 'estasoft-news-distribution' ) )
						. Html::void(
							'input',
							array(
								'type'        => 'text',
								'class'       => 'widefat',
								'id'          => 'estasoft-nd-hold-dialog-reason',
								'maxlength'   => (string) Hold::REASON_MAX,
								'placeholder' => __( 'For the delivery log', 'estasoft-news-distribution' ),
							)
						)
					)
					. Html::tag(
						'p',
						array(
							'class'  => 'estasoft-nd-bulk-dialog__error',
							'role'   => 'alert',
							'hidden' => true,
						),
						esc_html__( 'Write why you are holding the articles.', 'estasoft-news-distribution' )
					)
					. Html::tag(
						'p',
						array( 'class' => 'estasoft-nd-bulk-dialog__actions' ),
						Html::tag(
							'button',
							array(
								'type'                => 'button',
								'class'               => 'button button-primary',
								'data-estasoft-nd-ok' => '1',
							),
							esc_html__( 'Hold articles', 'estasoft-news-distribution' )
						)
						. Html::tag(
							'button',
							array(
								'type'                    => 'button',
								'class'                   => 'button',
								'data-estasoft-nd-cancel' => '1',
							),
							esc_html__( 'Cancel', 'estasoft-news-distribution' )
						)
					)
				)
			)
		);
	}

	// ---------------------------------------------------------------------
	// Help, scripts and styles.
	// ---------------------------------------------------------------------

	/**
	 * `current_screen`: a help tab that says what each action does.
	 *
	 * @param mixed $screen The screen.
	 * @return void
	 */
	public function help( $screen = null ): void {
		if ( ! $screen instanceof \WP_Screen || ! $this->on_list( $screen ) ) {
			return;
		}

		$rows = array(
			array( __( 'Check again', 'estasoft-news-distribution' ), __( 'Runs the article check again with today’s rules. Nothing is sent.', 'estasoft-news-distribution' ), __( 'Published, scheduled and draft posts', 'estasoft-news-distribution' ), __( 'Nothing to undo.', 'estasoft-news-distribution' ) ),
			array( __( 'Send now, skip the delay', 'estasoft-news-distribution' ), __( 'Sends the pushes that are waiting now, instead of after their delay.', 'estasoft-news-distribution' ), __( 'Published posts that show Waiting', 'estasoft-news-distribution' ), __( 'Cannot be undone. A push that was sent stays sent.', 'estasoft-news-distribution' ) ),
			array( __( 'Hold partner feeds', 'estasoft-news-distribution' ), __( 'Keeps the article out of partner feeds, feed readers and pushes until you release it. Your own site, the search sitemaps and Google News are not held.', 'estasoft-news-distribution' ), __( 'Published and scheduled posts', 'estasoft-news-distribution' ), __( 'Release hold', 'estasoft-news-distribution' ) ),
			array( __( 'Release hold', 'estasoft-news-distribution' ), __( 'Puts the article back into partner feeds and queues what the hold kept back. Platforms pick it up on their next fetch.', 'estasoft-news-distribution' ), __( 'Posts on hold', 'estasoft-news-distribution' ), __( 'Hold partner feeds', 'estasoft-news-distribution' ) ),
		);

		// Not the class "widefat": Quick Edit counts the columns of the first such table on the page, and this one is hidden.
		$table = '<table class="estasoft-nd-help-table"><thead><tr><th scope="col">' . esc_html__( 'Action', 'estasoft-news-distribution' ) . '</th><th scope="col">' . esc_html__( 'What it does', 'estasoft-news-distribution' ) . '</th><th scope="col">' . esc_html__( 'Works on', 'estasoft-news-distribution' ) . '</th><th scope="col">' . esc_html__( 'Undo', 'estasoft-news-distribution' ) . '</th></tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$table .= '<tr><th scope="row">' . esc_html( $row[0] ) . '</th><td>' . esc_html( $row[1] ) . '</td><td>' . esc_html( $row[2] ) . '</td><td>' . esc_html( $row[3] ) . '</td></tr>';
		}

		$table .= '</tbody></table>';

		$content = '<h3>' . esc_html__( 'What each action does', 'estasoft-news-distribution' ) . '</h3>'
			. '<p>' . esc_html__( 'These actions are in the News Distribution group of the Bulk actions menu. They show for people who can edit the posts they select.', 'estasoft-news-distribution' ) . '</p>'
			. $table
			. '<h3>' . esc_html__( 'How a run works', 'estasoft-news-distribution' ) . '</h3><ul>'
			. '<li>' . esc_html__( 'A few posts are done before the page comes back. A longer selection runs in the background, 20 posts at a time, and a notice follows it.', 'estasoft-news-distribution' ) . '</li>'
			. '<li>' . esc_html__( 'If a post cannot be changed, such as a draft that you ask to hold, it is skipped and the notice says why.', 'estasoft-news-distribution' ) . '</li>'
			. '<li>' . esc_html__( 'Every hold, release and send is recorded in the delivery log with your name.', 'estasoft-news-distribution' ) . '</li></ul>'
			. '<h3>' . esc_html__( 'What it cannot do', 'estasoft-news-distribution' ) . '</h3><ul>'
			. '<li>' . esc_html__( 'Make a platform fetch sooner. Send now only moves the pushes.', 'estasoft-news-distribution' ) . '</li>'
			. '<li>' . esc_html(
				sprintf(
					/* translators: %d: the most posts one run can take. */
					__( 'Run on more than %d posts at once. Select fewer.', 'estasoft-news-distribution' ),
					Bulk::MAX
				)
			) . '</li>'
			. '<li>' . esc_html__( 'Change an article’s text. Only the hold is changed, and Quick Edit applies it when you press Update.', 'estasoft-news-distribution' ) . '</li></ul>';

		$screen->add_help_tab(
			array(
				'id'      => 'estasoft-nd-bulk',
				'title'   => __( 'News Distribution', 'estasoft-news-distribution' ),
				'content' => $content,
			)
		);
	}

	/**
	 * `admin_enqueue_scripts`: the list's style and script.
	 *
	 * @param mixed $hook The screen's hook suffix.
	 * @return void
	 */
	public function enqueue( $hook = '' ): void {
		if ( 'edit.php' !== $hook || ! $this->on_list() ) {
			return;
		}

		wp_enqueue_style(
			self::HANDLE,
			ESTASOFT_ND_URL . 'assets/css/screens/posts-bulk.css',
			array(),
			Assets::file_version( 'assets/css/screens/posts-bulk.css' )
		);

		wp_enqueue_script(
			self::HANDLE,
			ESTASOFT_ND_URL . 'assets/js/screens/posts-bulk.js',
			array( 'inline-edit-post' ),
			Assets::file_version( 'assets/js/screens/posts-bulk.js' ),
			array( 'in_footer' => true )
		);

		wp_add_inline_script(
			self::HANDLE,
			'window.estasoftNDScreens = window.estasoftNDScreens || {}; window.estasoftNDScreens["posts-bulk"] = ' . wp_json_encode(
				array(
					'rest'  => esc_url_raw( rest_url( 'estasoft-nd/v1/' ) ),
					'nonce' => wp_create_nonce( 'wp_rest' ),
					'hold'  => self::PREFIX . Bulk::HOLD,
					'field' => self::FIELD_BULK_REASON,
					'text'  => array(
						/* translators: 1: how many are done, 2: how many in all. */
						'progress' => __( '%1$d of %2$d done.', 'estasoft-news-distribution' ),
						'still'    => __( 'This is taking longer than usual. Reload the page in a moment to see the result.', 'estasoft-news-distribution' ),
						'lost'     => __( 'Could not reach your site to follow the run. Reload the page to see the result.', 'estasoft-news-distribution' ),
					),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Whether this is the Posts list of a post type the site sends, for a person who sees News Distribution.
	 *
	 * @param \WP_Screen|null $screen The screen, or the current one.
	 * @return bool
	 */
	private function on_list( ?\WP_Screen $screen = null ): bool {
		$screen = null === $screen && function_exists( 'get_current_screen' ) ? get_current_screen() : $screen;

		if ( ! $screen instanceof \WP_Screen || 'edit' !== $screen->base ) {
			return false;
		}

		return in_array( $screen->post_type, $this->plugin->settings()->post_types(), true ) && $this->plugin->access()->can( Access::VIEW_OWN );
	}
}
