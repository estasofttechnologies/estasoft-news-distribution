<?php
/**
 * The default tab of an article: every check, and what each channel does with it.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\CheckTabs;

use Estasoft\NewsDistribution\Admin\Check\ChannelRows;
use Estasoft\NewsDistribution\Admin\Check\Context;
use Estasoft\NewsDistribution\Admin\Check\Findings;
use Estasoft\NewsDistribution\Admin\Check\Links;
use Estasoft\NewsDistribution\Admin\Check\Times;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\EmptyState;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\ArticleService;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Two parts. First the article itself: body, dates, title, featured image, byline and
 * clashes, each with a status and the findings the checks raised. Then one row for each
 * channel that is on, in the groups of the Channels hub, saying what happened to the
 * article there and why.
 *
 * Run again checks the article now and stores the result. With the script it posts to
 * `POST /articles/{id}/check`; without it, the form posts to this page.
 */
final class CheckTab extends AbstractCheckTab {

	public const NONCE = '_estasoft_nd_nonce';

	public function slug(): string {
		return 'checks';
	}

	public function title(): string {
		return __( 'Checks', 'estasoft-news-distribution' );
	}

	public function description(): string {
		return __( 'Why each article is, or is not, on each channel, and how to fix it.', 'estasoft-news-distribution' );
	}

	public function order(): int {
		return 10;
	}

	/**
	 * Runs the checks when the Run again form was posted without the script.
	 *
	 * @param int $post_id The article.
	 * @return void
	 */
	public function handle( int $post_id ): void {
		if ( $post_id < 1 || ! isset( $_POST['estasoft_nd_check_run'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only asks whether the form was posted; the nonce is checked next.
			return;
		}

		check_admin_referer( 'estasoft_nd_check_run_' . $post_id, self::NONCE );

		if ( absint( wp_unslash( $_POST['estasoft_nd_check_run'] ) ) !== $post_id ) {
			return;
		}

		$this->plugin->access()->require_or_die( Access::VIEW_OWN, $post_id );
		$this->plugin->make( ArticleService::class )->check( $post_id, true );

		wp_safe_redirect( $this->url( $post_id, array( 'checked' => 1 ) ) );
		exit;
	}

	public function render( int $post_id ): string {
		$ctx = new Context( $this->plugin, $post_id );

		if ( ! $ctx->exists() ) {
			return EmptyState::render(
				__( 'This is not an article News Distribution sends', 'estasoft-news-distribution' ),
				__( 'Its post type is not one of the types chosen in Settings, General.', 'estasoft-news-distribution' )
			);
		}

		$links = new Links( $this->plugin );
		$rows  = new ChannelRows( $this->plugin, $ctx, $links );
		$body  = $this->toolbar( $ctx ) . $this->notices( $ctx ) . $this->content_rows( $ctx, $links ) . $rows->render();

		return Html::tag(
			'div',
			array(
				'class'             => 'estasoft-nd-checkbody',
				'data-check-region' => '1',
				'data-post'         => (string) $post_id,
			),
			$body
		);
	}

	/**
	 * Counts by severity, when the article was checked, and the Run again button.
	 *
	 * @param Context $ctx The article.
	 * @return string Escaped HTML.
	 */
	private function toolbar( Context $ctx ): string {
		$counts = (array) ( $ctx->check()['counts'] ?? array() );
		$state  = '';

		if ( ! $ctx->was_checked() ) {
			$state = Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Not checked yet.', 'estasoft-news-distribution' ) );
		} elseif ( array() === $ctx->findings() ) {
			$state = StatusView::pill( Status::CLEAN, __( 'Nothing to fix', 'estasoft-news-distribution' ) );
		} else {
			foreach ( array( Status::BLOCK, Status::WARN, Status::INFO ) as $severity ) {
				$count = (int) ( $counts[ $severity ] ?? 0 );

				if ( $count > 0 ) {
					$state .= Html::tag(
						'span',
						array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-4' ),
						StatusView::pill( $severity ) . Html::tag( 'strong', array(), esc_html( number_format_i18n( $count ) ) )
					);
				}
			}
		}

		$when = $ctx->was_checked()
			? Html::tag(
				'span',
				array(
					'class'     => 'estasoft-nd-muted estasoft-nd-small',
					'data-when' => '1',
				),
				esc_html(
					sprintf(
						/* translators: %s: how long ago, such as "12 min ago". */
						__( 'Checked %s', 'estasoft-news-distribution' ),
						lcfirst( Times::ago( $ctx->checked_at() ) )
					)
				)
			)
			: '';

		$form = Html::tag(
			'form',
			array(
				'class'  => 'estasoft-nd-runform',
				'method' => 'post',
				'action' => $this->url( $ctx->id() ),
			),
			Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'estasoft_nd_check_run',
					'value' => (string) $ctx->id(),
				)
			)
			. Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => self::NONCE,
					'value' => wp_create_nonce( 'estasoft_nd_check_run_' . $ctx->id() ),
				)
			)
			. Button::submit(
				__( 'Run again', 'estasoft-news-distribution' ),
				array(
					'icon'  => 'retry',
					'attrs' => array(
						'data-run-check' => '1',
						'data-post'      => (string) $ctx->id(),
					),
				)
			)
		);

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-toolbar' ),
			Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-12 estasoft-nd-grow' ), $state . $when )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ), $form )
			. Html::tag(
				'p',
				array(
					'class'     => 'estasoft-nd-small estasoft-nd-muted estasoft-nd-hidden',
					'role'      => 'status',
					'data-live' => '1',
				),
				''
			)
		);
	}

	/**
	 * Messages above the rows: a check just run, an article that is not published.
	 *
	 * @param Context $ctx The article.
	 * @return string Escaped HTML.
	 */
	private function notices( Context $ctx ): string {
		$html = '';

		// A redirect after the Run again form only carries a flag, which changes nothing.
		if ( Query::is( 'checked', '1' ) ) {
			$html .= Html::tag( 'div', array( 'class' => 'estasoft-nd-pad' ), Message::text( 'success', __( 'Checked just now.', 'estasoft-news-distribution' ) ) );
		}

		if ( ! $ctx->is_published() ) {
			$post = $ctx->post();
			$word = null !== $post && 'future' === $post->post_status ? __( 'scheduled', 'estasoft-news-distribution' ) : __( 'not published yet', 'estasoft-news-distribution' );

			$html .= Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-pad' ),
				Message::text(
					'info',
					sprintf(
						/* translators: %s: "scheduled" or "not published yet". */
						__( 'This article is %s. The rows below show what each channel would do with it as it is.', 'estasoft-news-distribution' ),
						$word
					)
				)
			);
		}

		if ( ! $ctx->was_checked() ) {
			$html .= Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-pad' ),
				Message::text( 'info', __( 'This article has not been checked yet. Press Run again to check it now. The check also runs when the article is saved.', 'estasoft-news-distribution' ) )
			);
		}

		return $html;
	}

	/**
	 * The "Article content" group: one row for each area the checks look at.
	 *
	 * @param Context $ctx   The article.
	 * @param Links   $links Addresses of other screens.
	 * @return string Escaped HTML, empty until the article has been checked.
	 */
	private function content_rows( Context $ctx, Links $links ): string {
		if ( ! $ctx->was_checked() ) {
			return '';
		}

		$labels = array();

		foreach ( $this->plugin->registry()->all() as $channel ) {
			$labels[ $channel->id() ] = $channel->label();
		}

		$by   = Findings::by_area( $ctx->findings() );
		$html = ChannelRows::strip( __( 'Article content', 'estasoft-news-distribution' ) );

		foreach ( Findings::areas() as $area => $name ) {
			$items = $by[ $area ] ?? array();

			if ( 'other' === $area && array() === $items ) {
				continue;
			}

			$html .= ChannelRows::row(
				esc_html( $name ),
				$this->area_pill( $area, $items, $ctx ),
				$this->area_body( $area, $items, $ctx, $labels ),
				'image' === $area && array() !== $items ? $this->assign_link( $ctx, $links ) : ''
			);
		}

		return $html;
	}

	/**
	 * The status of one area: the worst finding, the date guard's decision for dates, otherwise clean.
	 *
	 * @param string                           $area  Area id.
	 * @param array<int, array<string, mixed>> $items Findings in the area.
	 * @param Context                          $ctx   The article.
	 * @return string Escaped HTML.
	 */
	private function area_pill( string $area, array $items, Context $ctx ): string {
		$worst = Findings::worst( $items );

		if ( '' !== $worst ) {
			return StatusView::pill( $worst );
		}

		$guard = $ctx->check()['date_guard'] ?? null;

		if ( 'date' === $area && is_array( $guard ) && '' !== (string) ( $guard['status'] ?? '' ) ) {
			return StatusView::pill( (string) $guard['status'] );
		}

		return StatusView::pill( Status::CLEAN );
	}

	/**
	 * What an area row says: a fact about the article, then the findings.
	 *
	 * @param string                           $area   Area id.
	 * @param array<int, array<string, mixed>> $items  Findings in the area.
	 * @param Context                          $ctx    The article.
	 * @param array<string, string>            $labels Channel id => name.
	 * @return string Escaped HTML.
	 */
	private function area_body( string $area, array $items, Context $ctx, array $labels ): string {
		$fact = $this->fact( $area, array() === $items, $ctx );

		return ( '' === $fact ? '' : Html::tag( 'p', array(), esc_html( $fact ) ) )
			. Findings::list( $items, $ctx->id(), false, $labels );
	}

	/**
	 * One sentence of fact about the article for an area.
	 *
	 * @param string  $area  Area id.
	 * @param bool    $clean Whether the area has no findings.
	 * @param Context $ctx   The article.
	 * @return string Plain text, empty when there is nothing true to say.
	 */
	private function fact( string $area, bool $clean, Context $ctx ): string {
		$article = $ctx->article();

		if ( null === $article ) {
			return '';
		}

		switch ( $area ) {
			case 'body':
				$text = sprintf(
					/* translators: %s: a number of words. */
					_n( 'The body has %s word.', 'The body has %s words.', $article->word_count(), 'estasoft-news-distribution' ),
					number_format_i18n( $article->word_count() )
				);

				return $clean ? $text . ' ' . __( 'No leftover shortcodes, layout markup or empty blocks were found.', 'estasoft-news-distribution' ) : $text;

			case 'title':
				$text = sprintf(
					/* translators: %s: a number of characters. */
					_n( 'The title is %s character.', 'The title is %s characters.', mb_strlen( $article->title() ), 'estasoft-news-distribution' ),
					number_format_i18n( mb_strlen( $article->title() ) )
				);

				return $clean ? $text . ' ' . __( 'No publication name, author name or date was found in it.', 'estasoft-news-distribution' ) : $text;

			case 'image':
				if ( $article->thumbnail_width() < 1 || $article->thumbnail_height() < 1 ) {
					return '';
				}

				return sprintf(
					/* translators: 1: width in pixels, 2: height in pixels. */
					__( 'The featured image is %1$s × %2$s px.', 'estasoft-news-distribution' ),
					number_format_i18n( $article->thumbnail_width() ),
					number_format_i18n( $article->thumbnail_height() )
				);

			case 'date':
				return $this->date_fact( $ctx );

			case 'byline':
				return sprintf(
					/* translators: %s: a person's name. */
					__( 'The author is shown as %s.', 'estasoft-news-distribution' ),
					$article->author_name()
				);

			case 'duplicate':
				return $clean ? __( 'No clash found with other articles.', 'estasoft-news-distribution' ) : '';
		}

		return '';
	}

	/**
	 * What the date guard did with the last save, or when the article was published.
	 *
	 * @param Context $ctx The article.
	 * @return string Plain text.
	 */
	private function date_fact( Context $ctx ): string {
		$guard = $ctx->check()['date_guard'] ?? null;

		if ( is_array( $guard ) && '' !== (string) ( $guard['decision'] ?? '' ) ) {
			$at       = Times::from_iso( $guard['at'] ?? null );
			$modified = Times::from_iso( $guard['modified'] ?? null );
			$previous = Times::from_iso( $guard['previous'] ?? null );
			$what     = lcfirst( (string) $guard['description'] );

			if ( 'kept' === $guard['decision'] && $previous > 0 ) {
				return sprintf(
					/* translators: 1: a time such as "14:32", 2: what changed, 3: a time such as "13:41". */
					__( 'The last save, at %1$s (%2$s), kept the modified date at %3$s.', 'estasoft-news-distribution' ),
					Times::at( $at ),
					$what,
					Times::at( $previous )
				);
			}

			if ( 'moved' === $guard['decision'] && $modified > 0 ) {
				return sprintf(
					/* translators: 1: a time such as "14:32", 2: what changed, 3: a time such as "14:32". */
					__( 'The last save, at %1$s (%2$s), moved the modified date to %3$s.', 'estasoft-news-distribution' ),
					Times::at( $at ),
					$what,
					Times::at( $modified )
				);
			}
		}

		$article = $ctx->article();

		if ( null === $article || ! $ctx->is_published() ) {
			return __( 'A draft has no publication date yet.', 'estasoft-news-distribution' );
		}

		return sprintf(
			/* translators: %s: a date and time such as "Today, 13:41". */
			__( 'Published %s.', 'estasoft-news-distribution' ),
			lcfirst( Times::day( $article->published_ts() ) )
		);
	}

	/**
	 * The link that hands the notes about the image to someone, when the assign tab exists.
	 *
	 * @param Context $ctx   The article.
	 * @param Links   $links Addresses of other screens.
	 * @return string Escaped HTML.
	 */
	private function assign_link( Context $ctx, Links $links ): string {
		$url = $links->tab( 'assign', $ctx->id() );

		return '' === $url ? '' : Html::tag( 'a', array( 'href' => $url ), esc_html__( 'Assign these notes', 'estasoft-news-distribution' ) );
	}
}
