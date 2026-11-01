<?php
/**
 * The Google News channel page.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Channels;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\Tag;
use Estasoft\NewsDistribution\Channels\GoogleNews\GoogleNewsChannel;
use Estasoft\NewsDistribution\Channels\SearchSitemap\FetchSummary;
use Estasoft\NewsDistribution\Channels\SearchSitemap\SitemapReport;
use Estasoft\NewsDistribution\Core\Language\Catalog;
use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * `admin.php?page=estasoft-nd-channels&channel=google-news`. Google News takes a
 * news sitemap per language. The page shows each address, whether it is valid,
 * when Google last fetched it, the steps to submit it and the rules applied.
 * News Distribution cannot read Search Console, so the steps are plain text.
 */
final class GoogleNewsScreen extends AbstractSitemapScreen {

	public static function channel_id(): string {
		return 'google-news';
	}

	public function description(): string {
		return __( 'Pulled channel: News Distribution builds the sitemaps and Google fetches them on its own schedule. Google decides whether an article appears in Google News.', 'estasoft-news-distribution' );
	}

	public function enqueue( string $tab ): void {
		parent::enqueue( $tab );
		Assets::screen_style( 'google-news' );
	}

	protected function what(): string {
		return __( 'news sitemap', 'estasoft-news-distribution' );
	}

	protected function crawler_name(): string {
		return __( 'Google', 'estasoft-news-distribution' );
	}

	protected function valid_text( int $entries ): string {
		return sprintf(
			/* translators: %s: number of articles in the sitemap. */
			_n( 'Valid, %s article', 'Valid, %s articles', $entries, 'estasoft-news-distribution' ),
			number_format_i18n( $entries )
		);
	}

	public function render( string $tab ): string {
		$channel = $this->channel instanceof GoogleNewsChannel ? $this->channel : null;

		if ( null === $channel ) {
			return '';
		}

		$reports = SitemapReport::for_channel( $this->plugin, $channel );
		$fetch   = $channel->fetch_report();
		$main    = $this->sitemaps_card( $reports, $fetch ) . $this->attention( $fetch ) . $this->submit_card();
		$side    = $this->rules_card(
			$channel->id(),
			__( 'Google', 'estasoft-news-distribution' ),
			array(
				'news.window'         => sprintf(
					/* translators: %s: number of days, such as 2. */
					_n( 'Only articles from the last %s day', 'Only articles from the last %s days', max( 1, (int) round( $channel->window_seconds() / DAY_IN_SECONDS ) ), 'estasoft-news-distribution' ),
					number_format_i18n( max( 1, (int) round( $channel->window_seconds() / DAY_IN_SECONDS ) ) )
				),
				'news.max_urls'       => sprintf(
					/* translators: %s: largest number of articles in one sitemap, such as 1,000. */
					__( 'At most %s articles per sitemap', 'estasoft-news-distribution' ),
					number_format_i18n( $channel->max_urls() )
				),
				'entry.required_tags' => __( 'Publication name, language, date and title on every entry', 'estasoft-news-distribution' ),
				'title.has_author|title.has_publication_name|title.has_date' => __( 'Titles without the author, publication name or date', 'estasoft-news-distribution' ),
				'language.file'       => __( 'One file per language, updated as you publish', 'estasoft-news-distribution' ),
			)
		) . $this->name_card( $channel ) . $this->cannot_paragraph(
			__( 'It cannot make Google fetch a sitemap sooner, choose which articles Google News shows, or read Search Console. Google decides whether an article appears, in any language. The fetch times above are the requests Google makes to your sitemap.', 'estasoft-news-distribution' )
		);

		return $this->head() . $this->split( $main, $side );
	}

	/**
	 * "Your news sitemaps": one row per language.
	 *
	 * @param array<string, array<string, mixed>> $reports Language => report.
	 * @param array<string, array<string, mixed>> $fetch   Language => fetch state.
	 * @return string
	 */
	private function sitemaps_card( array $reports, array $fetch ): string {
		$total  = 0;
		$counts = array();

		foreach ( $reports as $language => $report ) {
			$total   += (int) $report['entries'];
			$counts[] = Catalog::tag( (string) $language ) . ' ' . number_format_i18n( (int) $report['entries'] );
		}

		$intro = sprintf(
			/* translators: 1: number of articles in the sitemaps now, such as "18 articles"; 2: the count for each language, such as "EN 9, KU 4, AR 5". */
			__( 'One sitemap per language. Only articles from the last two days are included: %1$s now (%2$s).', 'estasoft-news-distribution' ),
			sprintf(
				/* translators: %s: number of articles. */
				_n( '%s article', '%s articles', $total, 'estasoft-news-distribution' ),
				number_format_i18n( $total )
			),
			implode( ', ', $counts )
		);

		return Card::flush( 'sitemaps', __( 'Your news sitemaps', 'estasoft-news-distribution' ), $intro, $this->language_rows( $reports, $fetch ) );
	}

	/**
	 * A warning bar when a language was never fetched or is later than usual.
	 *
	 * @param array<string, array<string, mixed>> $fetch Language => fetch state.
	 * @return string
	 */
	private function attention( array $fetch ): string {
		if ( ! $this->channel->is_enabled() ) {
			return '';
		}

		$lines = array();

		foreach ( $fetch as $language => $state ) {
			$name = self::language_label( (string) $language );

			if ( Status::NEVER === $state['status'] ) {
				$lines[] = sprintf(
					/* translators: %s: a language name such as Arabic. */
					__( 'Google has never fetched the %s sitemap.', 'estasoft-news-distribution' ),
					$name
				);
			} elseif ( Status::LATE === $state['status'] ) {
				$lines[] = sprintf(
					/* translators: 1: a language name such as Arabic; 2: how long ago, such as "26 h ago". */
					__( 'Google last fetched the %1$s sitemap %2$s.', 'estasoft-news-distribution' ),
					$name,
					FetchSummary::ago( (int) $state['last_ts'] )
				);
			}
		}

		if ( array() === $lines ) {
			return '';
		}

		$lead = sprintf(
			/* translators: 1: number of sitemaps that need attention; 2: number of sitemaps. */
			_n( '%1$s of %2$s sitemaps needs attention.', '%1$s of %2$s sitemaps need attention.', count( $lines ), 'estasoft-news-distribution' ),
			number_format_i18n( count( $lines ) ),
			number_format_i18n( count( $fetch ) )
		);

		$text = Html::tag( 'strong', array(), esc_html( $lead ) )
			. ' ' . esc_html( implode( ' ', $lines ) )
			. ' ' . esc_html__( 'Open Search Console and check that each one is submitted and shows the status Success. Google does not publish which languages Google News accepts, so News Distribution cannot tell you whether a language is included. Google decides.', 'estasoft-news-distribution' );

		return Message::bar( 'warning', $text );
	}

	/**
	 * "Submit to Google": three steps and a link to Google's guide.
	 *
	 * @return string
	 */
	private function submit_card(): string {
		$steps = array(
			__( 'Open Google Search Console and choose your site.', 'estasoft-news-distribution' ),
			__( 'Under Sitemaps, add each URL above.', 'estasoft-news-distribution' ),
			__( 'Check that each sitemap shows the status Success.', 'estasoft-news-distribution' ),
		);
		$items = '';

		foreach ( $steps as $step ) {
			$items .= Html::tag( 'li', array(), esc_html( $step ) );
		}

		return Card::note(
			'submit',
			__( 'Submit to Google', 'estasoft-news-distribution' ),
			Html::tag( 'ol', array( 'class' => 'estasoft-nd-numbered estasoft-nd-stack estasoft-nd-gap-8' ), $items )
			. $this->external_link( __( 'Google’s news sitemap guide', 'estasoft-news-distribution' ), 'https://developers.google.com/search/docs/crawling-indexing/sitemaps/news-sitemap' )
		);
	}

	/**
	 * "Publication name": the name each language's sitemap carries, and where to change it.
	 *
	 * @param GoogleNewsChannel $channel The channel.
	 * @return string
	 */
	private function name_card( GoogleNewsChannel $channel ): string {
		$list = '';

		foreach ( $this->plugin->languages()->languages() as $language ) {
			$language = (string) $language;

			if ( ! $channel->accepts_language( $language ) ) {
				continue;
			}

			$name  = $this->plugin->settings()->publication_name( $language );
			$list .= Html::tag(
				'li',
				array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
				Html::tag(
					'span',
					array(
						'lang' => Catalog::primary( $language ),
						'dir'  => Catalog::direction( $language ),
					),
					'' !== $name ? esc_html( $name ) : esc_html__( 'Not set', 'estasoft-news-distribution' )
				)
				. Tag::lang( $language )
			);
		}

		$body = Html::tag( 'ul', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ), $list )
			. Html::tag(
				'p',
				array(),
				Html::tag(
					'a',
					array(
						'href' => add_query_arg(
							array(
								'page'    => 'estasoft-nd-settings',
								'section' => 'general',
							),
							admin_url( 'admin.php' )
						),
					),
					esc_html__( 'Change in Settings', 'estasoft-news-distribution' )
				)
			);

		return Card::note( 'name', __( 'Publication name', 'estasoft-news-distribution' ), $body );
	}
}
