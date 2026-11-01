<?php
/**
 * The open feed readers channel page.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Channels;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Ui\AddressRow;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\Field;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Admin\Ui\Tag;
use Estasoft\NewsDistribution\Channels\FeedReaders\FeedBuilder;
use Estasoft\NewsDistribution\Channels\FeedReaders\FeedReadersChannel;
use Estasoft\NewsDistribution\Channels\FeedReaders\FlipboardChannel;
use Estasoft\NewsDistribution\Channels\SearchSitemap\FetchSummary;
use Estasoft\NewsDistribution\Channels\SearchSitemap\SitemapReport;
use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * `admin.php?page=estasoft-nd-channels&channel=feed-readers`. The feed address,
 * what the plugin can check about discovery, the readers that follow the feed
 * and the Flipboard topic for each category. The Flipboard channel has no page
 * of its own: it is part of this one.
 */
final class FeedReadersScreen extends AbstractChannelScreen {

	/**
	 * Most categories listed under "Flipboard topics".
	 */
	private const MAX_CATEGORIES = 60;

	public static function channel_id(): string {
		return 'feed-readers';
	}

	public function description(): string {
		return __( 'Flipboard, Feedly, Inoreader and NewsBlur read the same clean feed. News Distribution needs no application for them.', 'estasoft-news-distribution' );
	}

	public function enqueue( string $tab ): void {
		Assets::screen_style( 'address-row' );
		Assets::screen_style( 'feed-readers' );
		Assets::screen_script( 'address-row' );
	}

	public function render( string $tab ): string {
		$channel = $this->channel instanceof FeedReadersChannel ? $this->channel : null;

		if ( null === $channel ) {
			return '';
		}

		$reports = SitemapReport::for_channel( $this->plugin, $channel );
		$main    = $this->feed_card( $reports ) . $this->discovery_card( $channel, $reports ) . $this->readers_card( $channel );
		$side    = $this->topics_card();
		$columns = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-split' ),
			Html::tag( 'div', array( 'class' => 'estasoft-nd-split__main' ), $main ) . Html::tag( 'div', array( 'class' => 'estasoft-nd-split__side' ), $side )
		);
		$cannot  = Card::note(
			'cannot',
			__( 'What it cannot do', 'estasoft-news-distribution' ),
			Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted' ),
				esc_html__( 'It cannot make anyone follow your feed, choose where Flipboard places your stories, or show how many people read it in Feedly, Inoreader or NewsBlur.', 'estasoft-news-distribution' )
			)
		);

		return $this->form( $columns . $cannot );
	}

	/**
	 * "Your feed": one address per language, each with Copy.
	 *
	 * @param array<string, array<string, mixed>> $reports Language => report.
	 * @return string
	 */
	private function feed_card( array $reports ): string {
		$rows = '';

		foreach ( $this->plugin->languages()->languages() as $language ) {
			$language = (string) $language;

			if ( isset( $reports[ $language ] ) ) {
				$rows .= Html::tag( 'div', array( 'class' => 'estasoft-nd-address-row' ), AddressRow::language( $language, (string) $reports[ $language ]['url'], __( 'feed', 'estasoft-news-distribution' ) ) );
				continue;
			}

			$rows .= Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-address-row' ),
				Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-12' ),
					Tag::lang( $language ) . Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Turned off for this language in the Languages settings.', 'estasoft-news-distribution' ) )
				)
			);
		}

		return Card::flush(
			'feed',
			__( 'Your feed', 'estasoft-news-distribution' ),
			__( 'One standard full-text feed per language. Any reader can follow it.', 'estasoft-news-distribution' ),
			$rows
		);
	}

	/**
	 * "Discovery checks": what the plugin can tell about how readers find the feed.
	 * Each line is true or not for this site now; none of them reads a reader's side.
	 *
	 * @param FeedReadersChannel                  $channel The channel.
	 * @param array<string, array<string, mixed>> $reports Language => report.
	 * @return string
	 */
	private function discovery_card( FeedReadersChannel $channel, array $reports ): string {
		$checks = array();

		foreach ( $reports as $language => $report ) {
			$checks[ $language ] = FeedBuilder::check( (string) $report['body'], $channel->items() );
		}

		$with_items = array_filter(
			$checks,
			static function ( array $check ): bool {
				return $check['entries'] > 0;
			}
		);
		$items      = array();

		$items[] = $this->advertised_line( $channel );
		$items[] = $this->full_text_line( $checks, $with_items );
		$items[] = $this->valid_line( $reports );
		$items[] = $this->hub_line( $checks );

		$list = '';

		foreach ( $items as $item ) {
			$list .= Html::tag( 'li', array( 'class' => 'estasoft-nd-rule estasoft-nd-rule--' . $item['state'] ), Icon::svg( $item['icon'] ) . Html::tag( 'span', array(), esc_html( $item['text'] ) ) );
		}

		return Card::flush(
			'discovery',
			__( 'Discovery checks', 'estasoft-news-distribution' ),
			'',
			Html::tag( 'ul', array( 'class' => 'estasoft-nd-address-row estasoft-nd-gap-8' ), $list )
			. $this->rows( array( 'advertise' ) )
		);
	}

	/**
	 * Whether the page head advertises the feed.
	 *
	 * @param FeedReadersChannel $channel The channel.
	 * @return array{state: string, icon: string, text: string}
	 */
	private function advertised_line( FeedReadersChannel $channel ): array {
		if ( $channel->advertised() ) {
			return array(
				'state' => 'ok',
				'icon'  => 'check-circle',
				'text'  => __( 'The feed is advertised on every page, so readers find it from any article', 'estasoft-news-distribution' ),
			);
		}

		return array(
			'state' => 'off',
			'icon'  => 'minus-circle',
			'text'  => __( 'The feed is not advertised on your pages, so readers cannot find it from an article. Tick the box below to advertise it.', 'estasoft-news-distribution' ),
		);
	}

	/**
	 * Whether every item of every feed carries its full text.
	 *
	 * @param array<string, array<string, mixed>> $checks     Language => feed check.
	 * @param array<string, array<string, mixed>> $with_items The checks of feeds that have items.
	 * @return array{state: string, icon: string, text: string}
	 */
	private function full_text_line( array $checks, array $with_items ): array {
		unset( $checks );

		if ( array() === $with_items ) {
			return array(
				'state' => 'off',
				'icon'  => 'minus-circle',
				'text'  => __( 'No articles in the feed yet, so the full text could not be checked', 'estasoft-news-distribution' ),
			);
		}

		foreach ( $with_items as $check ) {
			if ( ! $check['full_text'] ) {
				return array(
					'state' => 'bad',
					'icon'  => 'warning',
					'text'  => __( 'Some items have no full text. Readers show only the title.', 'estasoft-news-distribution' ),
				);
			}
		}

		return array(
			'state' => 'ok',
			'icon'  => 'check-circle',
			'text'  => __( 'Full text in every item', 'estasoft-news-distribution' ),
		);
	}

	/**
	 * Whether every feed is valid RSS.
	 *
	 * @param array<string, array<string, mixed>> $reports Language => report.
	 * @return array{state: string, icon: string, text: string}
	 */
	private function valid_line( array $reports ): array {
		foreach ( $reports as $language => $report ) {
			if ( ! $report['valid'] ) {
				return array(
					'state' => 'bad',
					'icon'  => 'warning',
					'text'  => strtoupper( (string) $language ) . ': ' . (string) ( $report['problems'][0] ?? __( 'The feed is not valid RSS.', 'estasoft-news-distribution' ) ),
				);
			}
		}

		return array(
			'state' => 'ok',
			'icon'  => 'check-circle',
			'text'  => __( 'Valid RSS', 'estasoft-news-distribution' ),
		);
	}

	/**
	 * Whether the feeds name a WebSub hub. No hub is not a fault: the WebSub channel adds one.
	 *
	 * @param array<string, array<string, mixed>> $checks Language => feed check.
	 * @return array{state: string, icon: string, text: string}
	 */
	private function hub_line( array $checks ): array {
		$all = array() !== $checks;

		foreach ( $checks as $check ) {
			$all = $all && $check['hub'];
		}

		if ( $all ) {
			return array(
				'state' => 'ok',
				'icon'  => 'check-circle',
				'text'  => __( 'WebSub hub link in the feed', 'estasoft-news-distribution' ),
			);
		}

		return array(
			'state' => 'off',
			'icon'  => 'minus-circle',
			'text'  => __( 'No WebSub hub link in the feed. It is added when the WebSub channel is on and has a hub.', 'estasoft-news-distribution' ),
		);
	}

	/**
	 * "Readers": who follows the feed, and Flipboard's feed address.
	 *
	 * @param FeedReadersChannel $channel The channel.
	 * @return string
	 */
	private function readers_card( FeedReadersChannel $channel ): string {
		$flipboard = $this->plugin->registry()->get( 'flipboard' );
		$hub       = '' !== $channel->websub_hub();
		$found     = $channel->advertised()
			? __( 'Your feed is advertised on every page.', 'estasoft-news-distribution' )
			: __( 'Your feed is not advertised on your pages.', 'estasoft-news-distribution' );
		$source    = (string) ( $this->plugin->rules()->rule( 'flipboard', 'program.publisher' )['source'] ?? '' );
		$rows      = '';

		if ( $flipboard instanceof FlipboardChannel ) {
			$last  = $flipboard->last_fetch();
			$lines = ( $last > 0
				? sprintf(
					/* translators: 1: how long ago Flipboard fetched the feed, such as "31 min ago"; 2: number of items in the feed. */
					_n( 'Fetched %1$s, %2$s item.', 'Fetched %1$s, %2$s items.', $flipboard->items(), 'estasoft-news-distribution' ),
					FetchSummary::ago( $last ),
					number_format_i18n( $flipboard->items() )
				)
				: __( 'Not fetched yet.', 'estasoft-news-distribution' ) )
				. ' ' . __( 'Gets image sizes and a suggested topic with each article.', 'estasoft-news-distribution' );

			$rows .= $this->reader( __( 'Flipboard', 'estasoft-news-distribution' ), '' !== $source ? $source : 'https://about.flipboard.com/', $lines, $this->flipboard_addresses( $flipboard ) );
		}

		$rows .= $this->reader( __( 'Feedly', 'estasoft-news-distribution' ), 'https://feedly.com/', $found . ' ' . __( 'Followed by address or site search.', 'estasoft-news-distribution' ) );
		$rows .= $this->reader( __( 'Inoreader', 'estasoft-news-distribution' ), 'https://www.inoreader.com/', $found . ' ' . __( 'Followed by address or site search.', 'estasoft-news-distribution' ) );
		$rows .= $this->reader(
			__( 'NewsBlur', 'estasoft-news-distribution' ),
			'https://newsblur.com/',
			$found . ' ' . ( $hub ? __( 'Can use the WebSub link in the feed for faster updates.', 'estasoft-news-distribution' ) : __( 'Followed by address or site search.', 'estasoft-news-distribution' ) )
		);

		return Card::flush(
			'readers',
			__( 'Readers', 'estasoft-news-distribution' ),
			__( 'People follow your feed by pasting its address or searching for your site. Flipboard also has a publisher program on its own site.', 'estasoft-news-distribution' ),
			$rows
		);
	}

	/**
	 * One reader's row: its name as a link, a line about it, and its status.
	 *
	 * @param string $name  The reader's name.
	 * @param string $url   Its site.
	 * @param string $line  One plain sentence.
	 * @param string $extra Trusted HTML shown under the sentence, or empty.
	 * @return string
	 */
	private function reader( string $name, string $url, string $line, string $extra = '' ): string {
		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-reader' ),
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4 estasoft-nd-grow' ),
				Html::tag(
					'a',
					array(
						'href'   => $url,
						'target' => '_blank',
						'rel'    => 'noopener noreferrer',
						'class'  => 'estasoft-nd-strong',
					),
					esc_html( $name )
					. Html::tag( 'span', array( 'class' => 'estasoft-nd-sr' ), ' ' . esc_html__( '(opens in a new tab)', 'estasoft-news-distribution' ) )
				)
				. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $line ) )
				. $extra
			)
			. StatusView::pill( Status::HEALTHY )
		);
	}

	/**
	 * The Flipboard feed addresses, in a disclosure so the row stays short.
	 *
	 * @param FlipboardChannel $flipboard The Flipboard channel.
	 * @return string
	 */
	private function flipboard_addresses( FlipboardChannel $flipboard ): string {
		$rows = '';

		foreach ( $this->plugin->languages()->languages() as $language ) {
			if ( $flipboard->accepts_language( (string) $language ) ) {
				$rows .= AddressRow::language( (string) $language, $flipboard->feed_url( (string) $language ), __( 'Flipboard feed', 'estasoft-news-distribution' ) );
			}
		}

		if ( '' === $rows ) {
			return '';
		}

		return Html::tag(
			'details',
			array( 'class' => 'estasoft-nd-reader__details' ),
			Html::tag( 'summary', array( 'class' => 'estasoft-nd-small' ), esc_html__( 'Flipboard feed addresses', 'estasoft-news-distribution' ) )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $rows )
		);
	}

	/**
	 * "Flipboard topics": a topic to suggest for each category.
	 *
	 * @return string
	 */
	private function topics_card(): string {
		$terms = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
				'number'     => self::MAX_CATEGORIES,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		$terms = is_array( $terms ) ? $terms : array();
		$saved = (array) ( $this->values()['topics'] ?? array() );
		$rows  = '';

		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			$id      = 'estasoft-nd-topic-' . (int) $term->term_id;
			$chosen  = (string) ( $saved[ (string) $term->term_id ] ?? 'none' );
			$options = '';

			foreach ( FeedReadersChannel::topic_options() as $key => $label ) {
				$options .= Html::tag(
					'option',
					array(
						'value'    => $key,
						'selected' => (string) $key === $chosen,
					),
					esc_html( $label )
				);
			}

			$rows .= Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-address-row' ),
				Html::tag(
					'label',
					array(
						'class' => 'estasoft-nd-strong',
						'for'   => $id,
					),
					esc_html( $term->name )
				)
				. Html::tag(
					'select',
					array(
						'id'    => $id,
						'name'  => Field::name( 'topics', (string) $term->term_id ),
						'class' => 'estasoft-nd-field estasoft-nd-topic',
					),
					$options
				)
			);
		}

		if ( '' === $rows ) {
			$rows = Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-address-row' ),
				Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Your site has no categories yet. Add some to suggest a topic for each.', 'estasoft-news-distribution' ) )
			);
		}

		return Card::flush(
			'topics',
			__( 'Flipboard topics', 'estasoft-news-distribution' ),
			__( 'Suggest a Flipboard topic for each of your categories. Flipboard tags stories itself and makes the final choice.', 'estasoft-news-distribution' ),
			$rows
		);
	}
}
