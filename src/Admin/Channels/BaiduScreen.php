<?php
/**
 * The Baidu Webmaster Tools channel page.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Channels;

use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Channels\Verification\VerificationChannel;
use Estasoft\NewsDistribution\Core\Operations\Links;

defined( 'ABSPATH' ) || exit;

/**
 * `admin.php?page=estasoft-nd-channels&channel=baidu`. The same search sitemap as
 * Bing and Yandex, then four steps. News Distribution does not connect to Baidu and
 * cannot see inside Baidu Webmaster Tools, so the account, the verification and the
 * submission are ticked by hand.
 *
 * Baidu's rule pack comes from third-party guides, so the words on this page say
 * "may" wherever the guides could not be confirmed, and the page says which pack
 * is the least certain.
 */
final class BaiduScreen extends AbstractSearchScreen {

	public static function channel_id(): string {
		return 'baidu';
	}

	public function description(): string {
		return __( 'Pulled channel: News Distribution builds the sitemap and Baidu reads it on its own schedule. Baidu decides what it indexes.', 'estasoft-news-distribution' );
	}

	protected function platform(): string {
		return __( 'Baidu', 'estasoft-news-distribution' );
	}

	protected function crawler_name(): string {
		return 'Baiduspider';
	}

	/**
	 * The warning above the cards: the console's language and the sign-up.
	 *
	 * @return string
	 */
	protected function notice(): string {
		return Message::bar(
			'warning',
			Html::tag( 'strong', array(), esc_html__( 'Baidu’s console is in Chinese.', 'estasoft-news-distribution' ) )
			. ' ' . esc_html__( 'Registration may ask for a phone number, and Baidu decides who can register. Work through the steps in order and tick each one when it is done.', 'estasoft-news-distribution' )
		);
	}

	protected function rule_sentences(): array {
		return array(
			'sitemap.format'   => __( 'XML or text sitemap in UTF-8', 'estasoft-news-distribution' ),
			'sitemap.max_urls' => sprintf(
				/* translators: %s: the most URLs News Distribution puts in one sitemap file, such as 1,000. */
				__( 'One file per language, %s URLs at most', 'estasoft-news-distribution' ),
				number_format_i18n( (int) $this->plugin->rules()->limit( 'baidu', 'max_urls', 1000 ) )
			),
			'site.address'     => __( 'Your main address only, because Baidu may treat http and https as separate sites', 'estasoft-news-distribution' ),
			'sitemap.shared'   => __( 'Same plain sitemap as Bing and Yandex', 'estasoft-news-distribution' ),
		);
	}

	protected function cannot_card(): string {
		return $this->cannot_paragraph(
			__( 'It cannot register a Baidu account, verify your site or submit the sitemap for you, and it cannot see inside Baidu Webmaster Tools. Baidu decides who can register and what it indexes, so News Distribution cannot make Baidu list an article.', 'estasoft-news-distribution' )
		);
	}

	/**
	 * The words of the four steps. This page draws its own steps, so the keys are its
	 * own: `ready`, `account_title`, `account_text`, `account_label`, `account_link`,
	 * `account_url`, `verify_title`, `verify_text`, `verify_label`, `verify_tool`,
	 * `submit_title`, `submit_text` and `submit_label`.
	 *
	 * @return array<string, string>
	 */
	protected function steps_copy(): array {
		$engines = VerificationChannel::engines();

		return array(
			'ready'         => __( 'News Distribution builds the same plain search sitemap as for Bing and Yandex.', 'estasoft-news-distribution' ),
			'account_title' => __( 'Create a Baidu account', 'estasoft-news-distribution' ),
			'account_text'  => __( 'You need an account to use Baidu Webmaster Tools. Registration may ask for a phone number.', 'estasoft-news-distribution' ),
			'account_label' => __( 'I have a Baidu account', 'estasoft-news-distribution' ),
			'account_link'  => __( 'Baidu Webmaster Tools', 'estasoft-news-distribution' ),
			'account_url'   => (string) $engines['baidu']['open_url'],
			'verify_title'  => __( 'Verify your site', 'estasoft-news-distribution' ),
			'verify_text'   => __( 'Add your site with the exact address you publish under, because Baidu may treat http and https as different sites. Baidu may offer an HTML tag, a file upload or a CNAME record.', 'estasoft-news-distribution' ),
			'verify_label'  => __( 'My site is verified', 'estasoft-news-distribution' ),
			'verify_tool'   => __( 'News Distribution can print the tag or serve the file for you on Site verification.', 'estasoft-news-distribution' ),
			'submit_title'  => __( 'Submit the sitemaps', 'estasoft-news-distribution' ),
			'submit_text'   => __( 'In the console open Resource submission (资源提交), then Normal submission (普通收录), choose the Sitemap tab and paste each URL from the card above. Baidu can rename these menus.', 'estasoft-news-distribution' ),
			'submit_label'  => __( 'I submitted the sitemaps', 'estasoft-news-distribution' ),
		);
	}

	/**
	 * Says the Baidu pack is the least certain one, and why.
	 *
	 * @param string $platform_id The rule pack's platform id.
	 * @return string Plain text.
	 */
	protected function rules_foot( string $platform_id ): string {
		return sprintf(
			/* translators: %s: the version of the rule pack, such as 2026.07.1. */
			__( 'Rule pack %s, from third-party guides rather than Baidu’s own help, so the least certain', 'estasoft-news-distribution' ),
			$this->plugin->rules()->version( $platform_id )
		);
	}

	/**
	 * The four steps. The first is done when every sitemap is valid; the other three
	 * are the person's own word. The first step still to do is tinted.
	 *
	 * @param array<string, array<string, mixed>> $reports Language => report.
	 * @return string
	 */
	protected function steps_card( array $reports ): string {
		$copy     = $this->steps_copy();
		$values   = $this->values();
		$all_good = array() !== $reports;

		foreach ( $reports as $report ) {
			$all_good = $all_good && $report['valid'];
		}

		$account   = ! empty( $values['account'] );
		$verified  = ! empty( $values['verified'] );
		$submitted = ! empty( $values['submitted'] );
		$next      = 0;

		foreach ( array( $all_good, $account, $verified, $submitted ) as $index => $done ) {
			if ( ! $done ) {
				$next = $index + 1;
				break;
			}
		}

		$muted = array( 'class' => 'estasoft-nd-muted' );

		$step1 = $this->step(
			1,
			$all_good,
			__( 'Your sitemap is ready', 'estasoft-news-distribution' ),
			Html::tag( 'p', $muted, esc_html( $copy['ready'] ) ),
			1 === $next
		);

		$step2 = $this->step(
			2,
			$account,
			$copy['account_title'],
			Html::tag( 'p', $muted, esc_html( $copy['account_text'] ) )
			. $this->tick( 'account', $copy['account_label'], $account )
			. $this->external_link( $copy['account_link'], $copy['account_url'] ),
			2 === $next
		);

		$step3 = $this->step(
			3,
			$verified,
			$copy['verify_title'],
			Html::tag( 'p', $muted, esc_html( $copy['verify_text'] ) )
			. $this->tick( 'verified', $copy['verify_label'], $verified )
			. $this->date_field( 'verified_on', $verified, (string) ( $values['verified_on'] ?? '' ) )
			. Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
				esc_html( $copy['verify_tool'] ) . ' ' . Html::tag(
					'a',
					array(
						'href' => add_query_arg( 'engine', 'baidu', Links::channel( 'verification' ) ),
					),
					esc_html__( 'Open Site verification', 'estasoft-news-distribution' )
				)
			),
			3 === $next
		);

		$step4 = $this->step(
			4,
			$submitted,
			$copy['submit_title'],
			Html::tag( 'p', $muted, esc_html( $copy['submit_text'] ) )
			. $this->tick( 'submitted', $copy['submit_label'], $submitted )
			. $this->date_field( 'submitted_on', $submitted, (string) ( $values['submitted_on'] ?? '' ) ),
			4 === $next
		);

		return Html::tag(
			'section',
			array(
				'class'      => 'estasoft-nd-card estasoft-nd-card--flush',
				'aria-label' => __( 'Steps to set up the sitemap', 'estasoft-news-distribution' ),
			),
			$step1 . $step2 . $step3 . $step4
		);
	}

	/**
	 * Wraps the cards in the form, with the line the board puts in the save bar: the
	 * ticks are saved together, so a tick alone saves nothing until Save changes.
	 *
	 * @param string $inner Escaped HTML of the cards.
	 * @param bool   $bar   Whether to print the save bar.
	 * @param string $tab   The open tab.
	 * @return string
	 */
	protected function form( string $inner, bool $bar = true, string $tab = '' ): string {
		return parent::form(
			$inner . Message::bar( 'info', Html::tag( 'strong', array(), esc_html__( 'The ticks on this page are saved together', 'estasoft-news-distribution' ) ) ),
			$bar,
			$tab
		);
	}
}
