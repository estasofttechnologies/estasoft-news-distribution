<?php
/**
 * The News Distribution box in the classic editor.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Check\ArticleOptions;
use Estasoft\NewsDistribution\Admin\Check\Context;
use Estasoft\NewsDistribution\Admin\Check\Findings;
use Estasoft\NewsDistribution\Admin\Check\Links;
use Estasoft\NewsDistribution\Admin\Check\PanelState;
use Estasoft\NewsDistribution\Admin\Check\Times;
use Estasoft\NewsDistribution\Admin\Output;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Channels\ChannelInterface;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\ArticleChoices;
use Estasoft\NewsDistribution\Core\ArticleService;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * The same check and the same per-article choices as the block editor panel, in a meta box.
 *
 * What differs is when and where it shows, not what it says. The classic editor has no live data for
 * News Distribution to read, so the box shows the stored check: it is run when the post is saved and when
 * a person presses Check now. Check now is a plain form post to `admin-post.php`, with a nonce and a check
 * that the person can edit the article, so it works without any script. The form sits in the page footer
 * and the button points at it with the `form` attribute, because a form cannot be nested in the post form.
 * The check reads the saved article, so unsaved edits are not part of it.
 *
 * The choices (content label, breaking news, minor edit) are saved with the post, on `save_post`, behind
 * their own nonce and the same edit check. They are written to `_estasoft_nd_options`, which
 * `EditorPanelModule` registers; minor edit is used up by `EditorPanelModule::consume_minor_edit()`
 * once the save is done, exactly as in the block editor.
 *
 * Another feature adds a field to this box with two internal actions:
 *
 * - `estasoft_nd_internal_classic_box( $post, $options )`: print the field (escape it) between the choices
 *   and the link to Article check.
 * - `estasoft_nd_internal_classic_box_save( $post_id, $post )`: save it. Runs only after the nonce and the
 *   edit check passed, so the callback needs neither.
 */
final class ClassicBoxModule implements ModuleInterface {

	public const BOX = 'estasoft-nd-classic-box';

	public const SAVE_NONCE = 'estasoft_nd_classic_nonce';

	public const CHECK_NONCE = 'estasoft_nd_classic_check_nonce';

	public const ACTION = 'estasoft_nd_classic_check';

	public const FIELDS = 'estasoft_nd_classic';

	public const RESULT = 'estasoft_nd_checked';

	public const FORM = 'estasoft-nd-classic-check';

	private const SHOWN = 4;

	private Plugin $plugin;

	/**
	 * The article whose box was printed in this request; the footer prints its Check now form.
	 *
	 * @var int
	 */
	private int $printed = 0;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		add_action( 'add_meta_boxes', array( $this, 'add_box' ), 10, 2 );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'check_now' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_footer', array( $this, 'print_form' ) );
		add_filter( 'removable_query_args', array( $this, 'removable' ) );
	}

	/**
	 * `add_meta_boxes`: the box, on the edit screen of a post type the site sends.
	 *
	 * @param string   $post_type The post type.
	 * @param \WP_Post $post      The post being edited.
	 * @return void
	 */
	public function add_box( $post_type = '', $post = null ): void {
		if ( ! $post instanceof \WP_Post || ! $this->applies( $post ) ) {
			return;
		}

		add_meta_box(
			self::BOX,
			__( 'News Distribution', 'estasoft-news-distribution' ),
			array( $this, 'render' ),
			$post->post_type,
			'side',
			'default',
			array( '__back_compat_meta_box' => true )
		);
	}

	/**
	 * `admin_enqueue_scripts`: the box's style and script, on the classic edit screen only.
	 *
	 * @param string $hook The admin page.
	 * @return void
	 */
	public function enqueue( $hook = '' ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$post = get_post();

		if ( ! $post instanceof \WP_Post || ! $this->applies( $post ) ) {
			return;
		}

		wp_enqueue_style( self::BOX, ESTASOFT_ND_URL . 'assets/css/editor-classic.css', array(), Assets::file_version( 'assets/css/editor-classic.css' ) );

		wp_enqueue_script(
			self::BOX,
			ESTASOFT_ND_URL . 'assets/js/editor/classic-box.js',
			array(),
			Assets::file_version( 'assets/js/editor/classic-box.js' ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * `removable_query_args`: the flag a finished check leaves in the address is taken out by WordPress,
	 * so reloading the screen does not show the message again.
	 *
	 * @param mixed $args Query arguments WordPress removes from the address.
	 * @return mixed
	 */
	public function removable( $args ) {
		if ( is_array( $args ) ) {
			$args[] = self::RESULT;
		}

		return $args;
	}

	/**
	 * Whether the box belongs on this post: the classic editor, a type the site sends, a person who can edit it.
	 *
	 * @param \WP_Post $post The post.
	 * @return bool
	 */
	private function applies( \WP_Post $post ): bool {
		if ( ! in_array( $post->post_type, $this->plugin->settings()->post_types(), true ) ) {
			return false;
		}

		if ( function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $post ) ) {
			return false;
		}

		return $this->plugin->access()->can_edit_article( (int) $post->ID );
	}

	/**
	 * The box.
	 *
	 * @param \WP_Post $post The post being edited.
	 * @return void
	 */
	public function render( $post = null ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$this->printed = (int) $post->ID;

		$ctx     = new Context( $this->plugin, (int) $post->ID );
		$options = ArticleOptions::get( (int) $post->ID );

		echo '<div class="estasoft-nd-cb">';

		wp_nonce_field( 'estasoft_nd_classic_save_' . $post->ID, self::SAVE_NONCE, false );

		$this->state( $ctx );
		$this->check_row( $ctx );

		echo '<p class="estasoft-nd-cb__note">' . esc_html__( 'In this editor the check runs when you save the draft or press Check now. It does not update while you type.', 'estasoft-news-distribution' ) . '</p>';

		$this->choices( $post, $options );
		$this->left_out( $post );

		/**
		 * Lets a feature print a field in the classic editor box.
		 *
		 * Internal. Print escaped markup; save it with `estasoft_nd_internal_classic_box_save`.
		 *
		 * @param \WP_Post             $post    The post being edited.
		 * @param array<string, mixed> $options The per-article choices as stored.
		 */
		do_action( 'estasoft_nd_internal_classic_box', $post, $options );

		$url = $this->plugin->access()->can( Access::VIEW_OWN, (int) $post->ID ) ? ( new Links( $this->plugin ) )->page( 'estasoft-nd-check', array( 'post' => (int) $post->ID ) ) : '';

		if ( '' !== $url ) {
			echo '<p class="estasoft-nd-cb__more"><a href="' . esc_url( $url ) . '">' . esc_html__( 'Open Article check', 'estasoft-news-distribution' ) . '</a></p>';
		}

		echo '</div>';
	}

	/**
	 * What the check found: the count, then each thing to fix, or one green line when there is nothing.
	 *
	 * @param Context $ctx The article.
	 * @return void
	 */
	private function state( Context $ctx ): void {
		$todo = array();
		$tips = 0;

		foreach ( Findings::sorted( $ctx->findings() ) as $finding ) {
			if ( Status::INFO === ( $finding['severity'] ?? '' ) ) {
				++$tips;
			} else {
				$todo[] = array(
					'severity' => (string) ( $finding['severity'] ?? Status::WARN ),
					'message'  => (string) ( $finding['message'] ?? '' ),
				);
			}
		}

		foreach ( PanelState::missing_translations( $this->plugin, $ctx->id() ) as $name ) {
			$todo[] = array(
				'severity' => Status::WARN,
				'message'  => sprintf(
					/* translators: %s: a language name. */
					__( '%s translation is missing', 'estasoft-news-distribution' ),
					$name
				),
			);
		}

		echo '<div class="estasoft-nd-cb__state" aria-live="polite">';

		if ( ! $ctx->was_checked() ) {
			echo '<p class="estasoft-nd-cb__head estasoft-nd-cb__head--muted">' . esc_html__( 'Not checked yet', 'estasoft-news-distribution' ) . '</p>';
		} elseif ( array() === $todo ) {
			echo '<p class="estasoft-nd-cb__head estasoft-nd-cb__head--ok">';
			Output::show( Icon::svg( 'check' ) );
			echo '<span>' . esc_html__( 'Nothing to fix', 'estasoft-news-distribution' ) . '</span></p>';
		} else {
			echo '<p class="estasoft-nd-cb__head estasoft-nd-cb__head--warn">';
			Output::show( Icon::svg( 'warning' ) );
			echo '<span>' . esc_html(
				sprintf(
					/* translators: %d: a number of findings. */
					_n( '%d thing to fix', '%d things to fix', count( $todo ), 'estasoft-news-distribution' ),
					count( $todo )
				)
			) . '</span></p>';

			$this->list( $todo );
		}

		if ( $ctx->was_checked() && $tips > 0 ) {
			echo '<p class="estasoft-nd-cb__muted">' . esc_html(
				sprintf(
					/* translators: %d: a number of tips. */
					_n( '%d tip, in Article check', '%d tips, in Article check', $tips, 'estasoft-news-distribution' ),
					$tips
				)
			) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * The things to fix, the first few, each with its severity icon.
	 *
	 * @param array<int, array{severity: string, message: string}> $todo What to fix.
	 * @return void
	 */
	private function list( array $todo ): void {
		echo '<ul class="estasoft-nd-cb__list">';

		foreach ( array_slice( $todo, 0, self::SHOWN ) as $item ) {
			$info = Status::describe( $item['severity'] );

			echo '<li class="estasoft-nd-cb__item estasoft-nd-cb__item--' . esc_attr( $info['tone'] ) . '">';
			Output::show( Icon::svg( $info['icon'] ) );
			echo '<span>' . esc_html( $item['message'] ) . '</span></li>';
		}

		echo '</ul>';

		if ( count( $todo ) > self::SHOWN ) {
			echo '<p class="estasoft-nd-cb__muted">' . esc_html(
				sprintf(
					/* translators: %d: a number of things not listed here. */
					_n( 'And %d more, in Article check', 'And %d more, in Article check', count( $todo ) - self::SHOWN, 'estasoft-news-distribution' ),
					count( $todo ) - self::SHOWN
				)
			) . '</p>';
		}
	}

	/**
	 * The Check now button, when the stored check was made, and what a check just run said.
	 *
	 * @param Context $ctx The article.
	 * @return void
	 */
	private function check_row( Context $ctx ): void {
		$result = Query::key( self::RESULT );

		echo '<p class="estasoft-nd-cb__row">';
		printf(
			'<button type="submit" class="button" form="%1$s" data-checking="%2$s">%3$s</button>',
			esc_attr( self::FORM ),
			esc_attr__( 'Checking…', 'estasoft-news-distribution' ),
			esc_html__( 'Check now', 'estasoft-news-distribution' )
		);

		if ( $ctx->was_checked() ) {
			echo '<span class="estasoft-nd-cb__muted">' . esc_html(
				sprintf(
					/* translators: %s: a time of day such as 14:28. */
					__( 'Last checked %s', 'estasoft-news-distribution' ),
					Times::at( $ctx->checked_at() )
				)
			) . '</span>';
		}

		echo '</p>';

		echo '<p class="estasoft-nd-cb__status" role="status" data-status="1">';

		if ( 'failed' === $result ) {
			echo '<span class="estasoft-nd-cb__error">' . esc_html(
				$ctx->was_checked()
					? __( 'The check could not run. This is the last result.', 'estasoft-news-distribution' )
					: __( 'The check could not run.', 'estasoft-news-distribution' )
			) . '</span>';
		} elseif ( 'done' === $result ) {
			echo esc_html__( 'Checked just now.', 'estasoft-news-distribution' );
		}

		echo '</p>';
	}

	/**
	 * The per-article choices.
	 *
	 * @param \WP_Post             $post    The post.
	 * @param array<string, mixed> $options Stored choices.
	 * @return void
	 */
	private function choices( \WP_Post $post, array $options ): void {
		$labels  = ArticleOptions::content_labels();
		$current = (string) $options['content_label'];

		if ( ! isset( $labels[ $current ] ) ) {
			// Another feature stored a word of its own; keep it selectable so a save does not change it.
			$labels[ $current ] = $current;
		}

		echo '<input type="hidden" name="' . esc_attr( self::FIELDS ) . '[present]" value="1" />';

		echo '<p class="estasoft-nd-cb__field"><label for="estasoft-nd-cb-label"><strong>' . esc_html__( 'Content label', 'estasoft-news-distribution' ) . '</strong></label>';
		echo '<select id="estasoft-nd-cb-label" name="' . esc_attr( self::FIELDS ) . '[content_label]">';

		foreach ( $labels as $value => $words ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( (string) $value ),
				selected( $current, (string) $value, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selected() returns a fixed attribute.
				esc_html( (string) $words )
			);
		}

		echo '</select></p>';

		$this->checkbox( 'breaking', ! empty( $options['breaking'] ), __( 'Breaking news', 'estasoft-news-distribution' ), __( 'Skip the delay and send now.', 'estasoft-news-distribution' ) );
		$this->checkbox( 'minor_edit', ! empty( $options['minor_edit'] ), __( 'Minor edit', 'estasoft-news-distribution' ), __( 'Do not send pushes for this change.', 'estasoft-news-distribution' ) );
	}

	/**
	 * The channels the block editor panel switched off for this article. The classic box has no switches of its own,
	 * so it only says which channels leave the article out; the choice is made in the block editor panel.
	 *
	 * @param \WP_Post $post The post.
	 * @return void
	 */
	private function left_out( \WP_Post $post ): void {
		$names = array();

		foreach ( $this->plugin->make( ArticleChoices::class )->left_out( (int) $post->ID ) as $id ) {
			$channel = $this->plugin->registry()->get( $id );

			if ( null !== $channel && ChannelInterface::MODE_MANUAL !== $channel->mode() ) {
				$names[] = $channel->label();
			}
		}

		if ( array() === $names ) {
			return;
		}

		echo '<p class="estasoft-nd-cb__muted">' . esc_html(
			sprintf(
				/* translators: %s: channel names such as "Google News, Bing". */
				__( 'Left out by a choice for this article: %s.', 'estasoft-news-distribution' ),
				wp_sprintf( '%l', $names )
			)
		) . '</p>';
	}

	/**
	 * One checkbox with its help line.
	 *
	 * @param string $key     The option name.
	 * @param bool   $checked Whether it is on.
	 * @param string $label   The label.
	 * @param string $help    The help line.
	 * @return void
	 */
	private function checkbox( string $key, bool $checked, string $label, string $help ): void {
		$id = 'estasoft-nd-cb-' . str_replace( '_', '-', $key );

		printf(
			'<p class="estasoft-nd-cb__check"><input type="checkbox" id="%1$s" name="%2$s[%3$s]" value="1"%4$s aria-describedby="%1$s-help" /><label for="%1$s"><strong>%5$s</strong></label><span class="estasoft-nd-cb__help" id="%1$s-help">%6$s</span></p>',
			esc_attr( $id ),
			esc_attr( self::FIELDS ),
			esc_attr( $key ),
			checked( $checked, true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- checked() returns a fixed attribute.
			esc_html( $label ),
			esc_html( $help )
		);
	}

	/**
	 * `admin_footer`: the form Check now posts, outside the post form.
	 *
	 * @return void
	 */
	public function print_form(): void {
		if ( $this->printed < 1 ) {
			return;
		}

		printf(
			'<form id="%1$s" method="post" action="%2$s" hidden>',
			esc_attr( self::FORM ),
			esc_url( admin_url( 'admin-post.php' ) )
		);
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		echo '<input type="hidden" name="post_id" value="' . esc_attr( (string) $this->printed ) . '" />';
		wp_nonce_field( self::ACTION, self::CHECK_NONCE, false );
		echo '</form>';
	}

	/**
	 * `admin_post_estasoft_nd_classic_check`: Check now. Runs the check on the saved article and comes back to the edit screen.
	 *
	 * @return void
	 */
	public function check_now(): void {
		check_admin_referer( self::ACTION, self::CHECK_NONCE );

		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

		wp_safe_redirect( $this->run_check( $post_id ) );
		exit;
	}

	/**
	 * Runs the check for a person who may edit the article.
	 *
	 * @param int $post_id The article.
	 * @return string Where to go next: the edit screen, with the result in the address.
	 */
	public function run_check( int $post_id ): string {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! $this->plugin->access()->can_edit_article( $post_id ) || ! in_array( $post->post_type, $this->plugin->settings()->post_types(), true ) ) {
			$this->plugin->access()->deny();
		}

		try {
			$done = null !== $this->plugin->make( ArticleService::class )->check( $post_id, true );
		} catch ( \Throwable $error ) {
			$done = false;
		}

		return add_query_arg( self::RESULT, $done ? 'done' : 'failed', (string) get_edit_post_link( $post_id, 'raw' ) );
	}

	/**
	 * `save_post`: saves the box's choices with the post.
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    The post.
	 * @return void
	 */
	public function save( $post_id, $post = null ): void {
		$post_id = (int) $post_id;

		if ( ! isset( $_POST[ self::SAVE_NONCE ] ) || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::SAVE_NONCE ] ) ), 'estasoft_nd_classic_save_' . $post_id ) ) {
			return;
		}

		$post = $post instanceof \WP_Post ? $post : get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, $this->plugin->settings()->post_types(), true ) || ! $this->plugin->access()->can_edit_article( $post_id ) ) {
			return;
		}

		$fields = isset( $_POST[ self::FIELDS ] ) && is_array( $_POST[ self::FIELDS ] ) ? map_deep( wp_unslash( $_POST[ self::FIELDS ] ), 'sanitize_text_field' ) : array();

		$this->save_choices( $post_id, $fields );

		/**
		 * Lets a feature save the field it printed in the classic editor box.
		 *
		 * Internal. The nonce and the person's right to edit the article were checked before this runs.
		 *
		 * @param int      $post_id The post.
		 * @param \WP_Post $post    The post.
		 */
		do_action( 'estasoft_nd_internal_classic_box_save', $post_id, $post );
	}

	/**
	 * Writes the box's own choices. A checkbox that is not ticked is not sent, so the form's marker says the box was there.
	 *
	 * @param int                  $post_id The article.
	 * @param array<string, mixed> $fields  What was posted.
	 * @return void
	 */
	public function save_choices( int $post_id, array $fields ): void {
		if ( empty( $fields['present'] ) ) {
			return;
		}

		$values = array(
			'breaking'   => ! empty( $fields['breaking'] ),
			'minor_edit' => ! empty( $fields['minor_edit'] ),
		);

		$label = isset( $fields['content_label'] ) && is_scalar( $fields['content_label'] ) ? sanitize_key( (string) $fields['content_label'] ) : '';

		if ( '' !== $label && ( isset( ArticleOptions::content_labels()[ $label ] ) || ArticleOptions::get( $post_id )['content_label'] === $label ) ) {
			$values['content_label'] = $label;
		}

		ArticleOptions::update( $post_id, $values );
	}
}
