<?php
/**
 * Ask the assistant: questions about one article, answered from the rule packs and the article's own checks.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\CheckTabs;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Check\ChannelRows;
use Estasoft\NewsDistribution\Admin\Check\Context;
use Estasoft\NewsDistribution\Admin\Check\Links;
use Estasoft\NewsDistribution\Admin\Check\Times;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\EmptyState;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Tag;
use Estasoft\NewsDistribution\Admin\Ui\Table;
use Estasoft\NewsDistribution\Ai\Availability;
use Estasoft\NewsDistribution\Ai\Config;
use Estasoft\NewsDistribution\Ai\Features\Ask;
use Estasoft\NewsDistribution\Ai\View\Parts;
use Estasoft\NewsDistribution\Ai\View\Runner;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Operations\Flash;

defined( 'ABSPATH' ) || exit;

/**
 * `admin.php?page=estasoft-nd-check&post=<id>&tab=ask`. It exists only while the assistant is ready and "Ask the
 * assistant" is on.
 *
 * Each question is one request. What is sent is the question and a short list of facts: the article's own check and
 * the rules of the platforms that are on, read from their rule packs. Not the text of the article. An answer is shown
 * only when it names a source that is in those facts and every figure in it is a figure in them; the source line
 * under it is written by News Distribution. Otherwise the chat says "I cannot answer that."
 *
 * The question box is a form, so the page works without a script; `Ai\View\Runner` handles what it posts.
 */
final class AskTab extends AbstractCheckTab {

	public function slug(): string {
		return 'ask';
	}

	public function title(): string {
		return __( 'Ask the assistant', 'estasoft-news-distribution' );
	}

	public function description(): string {
		return __( 'Ask a question and get an answer with its source.', 'estasoft-news-distribution' );
	}

	public function order(): int {
		return 82;
	}

	public function permission(): string {
		return Access::AI;
	}

	public function available( int $post_id ): bool {
		return $this->plugin->make( Availability::class )->usable() && $this->plugin->make( Config::class )->feature_on( 'ask' );
	}

	public function handle( int $post_id ): void {
		if ( $post_id < 1 || ! Runner::posted( $this->slug() ) ) {
			return;
		}

		$this->plugin->access()->require_or_die( Access::AI, $post_id );
		$this->plugin->make( Runner::class )->handle( $this->slug(), $this->url( $post_id ) . '#estasoft-nd-ai-chat' );
	}

	public function enqueue(): void {
		Assets::screen_style( 'ai' );
		Assets::screen_script( 'ai' );
	}

	public function render( int $post_id ): string {
		$ctx = new Context( $this->plugin, $post_id );

		if ( ! $ctx->exists() || null === $ctx->article() ) {
			return EmptyState::render(
				__( 'This is not an article News Distribution sends', 'estasoft-news-distribution' ),
				__( 'Its post type is not one of the types chosen in Settings, General.', 'estasoft-news-distribution' )
			);
		}

		$left = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-ai-ask__left' ),
			$this->article( $ctx ) . $this->packs( $ctx )
		);

		return Flash::take()
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-ai-ask' ),
				$left . Html::tag( 'div', array( 'class' => 'estasoft-nd-ai-ask__right' ), $this->chat( $ctx ) )
			);
	}

	/**
	 * The article and what each channel does with it, as the Checks tab shows it.
	 *
	 * @param Context $ctx The article.
	 * @return string Escaped HTML.
	 */
	private function article( Context $ctx ): string {
		$article = $ctx->article();
		$parts   = array();

		if ( $ctx->is_published() && null !== $article ) {
			$parts[] = sprintf(
				/* translators: %s: a date and time such as "today, 13:41". */
				__( 'Published %s', 'estasoft-news-distribution' ),
				lcfirst( Times::day( $article->published_ts() ) )
			);
		}

		if ( $ctx->was_checked() ) {
			$parts[] = sprintf(
				/* translators: %s: a number of things to fix. */
				_n( '%s thing to fix', '%s things to fix', $ctx->to_fix(), 'estasoft-news-distribution' ),
				number_format_i18n( $ctx->to_fix() )
			);
		}

		$rows = '';

		try {
			$rows = ( new ChannelRows( $this->plugin, $ctx, new Links( $this->plugin ) ) )->render();
		} catch ( \Throwable $e ) {
			$rows = '';
		}

		return Html::tag(
			'section',
			array(
				'class'           => 'estasoft-nd-card estasoft-nd-card--flush estasoft-nd-detail',
				'aria-labelledby' => 'estasoft-nd-ai-head',
			),
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-detail__head' ),
				Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-detail__text' ),
					Html::tag(
						'h2',
						array(
							'class' => 'estasoft-nd-page-title estasoft-nd-detail__title',
							'id'    => 'estasoft-nd-ai-head',
							'lang'  => $ctx->language(),
							'dir'   => 'auto',
						),
						esc_html( $ctx->title() )
					)
					. Html::tag( 'p', array( 'class' => 'estasoft-nd-small estasoft-nd-muted estasoft-nd-detail__meta' ), Tag::lang( $ctx->language() ) . ' ' . esc_html( implode( ' · ', $parts ) ) )
				)
			)
			. $rows
		);
	}

	/**
	 * "Rule packs behind the answers": which pack each platform's rules came from.
	 *
	 * @param Context $ctx The article.
	 * @return string Escaped HTML, empty when no channel with a rule pack is on.
	 */
	private function packs( Context $ctx ): string {
		$article = $ctx->article();

		if ( null === $article ) {
			return '';
		}

		$rows = array();

		foreach ( $this->plugin->make( Ask::class )->packs( $article ) as $pack ) {
			$newer = '' !== $pack['newest'] && $pack['newest'] !== $pack['version'];

			$rows[] = array(
				Html::tag( 'strong', array(), esc_html( $pack['label'] ) ),
				esc_html( $pack['version'] )
				. ( $newer ? Html::tag(
					'span',
					array( 'class' => 'estasoft-nd-muted estasoft-nd-small estasoft-nd-block' ),
					esc_html(
						sprintf(
							/* translators: %s: a pack version such as 2026.10.1. */
							__( '%s available, not applied', 'estasoft-news-distribution' ),
							$pack['newest']
						)
					)
				) : '' ),
				Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $pack['source'] ) ),
			);
		}

		if ( array() === $rows ) {
			return '';
		}

		return Card::flush(
			'ai-packs',
			__( 'Rule packs behind the answers', 'estasoft-news-distribution' ),
			__( 'Each answer names the pack it used. A pack is the platform’s published rules as data.', 'estasoft-news-distribution' ),
			Parts::fit(
				Table::render(
					'estasoft-nd-cols-ai-packs',
					array(
						__( 'Platform', 'estasoft-news-distribution' ),
						__( 'Pack applied', 'estasoft-news-distribution' ),
						__( 'Source', 'estasoft-news-distribution' ),
					),
					$rows,
					__( 'Rule packs behind the answers', 'estasoft-news-distribution' )
				),
				__( 'Rule packs behind the answers', 'estasoft-news-distribution' )
			)
		);
	}

	/**
	 * "Ask the assistant": the questions so far and the box for the next one.
	 *
	 * @param Context $ctx The article.
	 * @return string Escaped HTML.
	 */
	private function chat( Context $ctx ): string {
		$id      = $ctx->id();
		$history = $this->plugin->make( Ask::class )->history( $id, get_current_user_id() );
		$thread  = '';

		foreach ( $history as $turn ) {
			$thread .= Html::tag( 'p', array( 'class' => 'estasoft-nd-ai-ask__q' ), esc_html( $turn['question'] ) );
			$thread .= Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-ai-ask__a' . ( $turn['grounded'] ? '' : ' estasoft-nd-ai-ask__a--none' ) ),
				Parts::badge()
				. Html::tag( 'p', array(), esc_html( $turn['answer'] ) )
				. ( '' === $turn['source'] ? '' : Html::tag( 'p', array( 'class' => 'estasoft-nd-small estasoft-nd-muted' ), esc_html( $turn['source'] ) ) )
			);
		}

		if ( '' === $thread ) {
			$thread = Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'No question yet. Ask about this article’s checks or about a platform’s rules.', 'estasoft-news-distribution' ) );
		}

		$provider = $this->plugin->make( Availability::class )->usable_providers();
		$wait     = Parts::wait(
			sprintf(
				/* translators: %s: a provider name such as Anthropic. */
				__( 'Looking up the rules with %s…', 'estasoft-news-distribution' ),
				array() === $provider ? __( 'your AI provider', 'estasoft-news-distribution' ) : (string) $provider[0]['name']
			),
			true
		);

		$chips = '';

		foreach ( array(
			__( 'Why is this article flagged?', 'estasoft-news-distribution' ),
			__( 'Is it on every channel that is on?', 'estasoft-news-distribution' ),
			__( 'What does the title rule say?', 'estasoft-news-distribution' ),
		) as $question ) {
			$chips .= Parts::form(
				$this->url( $id ),
				$this->slug(),
				'suggest',
				array(
					'post'                   => $id,
					'estasoft_nd_ai_feature' => 'ask',
					'question'               => $question,
				),
				Html::tag(
					'button',
					array(
						'type'  => 'submit',
						'class' => 'estasoft-nd-btn estasoft-nd-btn--sm',
					),
					esc_html( $question )
				),
				'estasoft-nd-ai-chip'
			);
		}

		$box = Parts::form(
			$this->url( $id ),
			$this->slug(),
			'suggest',
			array(
				'post'                   => $id,
				'estasoft_nd_ai_feature' => 'ask',
			),
			Html::tag(
				'label',
				array(
					'class' => 'screen-reader-text',
					'for'   => 'estasoft-nd-ai-question',
				),
				esc_html__( 'Your question', 'estasoft-news-distribution' )
			)
			. Html::void(
				'input',
				array(
					'type'         => 'text',
					'id'           => 'estasoft-nd-ai-question',
					'name'         => 'question',
					'class'        => 'estasoft-nd-field estasoft-nd-grow',
					'maxlength'    => (string) Ask::MAX_QUESTION,
					'placeholder'  => __( 'Ask about this article', 'estasoft-news-distribution' ),
					'autocomplete' => 'off',
					'required'     => 'required',
				)
			)
			. Button::submit( __( 'Ask', 'estasoft-news-distribution' ), array( 'variant' => 'primary' ) ),
			'estasoft-nd-cluster estasoft-nd-gap-8 estasoft-nd-ai-ask__box'
		);

		$limit = $this->plugin->make( Config::class )->daily_limit();
		$note  = $limit > 0
			? sprintf(
				/* translators: %s: the daily request limit, such as 200. */
				__( 'Answers come from the rule packs and this article’s checks, with the source shown. AI can be wrong, so open the source before you rely on an answer. Each question is 1 of your %s requests a day, and questions are logged without the article text. A question the rules cannot answer gets “I cannot answer that” and still counts as a request. If your provider does not answer, the chat says so and nothing is changed.', 'estasoft-news-distribution' ),
				number_format_i18n( $limit )
			)
			: __( 'Answers come from the rule packs and this article’s checks, with the source shown. AI can be wrong, so open the source before you rely on an answer. Each question is one request, and questions are logged without the article text. A question the rules cannot answer gets “I cannot answer that” and still counts as a request. If your provider does not answer, the chat says so and nothing is changed.', 'estasoft-news-distribution' );

		return Html::tag(
			'section',
			array(
				'class'           => 'estasoft-nd-card estasoft-nd-ai-chat',
				'id'              => 'estasoft-nd-ai-chat',
				'aria-labelledby' => 'estasoft-nd-ai-chat-title',
			),
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
				Html::tag(
					'h2',
					array(
						'class' => 'estasoft-nd-title estasoft-nd-grow',
						'id'    => 'estasoft-nd-ai-chat-title',
					),
					esc_html__( 'Ask the assistant', 'estasoft-news-distribution' )
				) . Parts::badge()
			)
			. Html::tag(
				'div',
				array(
					'class'     => 'estasoft-nd-ai-ask__thread',
					'aria-live' => 'polite',
				),
				$thread . $wait
			)
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8 estasoft-nd-ai-ask__try' ),
				Html::tag( 'span', array( 'class' => 'estasoft-nd-small' ), esc_html__( 'Try', 'estasoft-news-distribution' ) ) . $chips
			)
			. $box
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-small estasoft-nd-muted' ), esc_html( $note ) )
		);
	}
}
