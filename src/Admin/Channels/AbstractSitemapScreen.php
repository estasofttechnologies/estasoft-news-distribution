<?php
/**
 * What the Google News, Bing and Yandex pages share.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Channels;

use Estasoft\NewsDistribution\Admin\Admin;
use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Ui\AddressRow;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Admin\Ui\Tag;
use Estasoft\NewsDistribution\Channels\SearchSitemap\FetchSummary;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * A page for a channel that gives the platform a sitemap: the addresses with
 * Copy, whether each is valid, when the platform last fetched it, the rules
 * News Distribution applies and what it cannot do. A "Test sitemap" button
 * builds and checks every language (`POST /channels/{id}/validate`).
 */
abstract class AbstractSitemapScreen extends AbstractChannelScreen {

	/**
	 * What the address is, for button names: "news sitemap" or "search sitemap".
	 *
	 * @return string
	 */
	abstract protected function what(): string;

	/**
	 * The text for a valid sitemap with this many entries, such as "Valid, 9 articles".
	 *
	 * @param int $entries Entries in the sitemap.
	 * @return string
	 */
	abstract protected function valid_text( int $entries ): string;

	/**
	 * The crawler's name when it has not fetched yet, such as "Google".
	 *
	 * @return string
	 */
	abstract protected function crawler_name(): string;

	/**
	 * The "Test sitemap" button.
	 *
	 * @param string $tab The tab slug.
	 * @return string
	 */
	public function actions( string $tab ): string {
		return Button::link(
			__( 'Test sitemap', 'estasoft-news-distribution' ),
			wp_nonce_url( $this->url( '', array( 'test' => 1 ) ), 'estasoft_nd_test_' . $this->channel->id() ),
			array(
				'attrs' => array(
					'data-test-sitemap' => true,
					'data-endpoint'     => 'channels/' . rawurlencode( $this->channel->id() ) . '/validate',
				),
			)
		);
	}

	/**
	 * Loads the shared styles and scripts.
	 *
	 * @param string $tab The tab slug.
	 * @return void
	 */
	public function enqueue( string $tab ): void {
		Assets::screen_style( 'address-row' );
		Assets::screen_script( 'address-row' );
		Assets::screen_script(
			'sitemap-test',
			array(
				'text' => array(
					'running'  => __( 'Testing the sitemaps…', 'estasoft-news-distribution' ),
					'passed'   => __( 'Test passed: every sitemap is valid.', 'estasoft-news-distribution' ),
					'problems' => __( 'The test found problems:', 'estasoft-news-distribution' ),
					'failed'   => __( 'The test could not run.', 'estasoft-news-distribution' ),
					'offline'  => __( 'Could not reach your site.', 'estasoft-news-distribution' ),
				),
			)
		);
	}

	/**
	 * The area the test result prints in, and the notice shown while the channel is off.
	 *
	 * @return string
	 */
	protected function head(): string {
		return $this->test_region() . $this->off_notice();
	}

	/**
	 * The test result region. The script fills it; without a script the button
	 * reloads the page with `test=1` and the server fills it.
	 *
	 * @return string
	 */
	private function test_region(): string {
		$attrs = array(
			'id'        => 'estasoft-nd-test-result',
			'role'      => 'status',
			'aria-live' => 'polite',
			'hidden'    => true,
		);
		$inner = '';

		$asked = isset( $_GET['test'], $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'estasoft_nd_test_' . $this->channel->id() ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The nonce is checked in this statement.

		if ( $asked ) {
			$lines = array();

			foreach ( $this->test_problems() as $line ) {
				$lines[] = Html::tag( 'li', array(), esc_html( $line ) );
			}

			$kind  = array() === $lines ? 'success' : 'warning';
			$lead  = array() === $lines ? __( 'Test passed: every sitemap is valid.', 'estasoft-news-distribution' ) : __( 'The test found problems:', 'estasoft-news-distribution' );
			$inner = Html::tag( 'strong', array(), esc_html( $lead ) ) . ( array() === $lines ? '' : Html::tag( 'ul', array(), implode( '', $lines ) ) );
			$attrs = array(
				'id'        => 'estasoft-nd-test-result',
				'class'     => 'estasoft-nd-message estasoft-nd-message--' . $kind . ' estasoft-nd-test-result',
				'role'      => 'status',
				'aria-live' => 'polite',
			);
		}

		return Html::tag( 'div', $attrs, $inner );
	}

	/**
	 * Builds every sitemap now, checks it, and lists the problems found.
	 *
	 * @return string[] One line per problem, with the language in front.
	 */
	private function test_problems(): array {
		$feeds = new \Estasoft\NewsDistribution\Frontend\FeedController( $this->plugin );
		$lines = array();

		foreach ( $this->plugin->languages()->languages() as $language ) {
			$language = (string) $language;

			if ( ! $this->channel->accepts_language( $language ) ) {
				continue;
			}

			$document = $feeds->build_document( $this->channel, $language );
			$checked  = $this->channel->check_output( $document['body'], $language );

			foreach ( (array) $checked['problems'] as $problem ) {
				$lines[] = strtoupper( $language ) . ': ' . $problem;
			}
		}

		return $lines;
	}

	/**
	 * A bar with a "Turn on" button, shown while the channel is off. It posts to
	 * the channel form handler, so it works without a script.
	 *
	 * @return string
	 */
	private function off_notice(): string {
		if ( $this->channel->is_enabled() ) {
			return '';
		}

		$id   = $this->channel->id();
		$form = Html::tag(
			'form',
			array(
				'method' => 'post',
				'action' => admin_url( 'admin-post.php' ),
			),
			Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'action',
					'value' => 'estasoft_nd_save_channel',
				)
			)
			. Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'channel',
					'value' => $id,
				)
			)
			. Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'estasoft_nd[enabled]',
					'value' => '1',
				)
			)
			. wp_nonce_field( Admin::NONCE_CHANNEL, '_wpnonce', true, false )
			. Button::submit( __( 'Turn on', 'estasoft-news-distribution' ), array( 'variant' => 'primary' ) )
		);

		return Message::bar(
			'warning',
			Html::tag( 'strong', array(), esc_html__( 'This channel is off.', 'estasoft-news-distribution' ) )
			. ' ' . esc_html__( 'Its sitemap addresses answer 404 until you turn it on.', 'estasoft-news-distribution' ),
			$form
		);
	}

	/**
	 * One row per language: the address with Copy, whether the sitemap is valid
	 * and, when asked, how the platform's fetches look.
	 *
	 * @param array<string, array<string, mixed>> $reports   Language => report (see SitemapReport).
	 * @param array<string, array<string, mixed>> $fetch     Language => fetch state, or an empty array to leave fetches out.
	 * @return string
	 */
	protected function language_rows( array $reports, array $fetch ): string {
		$html = '';

		foreach ( $this->plugin->languages()->languages() as $language ) {
			$language = (string) $language;

			if ( ! isset( $reports[ $language ] ) ) {
				$html .= Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-address-row' ),
					Html::tag(
						'div',
						array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-12' ),
						Tag::lang( $language ) . Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Turned off for this language in the Languages settings.', 'estasoft-news-distribution' ) )
					)
				);
				continue;
			}

			$report = $reports[ $language ];
			$facts  = $this->validity( $report );

			if ( isset( $fetch[ $language ] ) ) {
				$facts .= $this->fetch_facts( $fetch[ $language ] );
			}

			$problems = '';

			if ( ! $report['valid'] ) {
				$items = '';

				foreach ( $report['problems'] as $problem ) {
					$items .= Html::tag( 'li', array(), esc_html( (string) $problem ) );
				}

				$problems = Html::tag( 'ul', array( 'class' => 'estasoft-nd-small estasoft-nd-muted' ), $items );
			}

			$html .= Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-address-row' ),
				AddressRow::language( $language, (string) $report['url'], $this->what() )
				. Html::tag( 'div', array( 'class' => 'estasoft-nd-address-facts' ), $facts )
				. $problems
			);
		}

		return $html;
	}

	/**
	 * "Valid, 9 articles", or "Not valid" with the problems listed under it.
	 *
	 * @param array<string, mixed> $report The language's report.
	 * @return string
	 */
	private function validity( array $report ): string {
		return $report['valid']
			? StatusView::inline( Status::CLEAN, $this->valid_text( (int) $report['entries'] ) )
			: StatusView::inline( Status::FAILED, __( 'Not valid', 'estasoft-news-distribution' ) );
	}

	/**
	 * The fetch status and "Last fetched by Google 6 min ago" for one language.
	 *
	 * @param array<string, mixed> $state The language's fetch state.
	 * @return string
	 */
	private function fetch_facts( array $state ): string {
		return StatusView::inline( FetchSummary::display( (string) $state['status'] ) ) . Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html( $this->last_fetched( $state ) ) );
	}

	/**
	 * "Last fetched by Google 6 min ago", or "Last fetched by Google: never".
	 *
	 * @param array<string, mixed> $state A fetch state with `last_ts` and `agent`.
	 * @return string
	 */
	protected function last_fetched( array $state ): string {
		if ( (int) $state['last_ts'] <= 0 ) {
			return sprintf(
				/* translators: %s: the name of a crawler, such as Google or Bingbot. */
				__( 'Last fetched by %s: never', 'estasoft-news-distribution' ),
				$this->crawler_name()
			);
		}

		return sprintf(
			/* translators: 1: the name of a crawler, such as Google or Bingbot. 2: how long ago, such as "6 min ago". */
			__( 'Last fetched by %1$s %2$s', 'estasoft-news-distribution' ),
			'' !== (string) $state['agent'] ? FetchSummary::agent_name( (string) $state['agent'] ) : $this->crawler_name(),
			FetchSummary::ago( (int) $state['last_ts'] )
		);
	}

	/**
	 * The "Rules News Distribution applies" card.
	 *
	 * @param string                $platform_id The rule pack's platform id.
	 * @param string                $platform    The platform's name, for the careful note.
	 * @param array<string, string> $rules  Rule code (or `a|b` for either) => the sentence to print, in order.
	 * @return string
	 */
	protected function rules_card( string $platform_id, string $platform, array $rules ): string {
		$list    = '';
		$careful = false;
		$packs   = $this->plugin->rules();

		foreach ( $rules as $codes => $sentence ) {
			$found = false;

			foreach ( explode( '|', (string) $codes ) as $code ) {
				$rule = $packs->rule( $platform_id, $code );

				if ( array() !== $rule ) {
					$found   = true;
					$careful = $careful || 'confirmed' !== (string) ( $rule['confidence'] ?? '' );
				}
			}

			if ( $found ) {
				$list .= Html::tag( 'li', array( 'class' => 'estasoft-nd-rule estasoft-nd-rule--ok' ), Icon::svg( 'check' ) . Html::tag( 'span', array(), esc_html( (string) $sentence ) ) );
			}
		}

		$foot = $this->rules_foot( $platform_id );

		$body = Html::tag( 'ul', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $list )
			. Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
				esc_html( $foot ) . ' · ' . Html::tag(
					'a',
					array(
						'href' => add_query_arg(
							array(
								'page'    => 'estasoft-nd-settings',
								'section' => 'rules',
							),
							admin_url( 'admin.php' )
						),
					),
					esc_html__( 'Platform rules', 'estasoft-news-distribution' )
				)
			);

		if ( $careful ) {
			$body .= Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
				esc_html(
					sprintf(
						/* translators: %s: the name of a platform, such as Bing. */
						__( 'Some of these come from the sitemap standard or from News Distribution, not from a page %1$s publishes. Check with %2$s if a sitemap is rejected.', 'estasoft-news-distribution' ),
						$platform,
						$platform
					)
				)
			);
		}

		return Card::note( 'rules', __( 'Rules News Distribution applies', 'estasoft-news-distribution' ), $body );
	}

	/**
	 * The line that names the rule pack under the rules. A page whose pack comes from
	 * weaker sources than the others says so here.
	 *
	 * @param string $platform_id The rule pack's platform id.
	 * @return string Plain text.
	 */
	protected function rules_foot( string $platform_id ): string {
		return sprintf(
			/* translators: %s: the version of the rule pack, such as 2026.09.2. */
			__( 'Rule pack %s', 'estasoft-news-distribution' ),
			$this->plugin->rules()->version( $platform_id )
		);
	}

	/**
	 * A "What it cannot do" card with one paragraph.
	 *
	 * @param string $text The paragraph.
	 * @return string
	 */
	protected function cannot_paragraph( string $text ): string {
		return Card::note( 'cannot', __( 'What it cannot do', 'estasoft-news-distribution' ), Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( $text ) ) );
	}

	/**
	 * The two columns of the page: the main cards and the side cards.
	 *
	 * @param string $main Trusted HTML.
	 * @param string $side Trusted HTML.
	 * @return string
	 */
	protected function split( string $main, string $side ): string {
		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-split' ),
			Html::tag( 'div', array( 'class' => 'estasoft-nd-split__main' ), $main )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-split__side' ), $side )
		);
	}

	/**
	 * A language's name as a sentence reads it. Kurmanji is shown as Kurdish (Kurmanji).
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	public static function language_label( string $code ): string {
		if ( 'ku' === \Estasoft\NewsDistribution\Core\Language\Catalog::primary( $code ) ) {
			return __( 'Kurdish (Kurmanji)', 'estasoft-news-distribution' );
		}

		return \Estasoft\NewsDistribution\Core\Language\Catalog::name( $code );
	}

	/**
	 * A link that opens another site, with the external-link icon.
	 *
	 * @param string $label Link text.
	 * @param string $url   Address.
	 * @return string
	 */
	protected function external_link( string $label, string $url ): string {
		return Html::tag(
			'a',
			array(
				'href'   => $url,
				'target' => '_blank',
				'rel'    => 'noopener noreferrer',
				'class'  => 'estasoft-nd-cluster estasoft-nd-gap-4 estasoft-nd-strong',
			),
			esc_html( $label ) . Icon::svg( 'external-link' )
		);
	}
}
