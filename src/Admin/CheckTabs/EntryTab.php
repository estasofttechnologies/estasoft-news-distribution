<?php
/**
 * What a channel would be given for one article, as text.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\CheckTabs;

use Estasoft\NewsDistribution\Admin\Check\ChannelRows;
use Estasoft\NewsDistribution\Admin\Check\Context;
use Estasoft\NewsDistribution\Admin\Check\Findings;
use Estasoft\NewsDistribution\Admin\Check\Links;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Code;
use Estasoft\NewsDistribution\Admin\Ui\EmptyState;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Channels\ChannelInterface;
use Estasoft\NewsDistribution\Channels\Group;
use Estasoft\NewsDistribution\Core\ArticleService;
use Estasoft\NewsDistribution\Core\Language\Catalog;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * `&tab=entry&entry=<channel>`. The entry is built now from the article, by the same
 * `Core\ArticleService::preview()` that `GET /articles/{id}/preview/{channel}` answers with. Nothing is
 * stored and nothing is sent. Beside it are the checks that apply to that entry, each
 * with the rule it comes from.
 */
final class EntryTab extends AbstractCheckTab {

	/**
	 * Most characters of an entry shown on the screen.
	 */
	private const MAX_SHOWN = 60000;

	public function slug(): string {
		return 'entry';
	}

	public function title(): string {
		return __( 'Feed entry', 'estasoft-news-distribution' );
	}

	public function description(): string {
		return __( 'What News Distribution builds for each channel from this article.', 'estasoft-news-distribution' );
	}

	public function order(): int {
		return 20;
	}

	public function render( int $post_id ): string {
		$ctx = new Context( $this->plugin, $post_id );

		if ( ! $ctx->exists() || null === $ctx->article() ) {
			return EmptyState::render(
				__( 'No entry to show', 'estasoft-news-distribution' ),
				__( 'This is not an article News Distribution sends.', 'estasoft-news-distribution' )
			);
		}

		$channels = $this->channels( $ctx );

		if ( array() === $channels ) {
			return EmptyState::render(
				__( 'No channel builds an entry for this article', 'estasoft-news-distribution' ),
				__( 'Turn a channel on in Channels, and its entry appears here.', 'estasoft-news-distribution' )
			);
		}

		$wanted  = Query::key( 'entry' );
		$channel = $channels[ $wanted ] ?? reset( $channels );
		$preview = $this->plugin->make( ArticleService::class )->preview( $post_id, $channel->id() );

		if ( is_wp_error( $preview ) ) {
			return Message::text( 'danger', $preview->get_error_message() );
		}

		return $this->controls( $ctx, $channels, $channel )
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-split' ),
				Html::tag( 'div', array( 'class' => 'estasoft-nd-split__main' ), $this->entry_card( $ctx, $channel, $preview ) )
				. Html::tag( 'div', array( 'class' => 'estasoft-nd-split__side estasoft-nd-split__side--wide' ), $this->checks_card( $ctx, $channel, $preview ) )
			);
	}

	/**
	 * The channels that build an entry for this article: on, not set up by hand, and taking its language.
	 *
	 * @param Context $ctx The article.
	 * @return array<string, ChannelInterface> Channel id => channel, in hub order.
	 */
	private function channels( Context $ctx ): array {
		$order = array_flip( array_keys( Group::all() ) );
		$found = array();

		foreach ( $ctx->active() as $channel ) {
			if ( ChannelInterface::MODE_MANUAL !== $channel->mode() ) {
				$found[] = $channel;
			}
		}

		usort(
			$found,
			static function ( ChannelInterface $a, ChannelInterface $b ) use ( $order ): int {
				return array( $order[ $a->group() ] ?? 99, $a->order() ) <=> array( $order[ $b->group() ] ?? 99, $b->order() );
			}
		);

		$out = array();

		foreach ( $found as $channel ) {
			$out[ $channel->id() ] = $channel;
		}

		return $out;
	}

	/**
	 * The bar above the entry: which channel, which language, and Copy entry.
	 *
	 * @param Context                         $ctx      The article.
	 * @param array<string, ChannelInterface> $channels The channels to choose from.
	 * @param ChannelInterface                $current  The one shown.
	 * @return string Escaped HTML.
	 */
	private function controls( Context $ctx, array $channels, ChannelInterface $current ): string {
		$options = '';

		foreach ( $channels as $id => $channel ) {
			$options .= Html::tag(
				'option',
				array(
					'value'    => $id,
					'selected' => $id === $current->id(),
				),
				esc_html( $channel->label() )
			);
		}

		$field = function ( string $label, string $control, string $id ): string {
			return Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
				Html::tag(
					'label',
					array(
						'class' => 'estasoft-nd-label',
						'for'   => $id,
					),
					esc_html( $label )
				) . $control
			);
		};

		$hidden = '';

		foreach ( array(
			'page' => 'estasoft-nd-check',
			'post' => (string) $ctx->id(),
			'tab'  => 'entry',
		) as $name => $value ) {
			$hidden .= Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => $name,
					'value' => $value,
				)
			);
		}

		return Html::tag(
			'form',
			array(
				'class'      => 'estasoft-nd-card estasoft-nd-commandbar estasoft-nd-entrybar',
				'method'     => 'get',
				'action'     => admin_url( 'admin.php' ),
				'data-entry' => '1',
			),
			$hidden
			. $field(
				__( 'Channel', 'estasoft-news-distribution' ),
				Html::tag(
					'select',
					array(
						'class' => 'estasoft-nd-field',
						'id'    => 'estasoft-nd-entry-channel',
						'name'  => 'entry',
					),
					$options
				),
				'estasoft-nd-entry-channel'
			)
			. $field(
				__( 'Language', 'estasoft-news-distribution' ),
				Html::tag(
					'span',
					array(
						'class' => 'estasoft-nd-field estasoft-nd-field--static',
						'id'    => 'estasoft-nd-entry-language',
						'title' => __( 'The entry is built in the language of the article.', 'estasoft-news-distribution' ),
					),
					esc_html( Catalog::name( $ctx->language() ) )
				),
				'estasoft-nd-entry-language'
			)
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-end estasoft-nd-cluster estasoft-nd-gap-8' ),
				Button::submit( __( 'Show entry', 'estasoft-news-distribution' ), array( 'attrs' => array( 'data-entry-show' => '1' ) ) )
				. Button::button(
					__( 'Copy entry', 'estasoft-news-distribution' ),
					array(
						'icon'  => 'copy',
						'attrs' => array(
							'data-copy-entry' => '1',
							'hidden'          => true,
						),
					)
				)
			)
		);
	}

	/**
	 * The card with the entry as code, or the reason there is none.
	 *
	 * @param Context              $ctx     The article.
	 * @param ChannelInterface     $channel The channel.
	 * @param array<string, mixed> $preview The service's answer.
	 * @return string Escaped HTML.
	 */
	private function entry_card( Context $ctx, ChannelInterface $channel, array $preview ): string {
		$label = $channel->label();
		$entry = (string) $preview['entry'];
		$body  = '';
		$flags = array();

		if ( '' === $entry ) {
			$body = ChannelInterface::MODE_PUSHED === $channel->mode()
				? sprintf(
					/* translators: %s: a channel name. */
					__( '%s does not read a feed. It is sent a notice when the article is published or updated, so there is no entry to show here.', 'estasoft-news-distribution' ),
					$label
				)
				: sprintf(
					/* translators: %s: a channel name. */
					__( 'No entry was built for %s. The article may be one it leaves out; the checks beside this card say why.', 'estasoft-news-distribution' ),
					$label
				);

			$body = Message::text( 'info', $body );
		} else {
			list( $shown, $note ) = $this->excerpt( $entry );
			$article              = $ctx->article();

			if ( null !== $article && '' !== $article->thumbnail_url() ) {
				foreach ( preg_split( '/\r\n|\r|\n/', $shown ) as $index => $line ) {
					if ( $this->has_url( (string) $line, $article->thumbnail_url() ) ) {
						$flags[] = array(
							'line' => $index + 1,
							'tone' => 'ok',
						);
						break;
					}
				}
			}

			$body = Html::tag(
				'div',
				array(
					'class'      => 'estasoft-nd-entrycode',
					'data-entry' => 'code',
				),
				Code::block( $shown, sprintf( /* translators: %s: a channel name. */ __( 'Feed entry for %s', 'estasoft-news-distribution' ), $label ), $flags )
			) . ( '' === $note ? '' : Html::tag( 'p', array( 'class' => 'estasoft-nd-small estasoft-nd-muted' ), esc_html( $note ) ) );
		}

		$text = sprintf(
			/* translators: %s: a channel name. */
			__( 'Rebuilt from the article just now. This is what %s reads the next time it fetches the feed.', 'estasoft-news-distribution' ),
			$label
		);

		if ( array() !== $flags ) {
			$text .= ' ' . __( 'The line in green is the featured image.', 'estasoft-news-distribution' );
		}

		return Html::tag(
			'section',
			array(
				'class'           => 'estasoft-nd-card estasoft-nd-card--padded',
				'aria-labelledby' => 'estasoft-nd-entry-title',
			),
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
				Html::tag(
					'h2',
					array(
						'class' => 'estasoft-nd-title',
						'id'    => 'estasoft-nd-entry-title',
					),
					esc_html(
						sprintf(
							/* translators: %s: a channel name. */
							__( 'Entry for %s', 'estasoft-news-distribution' ),
							$label
						)
					)
				)
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( $text ) )
			)
			. $body
		);
	}

	/**
	 * The part of a document to show: the item, entry or url element for the article when the
	 * document is XML, otherwise all of it. Cut when it is very long.
	 *
	 * @param string $document What the channel built.
	 * @return array{0: string, 1: string} The text to show and a note about what was left out, or an empty note.
	 */
	private function excerpt( string $document ): array {
		$note = '';
		$text = $document;

		foreach ( array( 'item', 'entry', 'url' ) as $element ) {
			$start = strpos( $document, '<' . $element . '>' );
			$end   = strrpos( $document, '</' . $element . '>' );

			if ( false !== $start && false !== $end && $end > $start ) {
				$text = substr( $document, $start, $end + strlen( $element ) + 3 - $start );
				$note = __( 'Shown without the header of the feed or sitemap around it.', 'estasoft-news-distribution' );

				// The element sat one tab deep; take that tab off every line so the code starts at the margin.
				$line_start = (int) strrpos( substr( $document, 0, $start ), "\n" );
				$indent     = substr( $document, $line_start + 1, $start - $line_start - 1 );

				if ( '' !== $indent && '' === trim( $indent ) ) {
					$text = preg_replace( '/^' . preg_quote( $indent, '/' ) . '/m', '', $text );
				}

				break;
			}
		}

		if ( strlen( $text ) > self::MAX_SHOWN ) {
			$text = substr( $text, 0, self::MAX_SHOWN );
			$note = trim(
				$note . ' ' . sprintf(
					/* translators: %s: a number of characters. */
					__( 'Cut after %s characters.', 'estasoft-news-distribution' ),
					number_format_i18n( self::MAX_SHOWN )
				)
			);
		}

		return array( $text, $note );
	}

	/**
	 * "Checks for this entry": what the entry has, and what the rules say about this article.
	 *
	 * @param Context              $ctx     The article.
	 * @param ChannelInterface     $channel The channel.
	 * @param array<string, mixed> $preview The service's answer.
	 * @return string Escaped HTML.
	 */
	private function checks_card( Context $ctx, ChannelInterface $channel, array $preview ): string {
		$findings = Findings::sorted( (array) $preview['findings'] );
		$items    = $this->facts( $ctx, (string) $preview['entry'] );
		$notes    = '';
		$seen     = array();
		$list     = '';

		foreach ( $items as $item ) {
			$list .= Html::tag(
				'li',
				array( 'class' => 'estasoft-nd-finding estasoft-nd-finding--' . $item[0] ),
				Html::tag( 'span', array( 'class' => 'estasoft-nd-finding__icon' ), Icon::svg( $item[1] ) )
				. Html::tag( 'div', array( 'class' => 'estasoft-nd-grow' ), esc_html( $item[2] ) )
			);
		}

		foreach ( $findings as $finding ) {
			$list .= Findings::line( $finding, $ctx->id() );
			$rule  = $finding['rule'] ?? null;

			if ( is_array( $rule ) && '' !== (string) $rule['note'] && ! isset( $seen[ $rule['code'] ] ) && count( $seen ) < 2 ) {
				$seen[ $rule['code'] ] = true;
				$notes                .= Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( (string) $rule['note'] ) );

				if ( 0 === strpos( (string) $rule['source'], 'http' ) ) {
					$notes .= ChannelRows::source_line( $rule, $this->plugin->rules()->pack( (string) $rule['pack'] ) );
				}
			}
		}

		$verdict = '';

		if ( ! $preview['eligible'] ) {
			$verdict = Message::text(
				'danger',
				sprintf(
					/* translators: %s: a channel name. */
					__( '%s would skip this article as it is.', 'estasoft-news-distribution' ),
					$channel->label()
				)
			);
		}

		$back = ( new Links( $this->plugin ) )->tab( 'checks', $ctx->id() );

		return Html::tag(
			'section',
			array(
				'class'           => 'estasoft-nd-card estasoft-nd-card--padded',
				'aria-labelledby' => 'estasoft-nd-entrychecks',
			),
			Html::tag(
				'h2',
				array(
					'class' => 'estasoft-nd-title',
					'id'    => 'estasoft-nd-entrychecks',
				),
				esc_html__( 'Checks for this entry', 'estasoft-news-distribution' )
			)
			. $verdict
			. Html::tag( 'ul', array( 'class' => 'estasoft-nd-findings' ), $list )
			. $notes
			. ( '' === $back ? '' : Html::tag( 'p', array(), Html::tag( 'a', array( 'href' => $back ), esc_html__( 'Back to the checks', 'estasoft-news-distribution' ) ) ) )
		);
	}

	/**
	 * Whether a text holds an address, written as it is or escaped for XML.
	 *
	 * @param string $text The text.
	 * @param string $url  The address.
	 * @return bool
	 */
	private function has_url( string $text, string $url ): bool {
		return '' !== $url && ( false !== strpos( $text, $url ) || false !== strpos( $text, htmlspecialchars( $url, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) ) );
	}

	/**
	 * Facts about the entry that were looked for in it: the ID, the title, the address, the image.
	 *
	 * @param Context $ctx   The article.
	 * @param string  $entry What the channel built.
	 * @return array<int, array{0: string, 1: string, 2: string}> Tone, icon name and text.
	 */
	private function facts( Context $ctx, string $entry ): array {
		$article = $ctx->article();

		if ( null === $article || '' === $entry ) {
			return array();
		}

		$out = array();
		$id  = $this->plugin->article_ids()->get( $ctx->id() );

		if ( '' !== $id && false !== strpos( $entry, $id ) ) {
			$out[] = array( 'success', 'check-circle', __( 'Unique ID, kept when the article is updated or its address changes', 'estasoft-news-distribution' ) );
		}

		$title = htmlspecialchars( $article->title(), ENT_XML1 | ENT_QUOTES, 'UTF-8' );

		if ( '' !== $title && ( false !== strpos( $entry, $title ) || false !== strpos( $entry, wp_json_encode( $article->title(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) ) ) {
			$out[] = array( 'success', 'check-circle', __( 'Title is in the entry', 'estasoft-news-distribution' ) );
		} else {
			$out[] = array( 'warning', 'warning', __( 'The title was not found in the entry', 'estasoft-news-distribution' ) );
		}

		if ( '' !== $article->permalink() && $this->has_url( $entry, $article->permalink() ) ) {
			$out[] = array( 'success', 'check-circle', __( 'Address of the article is in the entry', 'estasoft-news-distribution' ) );
		}

		if ( '' !== $article->thumbnail_url() ) {
			$size = $article->thumbnail_width() > 0 && $article->thumbnail_height() > 0
				? sprintf(
					/* translators: 1: width in pixels, 2: height in pixels. */
					__( ', %1$s × %2$s px', 'estasoft-news-distribution' ),
					number_format_i18n( $article->thumbnail_width() ),
					number_format_i18n( $article->thumbnail_height() )
				)
				: '';

			// Some channels carry no image in the entry at all; a missing one is reported by the findings, not here.
			if ( $this->has_url( $entry, $article->thumbnail_url() ) ) {
				$out[] = array( 'success', 'check-circle', __( 'Featured image is in the entry', 'estasoft-news-distribution' ) . $size );
			}
		}

		$out[] = array(
			'success',
			'check-circle',
			sprintf(
				/* translators: %s: a language name such as "English". */
				__( 'Entry is in %s', 'estasoft-news-distribution' ),
				Catalog::name( $ctx->language() )
			),
		);

		return $out;
	}
}
