<?php
/**
 * AI suggestions for the summary of one article: the short text feeds and apps print under the headline, and the
 * description search results may show.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\CheckTabs;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Check\Context;
use Estasoft\NewsDistribution\Admin\Check\Times;
use Estasoft\NewsDistribution\Admin\Reports\AiLogReport;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\EmptyState;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Admin\Ui\Tag;
use Estasoft\NewsDistribution\Ai\Availability;
use Estasoft\NewsDistribution\Ai\Config;
use Estasoft\NewsDistribution\Ai\Features\Summary;
use Estasoft\NewsDistribution\Ai\Log;
use Estasoft\NewsDistribution\Ai\View\Parts;
use Estasoft\NewsDistribution\Ai\View\Runner;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Operations\Flash;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Integrations\KnownPlugins;

defined( 'ABSPATH' ) || exit;

/**
 * `admin.php?page=estasoft-nd-check&post=<id>&tab=summary`. The tab exists only when WordPress reports that text
 * generation is ready, the owner turned "Summaries and search descriptions" on and confirmed what is sent; otherwise it
 * is not in the list of tabs at all.
 *
 * It has two rows. The summary is the short text that feeds and apps print under the headline; the description is the
 * text a search result may show under the page title. Each row has a button that asks for up to three suggestions, and
 * each suggestion shows the checks it passed. Nothing changes until a person presses one of the buttons:
 *
 * - Summary: Use in feeds only (platforms read it, the excerpt stays), Use as excerpt, Edit first.
 * - Description: Use as search description, Edit first. The page head prints it only when no other plugin or theme
 *   printed a description (see `Markup\Description`).
 *
 * The buttons are forms, so the page works without a script; `Ai\View\Runner` handles what they post.
 */
final class SummaryTab extends AbstractCheckTab {

	public function slug(): string {
		return 'summary';
	}

	public function title(): string {
		return __( 'Summary', 'estasoft-news-distribution' );
	}

	public function description(): string {
		return __( 'A short summary for feeds and apps, and a description for search results. Nothing changes until you choose.', 'estasoft-news-distribution' );
	}

	public function order(): int {
		return 82;
	}

	public function permission(): string {
		return Access::AI;
	}

	public function available( int $post_id ): bool {
		return $this->plugin->make( Availability::class )->usable() && $this->plugin->make( Config::class )->feature_on( 'summary' );
	}

	public function handle( int $post_id ): void {
		if ( $post_id < 1 || ! Runner::posted( $this->slug() ) ) {
			return;
		}

		$this->plugin->access()->require_or_die( Access::AI, $post_id );
		$this->plugin->make( Runner::class )->handle( $this->slug(), $this->url( $post_id ) . '#estasoft-nd-ai-panel' );
	}

	public function enqueue(): void {
		Assets::screen_style( 'ai' );
		Assets::screen_script( 'ai' );
	}

	public function render( int $post_id ): string {
		$ctx = new Context( $this->plugin, $post_id );

		if ( ! $ctx->exists() ) {
			return EmptyState::render(
				__( 'This is not an article News Distribution sends', 'estasoft-news-distribution' ),
				__( 'Its post type is not one of the types chosen in Settings, General.', 'estasoft-news-distribution' )
			);
		}

		$feature = $this->plugin->make( Summary::class );
		$row     = $feature->pending_row( $post_id );
		$none    = array();

		foreach ( array( 'summary', 'description' ) as $kind ) {
			$last = $this->plugin->make( Log::class )->latest_for( $kind, $post_id );

			if ( null !== $last && Log::NONE === $last['decision'] && Clock::now() - (int) $last['created'] < 10 * MINUTE_IN_SECONDS ) {
				$none[] = $kind;
			}
		}

		return Flash::take()
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-ai-tab' ), $this->main( $ctx, $row ) . $this->notes( $post_id, $none ) );
	}

	/**
	 * Whether the article may be sent: published, or a draft the owner allowed.
	 *
	 * @param int $post_id The article.
	 * @return bool
	 */
	private function sendable( int $post_id ): bool {
		$article = $this->plugin->reader()->find( $post_id );

		return null !== $article && ( $article->is_public() || $this->plugin->make( Config::class )->drafts() );
	}

	/**
	 * The article's head, the two rows and the suggestions.
	 *
	 * @param Context                   $ctx The article.
	 * @param array<string, mixed>|null $row The suggestions waiting for a decision.
	 * @return string Escaped HTML.
	 */
	private function main( Context $ctx, ?array $row ): string {
		$id      = $ctx->id();
		$now     = $this->plugin->make( Summary::class )->current( $id );
		$waiting = null === $row ? '' : ( 'description' === ( $row['data']['finding'] ?? '' ) ? 'description' : 'summary' );
		$may     = $this->sendable( $id );

		if ( '' !== $now['feed'] ) {
			$summary_detail = Html::tag( 'span', array( 'dir' => 'auto' ), esc_html( $now['feed'] ) );
			$summary_status = StatusView::pill( Status::CLEAN, __( 'Feeds only', 'estasoft-news-distribution' ) );
		} elseif ( '' !== $now['excerpt'] ) {
			$summary_detail = Html::tag( 'span', array( 'dir' => 'auto' ), esc_html( $now['excerpt'] ) );
			$summary_status = Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'The excerpt', 'estasoft-news-distribution' ) );
		} else {
			$summary_detail = esc_html__( 'The article has no excerpt, so platforms print the first words of its text.', 'estasoft-news-distribution' );
			$summary_status = Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'No summary', 'estasoft-news-distribution' ) );
		}

		if ( '' !== $now['description'] ) {
			$description_detail = Html::tag( 'span', array( 'dir' => 'auto' ), esc_html( $now['description'] ) );
			$description_status = StatusView::pill( Status::CLEAN, __( 'Saved', 'estasoft-news-distribution' ) );
		} else {
			$description_detail = esc_html__( 'None chosen. Search engines write their own from the page.', 'estasoft-news-distribution' );
			$description_status = Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Not set', 'estasoft-news-distribution' ) );
		}

		$rows = $this->row(
			esc_html__( 'Summary for feeds and apps', 'estasoft-news-distribution' ),
			$summary_status,
			$summary_detail,
			$may ? $this->suggest_button( $id, 'summary', $waiting ) : ''
		) . $this->row(
			esc_html__( 'Description for search results', 'estasoft-news-distribution' ),
			$description_status,
			$description_detail,
			$may ? $this->suggest_button( $id, 'description', $waiting ) : ''
		);

		$panel = null === $row ? '' : $this->panel( $row, $id );

		return Html::tag(
			'section',
			array(
				'class'           => 'estasoft-nd-card estasoft-nd-card--flush estasoft-nd-detail',
				'aria-labelledby' => 'estasoft-nd-ai-head',
			),
			$this->head( $ctx )
			. ( $may ? '' : $this->draft_bar() )
			. Html::tag( 'div', array( 'id' => 'estasoft-nd-ai-rows' ), $rows )
			. $panel
			. $this->cleared( $id, $now )
		);
	}

	/**
	 * The article's title, its language and when it came out.
	 *
	 * @param Context $ctx The article.
	 * @return string Escaped HTML.
	 */
	private function head( Context $ctx ): string {
		$article = $ctx->article();

		if ( $ctx->is_published() && null !== $article ) {
			$when = sprintf(
				/* translators: %s: a date and time such as "19 Oct, 09:12". */
				__( 'Published %s', 'estasoft-news-distribution' ),
				Times::short( $article->published_ts() )
			);
		} else {
			$when = __( 'Not published yet', 'estasoft-news-distribution' );
		}

		return Html::tag(
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
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-small estasoft-nd-muted estasoft-nd-detail__meta' ), Tag::lang( $ctx->language() ) . ' ' . esc_html( $when ) )
			)
		);
	}

	/**
	 * The line shown when the article is a draft and drafts may not be sent.
	 *
	 * @return string Escaped HTML.
	 */
	private function draft_bar(): string {
		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-ai-bar' ),
			Message::bar( 'info', esc_html__( 'This article is not published. Turn on “Also drafts and scheduled posts” in Settings, AI assistant, if the assistant may read it. Until then nothing is sent.', 'estasoft-news-distribution' ) )
		);
	}

	/**
	 * One row: what the article has now, where it applies, and the button.
	 *
	 * @param string $title  Escaped HTML for the sentence.
	 * @param string $status Trusted HTML for the status.
	 * @param string $detail Trusted HTML for the line under the sentence, or empty.
	 * @param string $action Trusted HTML for the button, or empty.
	 * @return string Escaped HTML.
	 */
	private function row( string $title, string $status, string $detail, string $action = '' ): string {
		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-ai-row' ),
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-ai-row__text' ),
				Html::tag( 'strong', array( 'class' => 'estasoft-nd-ai-row__title' ), $title )
				. ( '' === $detail ? '' : Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), $detail ) )
			)
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-ai-row__status' ), $status )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-ai-row__action' ), $action )
		);
	}

	/**
	 * "Suggest a summary" or "Suggest a description", or "Hide suggestions" while some of that kind wait.
	 *
	 * @param int    $post_id The article.
	 * @param string $kind    `summary` or `description`.
	 * @param string $waiting The kind whose suggestions are shown now, or empty.
	 * @return string Escaped HTML.
	 */
	private function suggest_button( int $post_id, string $kind, string $waiting ): string {
		if ( $waiting === $kind ) {
			$row = $this->plugin->make( Summary::class )->pending_row( $post_id, $kind );

			return Parts::form(
				$this->url( $post_id ),
				$this->slug(),
				'decide',
				array(
					'log'      => null === $row ? 0 : (int) $row['id'],
					'decision' => 'dismiss',
				),
				Button::submit( __( 'Hide suggestions', 'estasoft-news-distribution' ), array( 'size' => 'sm' ) )
			);
		}

		return Parts::form(
			$this->url( $post_id ),
			$this->slug(),
			'suggest',
			array(
				'post'                   => $post_id,
				'estasoft_nd_ai_feature' => 'summary',
				'kind'                   => $kind,
			),
			Button::submit( 'description' === $kind ? __( 'Suggest a description', 'estasoft-news-distribution' ) : __( 'Suggest a summary', 'estasoft-news-distribution' ), array( 'size' => 'sm' ) )
		);
	}

	/**
	 * The suggestions of one request, each with the checks it passed and the buttons that apply it.
	 *
	 * @param array<string, mixed> $row     The log row.
	 * @param int                  $post_id The article.
	 * @return string Escaped HTML.
	 */
	private function panel( array $row, int $post_id ): string {
		$data    = (array) $row['data'];
		$kind    = 'description' === ( $data['finding'] ?? '' ) ? 'description' : 'summary';
		$list    = (array) ( $data['suggestions'] ?? array() );
		$limit   = (array) ( $data['limit'] ?? array() );
		$current = (array) ( $data['current'] ?? array() );
		$now     = 'description' === $kind ? (string) ( $current['description'] ?? '' ) : ( '' !== (string) ( $current['feed'] ?? '' ) ? (string) $current['feed'] : (string) ( $current['excerpt'] ?? '' ) );
		$html    = Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-ai-current' ),
			Html::tag(
				'strong',
				array(),
				esc_html( 'description' === $kind ? __( 'Suggestions for the search description', 'estasoft-news-distribution' ) : __( 'Suggestions for the summary', 'estasoft-news-distribution' ) )
			)
			. ( '' === $now ? '' : ' ' . Html::tag(
				'span',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
				esc_html(
					sprintf(
						/* translators: %s: the text the article has now. */
						__( 'Now: %s', 'estasoft-news-distribution' ),
						$now
					)
				)
			) )
		);

		foreach ( $list as $index => $suggestion ) {
			if ( ! is_array( $suggestion ) ) {
				continue;
			}

			$html .= $this->suggestion( $row, (int) $index, $suggestion, $post_id, $kind, (int) ( $limit['max'] ?? 300 ) );
		}

		$html .= Parts::form(
			$this->url( $post_id ),
			$this->slug(),
			'decide',
			array(
				'log'      => (int) $row['id'],
				'decision' => 'dismiss',
			),
			Button::submit( __( 'Dismiss all', 'estasoft-news-distribution' ), array( 'size' => 'sm' ) )
		);

		$html .= Html::tag( 'p', array( 'class' => 'estasoft-nd-small estasoft-nd-muted' ), esc_html( $this->limit_note( $kind, $limit ) ) );

		if ( 'description' === $kind ) {
			$html .= Html::tag( 'p', array( 'class' => 'estasoft-nd-small estasoft-nd-muted' ), esc_html( $this->seo_note() ) );
		} else {
			$html .= Html::tag( 'p', array( 'class' => 'estasoft-nd-small estasoft-nd-muted' ), esc_html__( 'Use in feeds only changes what platforms print, and the excerpt of the article stays as it is. Use as excerpt changes the excerpt, which your theme and other plugins may print on your site too.', 'estasoft-news-distribution' ) );
		}

		$dropped = (int) ( $data['dropped'] ?? 0 );
		$line    = '';

		if ( $dropped > 0 ) {
			$line = sprintf(
				/* translators: %d: a number of suggestions. */
				_n( '%d more suggestion was not shown because it broke a rule. ', '%d more suggestions were not shown because they broke a rule. ', $dropped, 'estasoft-news-distribution' ),
				$dropped
			);
		}

		$line .= Parts::made_by(
			array(
				'provider_name' => Parts::connection_name( $this->plugin, (string) $row['connection'] ),
				'requests'      => 1,
			)
		) . ' ' . __( 'Check each one before you use it. Each suggestion is recorded in the', 'estasoft-news-distribution' ) . ' ';

		$report = $this->log_link();

		$html .= Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-small estasoft-nd-muted' ),
			esc_html( $line ) . ( '' === $report ? esc_html__( 'AI log.', 'estasoft-news-distribution' ) : Html::tag( 'a', array( 'href' => $report ), esc_html__( 'AI log', 'estasoft-news-distribution' ) ) . '.' )
		);

		return Html::tag(
			'div',
			array(
				'class' => 'estasoft-nd-ai-panel',
				'id'    => 'estasoft-nd-ai-panel',
			),
			$html
		);
	}

	/**
	 * Where the limit comes from, in a sentence.
	 *
	 * @param string               $kind  `summary` or `description`.
	 * @param array<string, mixed> $limit `max`, `source` and `platform`.
	 * @return string Plain text.
	 */
	private function limit_note( string $kind, array $limit ): string {
		$max = (int) ( $limit['max'] ?? 0 );

		if ( 'rules' === ( $limit['source'] ?? '' ) ) {
			return sprintf(
				/* translators: 1: a number of characters; 2: a platform name. */
				__( 'Limit: %1$d characters, the shortest that %2$s allows among the platforms that are on.', 'estasoft-news-distribution' ),
				$max,
				(string) ( $limit['platform'] ?? '' )
			);
		}

		if ( 'description' === $kind ) {
			return sprintf(
				/* translators: %d: a number of characters. */
				__( 'Limit: %d characters. This is News Distribution’s own style limit. Search engines decide for themselves how much of a description they show.', 'estasoft-news-distribution' ),
				$max
			);
		}

		return sprintf(
			/* translators: %d: a number of characters. */
			__( 'Limit: %d characters. This is News Distribution’s own limit: none of the platforms that are on states one.', 'estasoft-news-distribution' ),
			$max
		);
	}

	/**
	 * What happens to a description when an SEO plugin or the theme prints one too.
	 *
	 * @return string Plain text.
	 */
	private function seo_note(): string {
		$names = array();

		foreach ( KnownPlugins::found() as $plugin ) {
			if ( 'seo' === $plugin['kind'] ) {
				$names[] = $plugin['name'];
			}
		}

		if ( array() === $names ) {
			return __( 'News Distribution prints the description you choose in the head of the article’s page. If your theme already prints one for this article, that one stays and this one is not printed.', 'estasoft-news-distribution' );
		}

		return sprintf(
			/* translators: %s: names of SEO plugins found on the site, such as "Yoast SEO and Rank Math SEO". */
			__( 'News Distribution prints the description you choose only when the page has none. %s is active on this site: if it prints a description for this article, that one stays, and the one you choose here is kept but not printed. News Distribution never changes another plugin’s settings.', 'estasoft-news-distribution' ),
			wp_sprintf( '%l', $names )
		);
	}

	/**
	 * One suggestion.
	 *
	 * @param array<string, mixed> $row        The log row.
	 * @param int                  $index      Its place in the stored list.
	 * @param array<string, mixed> $suggestion The suggestion.
	 * @param int                  $post_id    The article.
	 * @param string               $kind       `summary` or `description`.
	 * @param int                  $max        The most characters.
	 * @return string Escaped HTML.
	 */
	private function suggestion( array $row, int $index, array $suggestion, int $post_id, string $kind, int $max ): string {
		$log   = (int) $row['id'];
		$url   = $this->url( $post_id );
		$text  = (string) ( $suggestion['text'] ?? '' );
		$chars = (int) ( $suggestion['chars'] ?? mb_strlen( $text ) );
		$first = 0 === $index ? 'primary' : '';

		if ( 'description' === $kind ) {
			$buttons = Parts::decide( __( 'Use as search description', 'estasoft-news-distribution' ), 'use_description', array( 'variant' => $first ) );
			$targets = Parts::decide( __( 'Use as search description', 'estasoft-news-distribution' ), 'description', array( 'name' => 'target' ) );
		} else {
			$buttons = Parts::decide( __( 'Use in feeds only', 'estasoft-news-distribution' ), 'use_feeds', array( 'variant' => $first ) )
				. Parts::decide( __( 'Use as excerpt', 'estasoft-news-distribution' ), 'excerpt' );
			$targets = Parts::decide( __( 'Use in feeds only', 'estasoft-news-distribution' ), 'feeds', array( 'name' => 'target' ) )
				. Parts::decide( __( 'Use as excerpt', 'estasoft-news-distribution' ), 'excerpt', array( 'name' => 'target' ) );
		}

		$use  = Parts::form(
			$url,
			$this->slug(),
			'decide',
			array(
				'log'   => $log,
				'index' => $index,
			),
			$buttons,
			'estasoft-nd-ai-actions'
		);
		$edit = Html::tag(
			'details',
			array( 'class' => 'estasoft-nd-ai-edit' ),
			Html::tag( 'summary', array( 'class' => 'estasoft-nd-btn estasoft-nd-btn--sm' ), esc_html__( 'Edit first', 'estasoft-news-distribution' ) )
			. Parts::form(
				$url,
				$this->slug(),
				'decide',
				array(
					'log'      => $log,
					'decision' => 'edit',
				),
				Html::tag(
					'label',
					array(
						'class' => 'screen-reader-text',
						'for'   => 'estasoft-nd-ai-edit-' . $index,
					),
					esc_html__( 'Your wording', 'estasoft-news-distribution' )
				)
				. Html::void(
					'input',
					array(
						'type'      => 'text',
						'id'        => 'estasoft-nd-ai-edit-' . $index,
						'name'      => 'text',
						'value'     => $text,
						'class'     => 'estasoft-nd-field estasoft-nd-w-480',
						'maxlength' => (string) max( 1, $max ),
						'dir'       => 'auto',
					)
				)
				. Html::tag( 'span', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ), $targets ),
				'estasoft-nd-ai-editform'
			)
		);

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-suggest' ),
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
				Parts::badge()
				. Html::tag( 'strong', array( 'dir' => 'auto' ), esc_html( $text ) )
				. Html::tag(
					'span',
					array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
					esc_html(
						sprintf(
							/* translators: %d: a number of characters. */
							_n( '%d character', '%d characters', $chars, 'estasoft-news-distribution' ),
							$chars
						)
					)
				)
			)
			. Parts::ticks( (array) ( $suggestion['checks'] ?? array() ) )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ), $use . $edit )
		);
	}

	/**
	 * Lines that say a chosen text is in use, each with a button that removes it again.
	 *
	 * @param int                  $post_id The article.
	 * @param array<string, mixed> $now     What the article has now.
	 * @return string Escaped HTML.
	 */
	private function cleared( int $post_id, array $now ): string {
		$html = '';
		$log  = $this->plugin->make( Log::class );
		$rows = array(
			array( 'summary', 'feed', 'clear_feeds', __( 'Platforms read a different summary', 'estasoft-news-distribution' ), __( 'Feeds only', 'estasoft-news-distribution' ), __( 'Use the usual summary again', 'estasoft-news-distribution' ) ),
			array( 'description', 'description', 'clear_description', __( 'A search description is saved', 'estasoft-news-distribution' ), __( 'Saved', 'estasoft-news-distribution' ), __( 'Remove the description', 'estasoft-news-distribution' ) ),
		);

		foreach ( $rows as $one ) {
			list( $feature, $key, $decision, $title, $pill, $button ) = $one;

			$last = $log->latest_for( $feature, $post_id );

			if ( '' === (string) $now[ $key ] || null === $last ) {
				continue;
			}

			$html .= $this->row(
				esc_html( $title ),
				StatusView::pill( Status::CLEAN, $pill ),
				Html::tag( 'span', array( 'dir' => 'auto' ), esc_html( (string) $now[ $key ] ) ),
				Parts::form(
					$this->url( $post_id ),
					$this->slug(),
					'decide',
					array(
						'log'      => (int) $last['id'],
						'decision' => $decision,
					),
					Button::submit( $button, array( 'size' => 'sm' ) )
				)
			);
		}

		return $html;
	}

	/**
	 * The notes under the article.
	 *
	 * @param int      $post_id The article.
	 * @param string[] $none    The kinds whose last request gave no suggestion that followed the rules.
	 * @return string Escaped HTML.
	 */
	private function notes( int $post_id, array $none ): string {
		$report = $this->log_link();
		$how    = Html::tag(
			'ul',
			array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ),
			$this->point( __( 'The AI writes suggestions. News Distribution runs the rules on each one and shows only those that follow them', 'estasoft-news-distribution' ) )
			. $this->point( __( 'Nothing changes until you click', 'estasoft-news-distribution' ) )
			. $this->point( __( 'The headline, the body and the excerpt of the article change only if you choose Use as excerpt', 'estasoft-news-distribution' ) )
			. $this->point( __( 'Articles with AI-written text are marked AI-assisted automatically, and a person can remove the mark only by editing the article’s content label.', 'estasoft-news-distribution' ) )
			. Html::tag(
				'li',
				array( 'class' => 'estasoft-nd-rule' ),
				Icon::svg( 'info' ) . Html::tag(
					'span',
					array(),
					'' === $report
						? esc_html__( 'Each suggestion goes in the AI log', 'estasoft-news-distribution' )
						: esc_html__( 'Each suggestion goes in the', 'estasoft-news-distribution' ) . ' ' . Html::tag( 'a', array( 'href' => $report ), esc_html__( 'AI log', 'estasoft-news-distribution' ) )
				)
			)
		);

		$sent = Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-muted' ),
			esc_html__( 'One request sends the language, the headline and the first 600 characters of the article, with the rules and the limit. It goes to your AI provider, and nowhere else.', 'estasoft-news-distribution' )
		) . Parts::ticks(
			array(
				array(
					'ok'   => true,
					'text' => __( 'Only text from the article you chose', 'estasoft-news-distribution' ),
				),
				array(
					'level' => 'none',
					'text'  => __( 'Not the author, the rest of the text or the pictures', 'estasoft-news-distribution' ),
				),
			)
		);

		$working = Parts::wait(
			sprintf(
				/* translators: %s: a provider name such as Anthropic. */
				__( 'Asking %s for suggestions. This takes a few seconds.', 'estasoft-news-distribution' ),
				$this->provider_name()
			),
			true
		);
		$idle  = Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
			esc_html__( 'While a request runs, this card shows gray placeholders. When no suggestion follows the rules, it says so here and nothing is changed.', 'estasoft-news-distribution' )
		);
		$empty = '';

		foreach ( $none as $kind ) {
			$empty .= Message::bar(
				'warning',
				Html::tag(
					'strong',
					array(),
					esc_html( 'description' === $kind ? __( 'The last request for a description gave nothing that follows the rules, so none is shown.', 'estasoft-news-distribution' ) : __( 'The last request for a summary gave nothing that follows the rules, so none is shown.', 'estasoft-news-distribution' ) )
				) . ' ' . esc_html__( 'Nothing was changed. Write one yourself, or ask again.', 'estasoft-news-distribution' ),
				$this->sendable( $post_id ) ? Parts::form(
					$this->url( $post_id ),
					$this->slug(),
					'suggest',
					array(
						'post'                   => $post_id,
						'estasoft_nd_ai_feature' => 'summary',
						'kind'                   => $kind,
					),
					Button::submit( __( 'Ask again', 'estasoft-news-distribution' ), array( 'size' => 'sm' ) )
				) : ''
			);
		}

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-notes' ),
			Card::note( 'ai-how', __( 'How suggestions work', 'estasoft-news-distribution' ), $how )
			. Card::note( 'ai-sent', __( 'What is sent', 'estasoft-news-distribution' ), $sent )
			. Card::note( 'ai-wait', __( 'While it works, or finds nothing', 'estasoft-news-distribution' ), $working . $idle . $empty )
			. Card::cannot(
				array(
					__( 'Check that a suggestion is true. A person reads each one before using it.', 'estasoft-news-distribution' ),
					__( 'Make a platform or a search engine show the text. They decide what they print.', 'estasoft-news-distribution' ),
					__( 'Change another plugin’s description. A description another plugin prints always stays.', 'estasoft-news-distribution' ),
				)
			)
		);
	}

	/**
	 * One line of the "how it works" list.
	 *
	 * @param string $text Plain text.
	 * @return string Escaped HTML.
	 */
	private function point( string $text ): string {
		return Html::tag( 'li', array( 'class' => 'estasoft-nd-rule' ), Icon::svg( 'info' ) . Html::tag( 'span', array(), esc_html( $text ) ) );
	}

	/**
	 * The name of the provider that answers, for the waiting line.
	 *
	 * @return string
	 */
	private function provider_name(): string {
		$providers = $this->plugin->make( Availability::class )->usable_providers();

		return array() === $providers ? __( 'your AI provider', 'estasoft-news-distribution' ) : (string) $providers[0]['name'];
	}

	/**
	 * The address of the AI log, for a person who may open it.
	 *
	 * @return string Empty when the person cannot.
	 */
	private function log_link(): string {
		$report = new AiLogReport( $this->plugin );

		return $this->plugin->access()->can( $report->permission() ) ? $report->url() : '';
	}
}
