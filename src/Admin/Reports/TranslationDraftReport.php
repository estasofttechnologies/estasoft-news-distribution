<?php
/**
 * Translation draft: a first version of an article in another language, for a person to review.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Reports;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Reports\Parts\View;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\EmptyState;
use Estasoft\NewsDistribution\Admin\Ui\Grid;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\Tag;
use Estasoft\NewsDistribution\Ai\Availability;
use Estasoft\NewsDistribution\Ai\Config;
use Estasoft\NewsDistribution\Ai\Features\Translate;
use Estasoft\NewsDistribution\Ai\Log;
use Estasoft\NewsDistribution\Ai\Reviewers;
use Estasoft\NewsDistribution\Ai\View\Parts;
use Estasoft\NewsDistribution\Ai\View\Runner;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Language\Catalog;
use Estasoft\NewsDistribution\Core\Operations\Flash;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * `admin.php?page=estasoft-nd-reports&report=translation-draft&post=<id>`. It is in the list of reports only
 * while the assistant is ready and "Translation drafts" is on.
 *
 * Three steps, each a form that works without a script:
 *
 * 1. Choose the article, the language and the names to keep, and make the draft. One request sends the title and the text.
 * 2. Read the draft beside the original and the checks it passed. Then save it as a draft only, or send it for review
 *    to a reviewer who is signed off for the language, with an editor who holds editorial responsibility.
 * 3. A reviewer approves it. Until then WordPress keeps it a draft: it cannot be published.
 *
 * The draft is kept for a day while it waits for a person; it is not in the AI log, which holds only what was asked.
 */
final class TranslationDraftReport extends AbstractReport {

	public function name(): string {
		return 'translation-draft';
	}

	public function title(): string {
		return __( 'Translation draft', 'estasoft-news-distribution' );
	}

	public function description(): string {
		return __( 'A first version in another language, for a person to review.', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return 'content';
	}

	public function order(): int {
		return 45;
	}

	public function permission(): string {
		return Access::VIEW;
	}

	public function available(): bool {
		return $this->plugin->make( Availability::class )->usable() && $this->plugin->make( Config::class )->feature_on( 'translate' );
	}

	/**
	 * The figures, for the screen and for `GET /reports/translation-draft`.
	 *
	 * @return array<string, mixed>
	 */
	public function data(): array {
		$out = array();

		foreach ( $this->plugin->make( Translate::class )->unreviewed() as $item ) {
			$out[] = array(
				'log'       => $item['log'],
				'draft'     => $item['post'],
				'language'  => $item['language'],
				'approvals' => $item['approvals'],
				'needs'     => $item['needs'],
				'at'        => $item['at'] > 0 ? Clock::iso( $item['at'] ) : null,
			);
		}

		return array(
			'languages' => $this->plugin->make( Reviewers::class )->languages(),
			'drafts'    => $out,
		);
	}

	public function handle(): void {
		if ( ! Runner::posted( $this->name() ) ) {
			return;
		}

		check_admin_referer( Runner::action( $this->name() ), Runner::NONCE );
		$this->plugin->access()->require_or_die( Access::VIEW );

		$post = isset( $_POST['post'] ) ? absint( wp_unslash( $_POST['post'] ) ) : $this->post_id();

		$this->plugin->make( Runner::class )->handle( $this->name(), $this->url( $post > 0 ? array( 'post' => $post ) : array() ) );
	}

	public function enqueue(): void {
		Assets::screen_style( 'ai' );
		Assets::screen_script( 'ai' );
	}

	/**
	 * The article chosen in the address.
	 *
	 * @return int
	 */
	private function post_id(): int {
		return Query::int( 'post' );
	}

	public function render(): string {
		$feature = $this->plugin->make( Translate::class );
		$post_id = $this->post_id();
		$flash   = Flash::take();
		$article = $post_id > 0 && $this->plugin->access()->can( Access::AI, $post_id ) ? $this->plugin->reader()->find( $post_id ) : null;

		if ( $post_id > 0 && null === $article ) {
			$flash .= Message::text( 'warning', __( 'That is not an article you can make a translation draft of.', 'estasoft-news-distribution' ) );
		}

		$main = null === $article ? $this->choose() : $this->create( $article ) . $this->waiting_draft( $article );

		return $flash
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-ai-grid' ),
				Html::tag( 'div', array( 'class' => 'estasoft-nd-ai-grid__main' ), $main . $this->review_list() )
				. Html::tag( 'div', array( 'class' => 'estasoft-nd-ai-grid__side' ), $this->notes( $feature, $article ) )
			);
	}

	/**
	 * The first step when no article is chosen: a list of recent articles.
	 *
	 * @return string Escaped HTML.
	 */
	private function choose(): string {
		$args = array(
			'post_type'      => $this->plugin->settings()->post_types(),
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		);

		if ( $this->plugin->access()->sees_only_own() ) {
			$args['author'] = get_current_user_id();
		}

		$options = '';

		foreach ( get_posts( $args ) as $post ) {
			if ( $this->plugin->access()->can( Access::AI, (int) $post->ID ) ) {
				$options .= Html::tag( 'option', array( 'value' => (string) $post->ID ), esc_html( trim( wp_strip_all_tags( get_the_title( $post ) ) ) ) );
			}
		}

		if ( '' === $options ) {
			return EmptyState::render(
				__( 'There is no article to translate yet', 'estasoft-news-distribution' ),
				__( 'Publish an article, or open this page from an article you can edit.', 'estasoft-news-distribution' )
			);
		}

		$form = Html::tag(
			'form',
			array(
				'method' => 'get',
				'action' => admin_url( 'admin.php' ),
			),
			Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'page',
					'value' => 'estasoft-nd-reports',
				)
			)
			. Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'report',
					'value' => $this->name(),
				)
			)
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-form-row' ),
				Html::tag(
					'label',
					array(
						'class' => 'estasoft-nd-form-label',
						'for'   => 'estasoft-nd-ai-from',
					),
					esc_html__( 'From', 'estasoft-news-distribution' )
				)
				. Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-form-control' ),
					Html::tag(
						'select',
						array(
							'id'    => 'estasoft-nd-ai-from',
							'name'  => 'post',
							'class' => 'estasoft-nd-field',
						),
						$options
					)
				)
			)
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-form-row' ), Button::submit( __( 'Choose this article', 'estasoft-news-distribution' ), array( 'variant' => 'primary' ) ) )
		);

		return Card::flush( 'ai-choose', __( 'Choose an article', 'estasoft-news-distribution' ), __( 'The draft is made from one article and saved as a draft. Choose the article to start.', 'estasoft-news-distribution' ), $form );
	}

	/**
	 * "Create a draft": the language, the names to keep, what is sent.
	 *
	 * @param Article $article The article.
	 * @return string Escaped HTML.
	 */
	private function create( Article $article ): string {
		$feature   = $this->plugin->make( Translate::class );
		$reviewers = $this->plugin->make( Reviewers::class );
		$into      = '';

		foreach ( $reviewers->languages() as $code ) {
			if ( $code === $article->language() ) {
				continue;
			}

			$into .= Html::tag( 'option', array( 'value' => $code ), esc_html( View::language( $code ) ) );
		}

		if ( '' === $into ) {
			return Card::flush(
				'ai-create',
				__( 'Create a draft', 'estasoft-news-distribution' ),
				'',
				Html::tag( 'div', array( 'class' => 'estasoft-nd-row' ), Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'This site has no other language to translate into. Set one up in your translation plugin first.', 'estasoft-news-distribution' ) ) )
			);
		}

		$kept   = $feature->default_terms( $article );
		$inputs = '';

		for ( $i = 0; $i < 4; $i++ ) {
			$inputs .= Html::void(
				'input',
				array(
					'type'       => 'text',
					'name'       => 'keep[]',
					'value'      => $kept[ $i ] ?? '',
					'class'      => 'estasoft-nd-field',
					'maxlength'  => (string) Translate::TERM_MAX,
					'aria-label' => sprintf(
						/* translators: %d: the number of the box. */
						__( 'Name or term to keep, %d', 'estasoft-news-distribution' ),
						$i + 1
					),
				)
			);
		}

		$provider = $this->plugin->make( Availability::class )->usable_providers();
		$through  = array() === $provider ? __( 'your AI provider', 'estasoft-news-distribution' ) : (string) $provider[0]['name'];
		$words    = $article->word_count();
		$source   = Html::tag(
			'span',
			array( 'class' => 'estasoft-nd-ai-source' ),
			Tag::lang( $article->language() ) . Html::tag( 'span', array( 'dir' => 'auto' ), esc_html( $this->source_title( $article ) ) )
		);

		$form = Parts::form(
			$this->url( array( 'post' => $article->id() ) ),
			$this->name(),
			'suggest',
			array(
				'post'                   => $article->id(),
				'estasoft_nd_ai_feature' => 'translate',
			),
			$this->row( __( 'From', 'estasoft-news-distribution' ), $source )
			. $this->row(
				__( 'Into', 'estasoft-news-distribution' ),
				Html::tag(
					'select',
					array(
						'id'    => 'estasoft-nd-ai-into',
						'name'  => 'language',
						'class' => 'estasoft-nd-field',
					),
					$into
				)
				. Html::tag(
					'p',
					array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
					esc_html(
						sprintf(
							/* translators: %s: a list of language names. */
							__( 'Your site’s languages are %s. To translate into another language, set that language up in your translation plugin first.', 'estasoft-news-distribution' ),
							wp_sprintf( '%l', array_map( array( View::class, 'language' ), $reviewers->languages() ) )
						)
					)
				),
				'estasoft-nd-ai-into'
			)
			. $this->row(
				__( 'Keep as they are', 'estasoft-news-distribution' ),
				Html::tag( 'div', array( 'class' => 'estasoft-nd-ai-keep' ), $inputs )
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Names and terms you list are checked in the draft. Type each one exactly as it is written in the article.', 'estasoft-news-distribution' ) )
			)
			. $this->row(
				__( 'What is sent', 'estasoft-news-distribution' ),
				Html::tag(
					'p',
					array(),
					esc_html(
						sprintf(
							/* translators: %s: a number of words. */
							_n( 'The title, headings, body, captions and alt text. %s word.', 'The title, headings, body, captions and alt text. %s words.', $words, 'estasoft-news-distribution' ),
							number_format_i18n( $words )
						)
					)
				)
			)
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-form-row estasoft-nd-cluster estasoft-nd-gap-12' ),
				Button::submit( __( 'Make the draft', 'estasoft-news-distribution' ) )
				. Html::tag(
					'span',
					array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
					esc_html(
						sprintf(
							/* translators: %s: a provider name such as Anthropic. */
							__( '1 request through %s. If it fails, nothing is saved and you can make the draft again.', 'estasoft-news-distribution' ),
							$through
						)
					)
				)
			)
			. Parts::wait( __( 'Making the draft. This can take a minute for a long article.', 'estasoft-news-distribution' ), true )
		);

		return Card::flush(
			'ai-create',
			__( 'Create a draft', 'estasoft-news-distribution' ),
			__( 'The draft is saved in your site as a draft. It is never published without a signed-off reviewer’s approval.', 'estasoft-news-distribution' ),
			$form
		);
	}

	/**
	 * One labelled row of the form.
	 *
	 * @param string $label   The label (plain).
	 * @param string $control Trusted HTML.
	 * @param string $id      The id the label belongs to, or empty for a group.
	 * @return string Escaped HTML.
	 */
	private function row( string $label, string $control, string $id = '' ): string {
		$attrs = array( 'class' => 'estasoft-nd-form-label' );

		if ( '' !== $id ) {
			$attrs['for'] = $id;
		}

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-form-row' ),
			Html::tag( '' === $id ? 'div' : 'label', $attrs, esc_html( $label ) )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-form-control' ), $control )
		);
	}

	/**
	 * The headline the site shows for an article, not a title a person chose to use in feeds only.
	 *
	 * @param Article $article The article.
	 * @return string Plain text.
	 */
	private function source_title( Article $article ): string {
		return trim( wp_strip_all_tags( get_the_title( $article->id() ) ) );
	}

	/**
	 * The draft made a moment ago: the original beside it, the checks it passed, and the buttons that save it.
	 *
	 * @param Article $article The article.
	 * @return string Escaped HTML.
	 */
	private function waiting_draft( Article $article ): string {
		$feature = $this->plugin->make( Translate::class );
		$row     = $this->plugin->make( Log::class )->latest_for( 'translate', $article->id() );

		$draft = null === $row ? null : $feature->waiting( (int) $row['id'] );

		if ( null === $row || null === $draft || (int) ( $draft['user'] ?? 0 ) !== get_current_user_id() ) {
			return '';
		}

		$language = (string) $draft['language'];
		$name     = View::language( $language );
		$checks   = array();

		foreach ( (array) $draft['checks'] as $check ) {
			if ( is_array( $check ) ) {
				$checks[] = $check;
			}
		}

		$source_text = $this->excerpt( (string) get_post_field( 'post_content', $article->id() ) );
		$draft_text  = $this->excerpt( (string) $draft['content'] );
		$columns     = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-ai-draft' ),
			Html::tag(
				'div',
				array(
					'class' => 'estasoft-nd-ai-draft__col',
					'lang'  => $article->language(),
					'dir'   => 'auto',
				),
				Html::tag( 'p', array( 'class' => 'estasoft-nd-small estasoft-nd-muted' ), esc_html( sprintf( /* translators: %s: a language name. */ __( '%s (source)', 'estasoft-news-distribution' ), View::language( $article->language() ) ) ) )
				. Html::tag( 'h3', array(), esc_html( $this->source_title( $article ) ) )
				. Html::tag( 'p', array(), esc_html( $source_text ) )
			)
			. Html::tag(
				'div',
				array(
					'class' => 'estasoft-nd-ai-draft__col estasoft-nd-ai-draft__col--new',
					'lang'  => $language,
					'dir'   => 'auto',
				),
				Html::tag( 'p', array( 'class' => 'estasoft-nd-small estasoft-nd-muted' ), esc_html( sprintf( /* translators: %s: a language name. */ __( '%s (draft)', 'estasoft-news-distribution' ), $name ) ) )
				. Html::tag( 'h3', array(), esc_html( (string) $draft['title'] ) )
				. Html::tag( 'p', array(), esc_html( $draft_text ) )
			)
		);

		$head = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
			Html::tag(
				'h2',
				array(
					'class' => 'estasoft-nd-title estasoft-nd-grow',
					'id'    => 'estasoft-nd-ai-draft-title',
				),
				esc_html__( 'Draft', 'estasoft-news-distribution' )
			)
			. Parts::badge()
			. Tag::plain( __( 'Not published', 'estasoft-news-distribution' ) )
		);

		return Html::tag(
			'section',
			array(
				'class'           => 'estasoft-nd-card estasoft-nd-ai-draftcard',
				'id'              => 'estasoft-nd-ai-draft',
				'aria-labelledby' => 'estasoft-nd-ai-draft-title',
			),
			$head
			. $columns
			. Html::tag( 'h3', array( 'class' => 'estasoft-nd-title' ), esc_html__( 'Checks News Distribution ran', 'estasoft-news-distribution' ) )
			. Parts::ticks( $checks )
			. $this->review_form( $row, $language )
		);
	}

	/**
	 * The reviewer, the editor who holds responsibility, and the buttons.
	 *
	 * @param array<string, mixed> $row      The AI log row of the draft.
	 * @param string               $language The draft's language.
	 * @return string Escaped HTML.
	 */
	private function review_form( array $row, string $language ): string {
		$reviewers = $this->plugin->make( Reviewers::class );
		$signed    = $reviewers->signed_off( $language );
		$need      = $reviewers->requirement( $language );
		$reviewer  = '';

		foreach ( $signed as $id => $name ) {
			$reviewer .= Html::tag(
				'option',
				array( 'value' => (string) $id ),
				esc_html(
					sprintf(
						/* translators: 1: a person's name, 2: a language name. */
						__( '%1$s (signed off for %2$s)', 'estasoft-news-distribution' ),
						$name,
						View::language( $language )
					)
				)
			);
		}

		$responsible = '';

		foreach ( $reviewers->candidates() as $id => $name ) {
			$responsible .= Html::tag(
				'option',
				array(
					'value'    => (string) $id,
					'selected' => get_current_user_id() === $id ? 'selected' : null,
				),
				esc_html( $name )
			);
		}

		$reviewer_row = '' === $reviewer
			? Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $need['ok'] ? __( 'No reviewer is signed off, so two approvers must approve it.', 'estasoft-news-distribution' ) : __( 'No reviewer is signed off for this language yet. Sign one off in Settings, AI assistant.', 'estasoft-news-distribution' ) ) )
			: Html::tag(
				'select',
				array(
					'id'    => 'estasoft-nd-ai-reviewer',
					'name'  => 'reviewer',
					'class' => 'estasoft-nd-field',
				),
				$reviewer
			);

		$form = Parts::form(
			$this->url( array( 'post' => (int) $row['post_id'] ) ),
			$this->name(),
			'decide',
			array(
				'log'  => (int) $row['id'],
				'post' => (int) $row['post_id'],
			),
			Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-form-label',
					'for'   => 'estasoft-nd-ai-reviewer',
				),
				esc_html__( 'Reviewer', 'estasoft-news-distribution' )
			)
			. $reviewer_row
			. Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-form-label',
					'for'   => 'estasoft-nd-ai-responsible',
				),
				esc_html__( 'Editorial responsibility', 'estasoft-news-distribution' )
			)
			. Html::tag(
				'select',
				array(
					'id'    => 'estasoft-nd-ai-responsible',
					'name'  => 'responsible',
					'class' => 'estasoft-nd-field',
				),
				$responsible
			)
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
				Parts::decide( __( 'Send for review', 'estasoft-news-distribution' ), 'send_review', array( 'variant' => 'primary' ) )
				. Parts::decide( __( 'Save as a draft only', 'estasoft-news-distribution' ), 'save_draft' )
				. Parts::decide( __( 'Throw it away', 'estasoft-news-distribution' ), 'dismiss' )
			),
			'estasoft-nd-ai-reviewform'
		);

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-ai-review' ),
			Html::tag( 'h3', array( 'class' => 'estasoft-nd-title' ), esc_html__( 'Review before publishing', 'estasoft-news-distribution' ) )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( $this->plugin->make( Translate::class )->requirement_text( $language )['text'] ) )
			. $form
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-small estasoft-nd-muted' ), esc_html__( 'Articles with AI-written text are marked AI-assisted automatically, and a person can remove the mark only by editing the article’s content label. The reviewer’s name is recorded.', 'estasoft-news-distribution' ) )
		);
	}

	/**
	 * The first part of a text as plain words.
	 *
	 * @param string $html Stored text.
	 * @return string
	 */
	private function excerpt( string $html ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( strip_shortcodes( $html ) ) ) );

		return mb_strlen( $text ) > 320 ? rtrim( mb_substr( $text, 0, 320 ) ) . '…' : $text;
	}

	/**
	 * "Drafts waiting for review": the saved drafts and the button for a person who may approve.
	 *
	 * @return string Escaped HTML, empty when none wait.
	 */
	private function review_list(): string {
		$items = $this->plugin->make( Translate::class )->unreviewed();

		if ( array() === $items ) {
			return '';
		}

		$reviewers = $this->plugin->make( Reviewers::class );
		$rows      = array();

		foreach ( $items as $item ) {
			$edit      = (string) get_edit_post_link( $item['post'], 'raw' );
			$title     = trim( wp_strip_all_tags( get_the_title( $item['post'] ) ) );
			$user      = $item['reviewer'] > 0 ? get_userdata( $item['reviewer'] ) : false;
			$approvals = (array) ( $this->plugin->make( Translate::class )->review( $item['post'] )['approvals'] ?? array() );
			$can       = $this->plugin->access()->can( Access::VIEW ) && ! is_wp_error( $reviewers->can_approve( $item['language'], get_current_user_id(), $approvals ) ) && current_user_can( 'edit_post', $item['post'] );

			$rows[] = array(
				Html::tag(
					'span',
					array( 'class' => 'estasoft-nd-ai-queue__draft' ),
					( '' === $edit ? Html::tag( 'strong', array( 'dir' => 'auto' ), esc_html( $title ) ) : Html::tag(
						'a',
						array(
							'href' => $edit,
							'dir'  => 'auto',
						),
						esc_html( $title )
					) )
					. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( View::language( $item['language'] ) ) )
				),
				$user instanceof \WP_User ? esc_html( (string) $user->display_name ) : Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Not chosen', 'estasoft-news-distribution' ) ),
				Html::tag(
					'span',
					array( 'class' => 'estasoft-nd-ai-queue__draft' ),
					View::mark( 'info', __( 'Not reviewed yet', 'estasoft-news-distribution' ) )
					. Html::tag(
						'span',
						array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
						esc_html(
							sprintf(
								/* translators: 1: approvals so far, 2: approvals needed. */
								__( '%1$d of %2$d approvals', 'estasoft-news-distribution' ),
								$item['approvals'],
								$item['needs']
							)
						)
					)
				),
				$can
					? Parts::form(
						$this->url(),
						$this->name(),
						'decide',
						array(
							'log'      => $item['log'],
							'decision' => 'approve',
						),
						Button::submit( __( 'Approve', 'estasoft-news-distribution' ), array( 'size' => 'sm' ) )
					)
					: '',
			);
		}

		return Html::tag(
			'section',
			array(
				'class'           => 'estasoft-nd-ai-queue',
				'aria-labelledby' => 'estasoft-nd-ai-queue-title',
			),
			Html::tag(
				'h2',
				array(
					'class' => 'estasoft-nd-title',
					'id'    => 'estasoft-nd-ai-queue-title',
				),
				esc_html__( 'Drafts waiting for review', 'estasoft-news-distribution' )
			)
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-card estasoft-nd-card--flush' ),
				Parts::fit(
					Grid::render(
						'estasoft-nd-cols-ai-drafts',
						array(
							__( 'Draft', 'estasoft-news-distribution' ),
							__( 'Reviewer', 'estasoft-news-distribution' ),
							__( 'Status', 'estasoft-news-distribution' ),
							'',
						),
						$rows,
						__( 'Translation drafts waiting for review', 'estasoft-news-distribution' )
					),
					__( 'Translation drafts waiting for review', 'estasoft-news-distribution' ),
					true
				)
			)
		);
	}

	/**
	 * The notes beside the form.
	 *
	 * @param Translate    $feature The feature.
	 * @param Article|null $article The article chosen, if any.
	 * @return string Escaped HTML.
	 */
	private function notes( Translate $feature, ?Article $article ): string {
		unset( $feature, $article );

		$before = Html::tag(
			'ul',
			array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ),
			$this->point( __( 'In the EU, transparency rules in force since 2 August 2026 ask publishers to disclose AI-generated text that informs the public, unless a person has reviewed it and holds editorial responsibility', 'estasoft-news-distribution' ) )
			. $this->point( __( 'News Distribution records the reviewer so you can show that', 'estasoft-news-distribution' ) )
			. $this->point( __( 'This is not legal advice. Check what applies to you', 'estasoft-news-distribution' ) )
		) . Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-small estasoft-nd-muted' ),
			esc_html__( 'Kurdish (Kurmanji) drafts are weaker. News Distribution allows them only after a signed-off reviewer exists, and checks that the text is in Latin script.', 'estasoft-news-distribution' )
		);

		return Card::note( 'ai-before', __( 'Before you publish', 'estasoft-news-distribution' ), $before )
			. Card::cannot(
				array(
					__( 'Publish a translation by itself. A signed-off reviewer approves it first.', 'estasoft-news-distribution' ),
					__( 'Judge whether a translation is right. Only a person who reads the language can.', 'estasoft-news-distribution' ),
					__( 'Translate into a language that is not set up on this site.', 'estasoft-news-distribution' ),
				)
			);
	}

	/**
	 * One line of the "before you publish" list.
	 *
	 * @param string $text Plain text.
	 * @return string Escaped HTML.
	 */
	private function point( string $text ): string {
		return Html::tag( 'li', array( 'class' => 'estasoft-nd-rule' ), Icon::svg( 'info' ) . Html::tag( 'span', array(), esc_html( $text ) ) );
	}
}
