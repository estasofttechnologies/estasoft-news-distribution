<?php
/**
 * AI suggestions for one article: new titles that follow the rules of the platforms that are on.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\CheckTabs;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Check\Context;
use Estasoft\NewsDistribution\Admin\Check\Findings;
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
use Estasoft\NewsDistribution\Ai\Features\Fix;
use Estasoft\NewsDistribution\Ai\Log;
use Estasoft\NewsDistribution\Ai\View\Parts;
use Estasoft\NewsDistribution\Ai\View\Runner;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Operations\Flash;
use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * `admin.php?page=estasoft-nd-check&post=<id>&tab=ai`. The tab exists only when WordPress reports that text
 * generation is ready, the owner turned "Fix suggestions" on and confirmed what is sent; otherwise it is not in
 * the list of tabs at all.
 *
 * It lists the title problems the check found and, beside the first, a button that asks for new titles. Each
 * suggestion is shown with the checks it passed. Nothing changes until a person presses one of the buttons:
 * Use in feeds only (platforms read the new title, the headline on the site stays), Use as headline, Edit first.
 *
 * The buttons are forms, so the page works without a script; `Ai\View\Runner` handles what they post.
 */
final class AiTab extends AbstractCheckTab {

	public function slug(): string {
		return 'ai';
	}

	public function title(): string {
		return __( 'AI suggestions', 'estasoft-news-distribution' );
	}

	public function description(): string {
		return __( 'Titles that follow the rules of the platforms that are on. Nothing changes until you choose.', 'estasoft-news-distribution' );
	}

	public function order(): int {
		return 80;
	}

	public function permission(): string {
		return Access::AI;
	}

	public function available( int $post_id ): bool {
		return $this->plugin->make( Availability::class )->usable() && $this->plugin->make( Config::class )->feature_on( 'fix' );
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

		$fix   = $this->plugin->make( Fix::class );
		$row   = $fix->pending_row( $post_id, 'title' );
		$last  = $this->plugin->make( Log::class )->latest_for( 'fix', $post_id );
		$stale = null !== $last && Log::NONE === $last['decision'] && Clock::now() - (int) $last['created'] < 10 * MINUTE_IN_SECONDS;

		return Flash::take()
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-ai-tab' ), $this->main( $ctx, $row ) . $this->notes( $post_id, $stale ) );
	}

	/**
	 * The article's head, the rows that can be fixed and the suggestions.
	 *
	 * @param Context                   $ctx The article.
	 * @param array<string, mixed>|null $row The suggestions waiting for a decision.
	 * @return string Escaped HTML.
	 */
	private function main( Context $ctx, ?array $row ): string {
		$id       = $ctx->id();
		$findings = Findings::by_area( $ctx->findings() );
		$titles   = $findings['title'] ?? array();
		$alt      = array();
		$labels   = array();

		foreach ( $this->plugin->registry()->all() as $channel ) {
			$labels[ $channel->id() ] = $channel->label();
		}

		foreach ( $findings['image'] ?? array() as $finding ) {
			if ( 'image.alt_missing' === ( $finding['code'] ?? '' ) ) {
				$alt[] = $finding;
			}
		}

		$rows  = '';
		$first = true;

		foreach ( Findings::sorted( $titles ) as $finding ) {
			$rows .= $this->row(
				esc_html( (string) $finding['message'] ),
				$this->channels( $finding, $labels ),
				'',
				$first ? $this->suggest_button( $id, null !== $row ) : ''
			);

			$first = false;
		}

		if ( '' === $rows ) {
			$rows = $this->row(
				esc_html__( 'The title has no problem the checks know about', 'estasoft-news-distribution' ),
				StatusView::pill( Status::CLEAN ),
				Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'You can still ask for other wording. Each suggestion follows the rules of the platforms that are on.', 'estasoft-news-distribution' ) ),
				$this->suggest_button( $id, null !== $row )
			);
		}

		$panel = null === $row ? '' : $this->panel( $row, $id );

		foreach ( $alt as $finding ) {
			$image = $this->plugin->make( ImageHelperTab::class );

			$rows .= $this->row(
				esc_html( (string) $finding['message'] ),
				$this->channels( $finding, $labels ),
				Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Readers who cannot see the image get nothing.', 'estasoft-news-distribution' ) ),
				$image instanceof ImageHelperTab && $image->available( $id ) ? Button::link( __( 'Suggest a fix', 'estasoft-news-distribution' ), $image->url( $id ), array( 'size' => 'sm' ) ) : ''
			);
		}

		$cleared = $this->feed_title_row( $id );

		return Html::tag(
			'section',
			array(
				'class'           => 'estasoft-nd-card estasoft-nd-card--flush estasoft-nd-detail',
				'aria-labelledby' => 'estasoft-nd-ai-head',
			),
			$this->head( $ctx )
			. Html::tag( 'div', array( 'id' => 'estasoft-nd-ai-rows' ), $rows )
			. $panel
			. $cleared
		);
	}

	/**
	 * The article's title, its language, when it came out and how many problems it has.
	 *
	 * @param Context $ctx The article.
	 * @return string Escaped HTML.
	 */
	private function head( Context $ctx ): string {
		$article = $ctx->article();
		$parts   = array();

		if ( $ctx->is_published() && null !== $article ) {
			$parts[] = sprintf(
				/* translators: %s: a date and time such as "19 Oct, 09:12". */
				__( 'Published %s', 'estasoft-news-distribution' ),
				Times::short( $article->published_ts() )
			);
		} else {
			$parts[] = __( 'Not published yet', 'estasoft-news-distribution' );
		}

		if ( $ctx->was_checked() ) {
			$parts[] = sprintf(
				/* translators: %s: a number of problems. */
				_n( '%s problem', '%s problems', $ctx->to_fix(), 'estasoft-news-distribution' ),
				number_format_i18n( $ctx->to_fix() )
			);
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
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-small estasoft-nd-muted estasoft-nd-detail__meta' ), Tag::lang( $ctx->language() ) . ' ' . esc_html( implode( ' · ', $parts ) ) )
			)
		);
	}

	/**
	 * One row: what is wrong with a little more under it, where it applies, and the button.
	 *
	 * @param string $title  Escaped HTML for the sentence.
	 * @param string $status Trusted HTML for the status or the channels.
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
	 * The status cell of a finding row: its icon and the channels it is for.
	 *
	 * @param array<string, mixed>  $finding The finding.
	 * @param array<string, string> $labels  Channel id => name.
	 * @return string Escaped HTML.
	 */
	private function channels( array $finding, array $labels ): string {
		$names = array();

		foreach ( (array) ( $finding['channels'] ?? array() ) as $channel_id ) {
			if ( isset( $labels[ (string) $channel_id ] ) ) {
				$names[] = $labels[ (string) $channel_id ];
			}
		}

		return StatusView::inline( (string) ( $finding['severity'] ?? Status::WARN ), array() === $names ? __( 'All channels', 'estasoft-news-distribution' ) : wp_sprintf( '%l', $names ) );
	}

	/**
	 * "Suggest a fix", or "Hide suggestions" while some wait for a decision.
	 *
	 * @param int  $post_id The article.
	 * @param bool $waiting Whether suggestions are shown now.
	 * @return string Escaped HTML.
	 */
	private function suggest_button( int $post_id, bool $waiting ): string {
		if ( $waiting ) {
			$row = $this->plugin->make( Fix::class )->pending_row( $post_id, 'title' );

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
				'estasoft_nd_ai_feature' => 'fix',
			),
			Button::submit( __( 'Suggest a fix', 'estasoft-news-distribution' ), array( 'size' => 'sm' ) )
		);
	}

	/**
	 * The suggestions, each with the checks it passed and the buttons that apply it.
	 *
	 * @param array<string, mixed> $row  The log row.
	 * @param int                  $post_id The article.
	 * @return string Escaped HTML.
	 */
	private function panel( array $row, int $post_id ): string {
		$data    = (array) $row['data'];
		$current = (array) ( $data['current'] ?? array() );
		$list    = (array) ( $data['suggestions'] ?? array() );
		$html    = Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-ai-current' ),
			Html::tag( 'strong', array(), esc_html( sprintf( /* translators: %s: the title now. */ __( 'Current title: %s', 'estasoft-news-distribution' ), (string) ( $current['text'] ?? '' ) ) ) )
			. ' ' . Html::tag(
				'span',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
				esc_html(
					sprintf(
						/* translators: %d: a number of characters. */
						_n( '(%d character)', '(%d characters)', (int) ( $current['chars'] ?? 0 ), 'estasoft-news-distribution' ),
						(int) ( $current['chars'] ?? 0 )
					)
				)
			)
		);

		foreach ( $list as $index => $suggestion ) {
			if ( ! is_array( $suggestion ) ) {
				continue;
			}

			$html .= $this->suggestion( $row, (int) $index, $suggestion, $post_id );
		}

		$html .= Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-small estasoft-nd-muted' ),
			esc_html__( 'Use in feeds only changes the title sent to platforms, and your headline on the site stays as it is. Use as headline changes the title on your site.', 'estasoft-news-distribution' )
		);

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
	 * One suggestion.
	 *
	 * @param array<string, mixed> $row        The log row.
	 * @param int                  $index      Its place in the stored list.
	 * @param array<string, mixed> $suggestion The suggestion.
	 * @param int                  $post_id    The article.
	 * @return string Escaped HTML.
	 */
	private function suggestion( array $row, int $index, array $suggestion, int $post_id ): string {
		$log   = (int) $row['id'];
		$url   = $this->url( $post_id );
		$text  = (string) ( $suggestion['text'] ?? '' );
		$chars = (int) ( $suggestion['chars'] ?? mb_strlen( $text ) );
		$use   = Parts::form(
			$url,
			$this->slug(),
			'decide',
			array(
				'log'   => $log,
				'index' => $index,
			),
			Parts::decide( __( 'Use in feeds only', 'estasoft-news-distribution' ), 'use_feeds', array( 'variant' => 0 === $index ? 'primary' : '' ) )
			. Parts::decide( __( 'Use as headline', 'estasoft-news-distribution' ), 'headline' ),
			'estasoft-nd-ai-actions'
		);
		$edit  = Html::tag(
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
						'maxlength' => '200',
						'dir'       => 'auto',
					)
				)
				. Html::tag(
					'span',
					array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
					Parts::decide( __( 'Use in feeds only', 'estasoft-news-distribution' ), 'feeds', array( 'name' => 'target' ) )
					. Parts::decide( __( 'Use as headline', 'estasoft-news-distribution' ), 'headline', array( 'name' => 'target' ) )
				),
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
	 * A line that says platforms read another title than the headline, with a button to go back to the headline.
	 *
	 * @param int $post_id The article.
	 * @return string Escaped HTML.
	 */
	private function feed_title_row( int $post_id ): string {
		$title = Fix::feed_title( $post_id );
		$last  = $this->plugin->make( Log::class )->latest_for( 'fix', $post_id );

		if ( '' === $title || null === $last ) {
			return '';
		}

		return $this->row(
			esc_html__( 'Platforms read a different title', 'estasoft-news-distribution' ),
			StatusView::pill( Status::CLEAN, __( 'Feeds only', 'estasoft-news-distribution' ) ),
			Html::tag( 'span', array( 'dir' => 'auto' ), esc_html( $title ) ),
			Parts::form(
				$this->url( $post_id ),
				$this->slug(),
				'decide',
				array(
					'log'      => (int) $last['id'],
					'decision' => 'clear_feeds',
				),
				Button::submit( __( 'Use the headline again', 'estasoft-news-distribution' ), array( 'size' => 'sm' ) )
			)
		);
	}

	/**
	 * The three notes under the article.
	 *
	 * @param int  $post_id The article.
	 * @param bool $none    Whether the last request gave no suggestion that followed the rules.
	 * @return string Escaped HTML.
	 */
	private function notes( int $post_id, bool $none ): string {
		$report = $this->log_link();
		$how    = Html::tag(
			'ul',
			array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ),
			$this->point( __( 'The AI writes suggestions. News Distribution runs the rule on each one and shows only those that follow it', 'estasoft-news-distribution' ) )
			. $this->point( __( 'Nothing changes until you click', 'estasoft-news-distribution' ) )
			. $this->point( __( 'Your own headline changes only if you choose Use as headline', 'estasoft-news-distribution' ) )
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

		$provider = $this->provider_name();
		$working  = Parts::wait(
			sprintf(
				/* translators: %s: a provider name such as Anthropic. */
				__( 'Asking %s for suggestions. This takes a few seconds.', 'estasoft-news-distribution' ),
				$provider
			),
			true
		);
		$idle  = Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
			esc_html__( 'While a request runs, this card shows gray placeholders. When no suggestion follows the rule, it says so here and nothing is changed.', 'estasoft-news-distribution' )
		);
		$empty = '';

		if ( $none ) {
			$empty = Message::bar(
				'warning',
				Html::tag( 'strong', array(), esc_html__( 'The last request gave no suggestion that follows the rule, so none is shown.', 'estasoft-news-distribution' ) )
				. ' ' . esc_html__( 'Nothing was changed. Write one yourself, or ask again.', 'estasoft-news-distribution' ),
				Parts::form(
					$this->url( $post_id ),
					$this->slug(),
					'suggest',
					array(
						'post'                   => $post_id,
						'estasoft_nd_ai_feature' => 'fix',
					),
					Button::submit( __( 'Ask again', 'estasoft-news-distribution' ), array( 'size' => 'sm' ) )
				)
			);
		}

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-notes' ),
			Card::note( 'ai-how', __( 'How suggestions work', 'estasoft-news-distribution' ), $how )
			. Card::note( 'ai-wait', __( 'While it works, or finds nothing', 'estasoft-news-distribution' ), $working . $idle . $empty )
			. Card::cannot(
				array(
					__( 'Check that a suggestion is true. A person reads each one before using it.', 'estasoft-news-distribution' ),
					__( 'Make Google News show the article. Google decides.', 'estasoft-news-distribution' ),
					__( 'Change your headline unless you choose Use as headline.', 'estasoft-news-distribution' ),
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
