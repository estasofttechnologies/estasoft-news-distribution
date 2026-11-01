<?php
/**
 * The confirmation that has to be given before the AI assistant sends anything.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Ai\View;

use Estasoft\NewsDistribution\Admin\Operations\Forms;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Ai\Availability;
use Estasoft\NewsDistribution\Core\Operations\Links;
use Estasoft\NewsDistribution\Core\Operations\PrivacyInventory;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * `admin.php?page=estasoft-nd-settings&section=ai&dialog=consent` opens Settings, AI assistant with the
 * "Turn on the AI assistant?" dialog on top. It is a plain form that posts to `admin-post.php`
 * (`estasoft_nd_ai_consent`), so it works without a script. Only an administrator sees it.
 *
 * The record is made by `Ai\Consent::grant()`, which the post handler calls. Nothing here records anything.
 */
final class ConsentView {

	public const ACTION = 'estasoft_nd_ai_consent';

	private Plugin $plugin;

	/**
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Whether the address asks for the dialog and this person may see it.
	 *
	 * @return bool
	 */
	public static function wanted(): bool {
		if ( ! Query::is( 'dialog', 'consent' ) ) {
			return false;
		}

		return current_user_can( 'manage_options' );
	}

	/**
	 * The address that opens the dialog.
	 *
	 * @return string
	 */
	public static function url(): string {
		return Links::settings( 'ai', array( 'dialog' => 'consent' ) );
	}

	/**
	 * The dialog.
	 *
	 * @return string Escaped HTML.
	 */
	public function dialog(): string {
		$availability = $this->plugin->make( Availability::class );
		$providers    = array_column( $availability->usable_providers(), 'name' );
		$months       = $this->plugin->make( PrivacyInventory::class )->ai_months();
		$ready        = 'ok' === $availability->connection();

		if ( ! $ready ) {
			$where = '';
		} elseif ( 1 === count( $providers ) ) {
			$where = sprintf(
				/* translators: %s: the name of the AI provider, such as "Anthropic". */
				__( ' (today: %s)', 'estasoft-news-distribution' ),
				$providers[0]
			);
		} else {
			$where = sprintf(
				/* translators: %s: the names of the AI providers you can use, such as "Anthropic and Google". */
				__( ' (providers you can use now: %s)', 'estasoft-news-distribution' ),
				wp_sprintf( '%l', $providers )
			);
		}

		$parts = array(
			array(
				__( 'What happens', 'estasoft-news-distribution' ),
				sprintf(
					/* translators: %s: an empty string, or a phrase naming the AI provider in brackets. */
					__( 'When you ask for a suggestion, News Distribution sends text to the AI provider that WordPress picks from your preferred models%s. The provider answers with a suggestion. News Distribution checks it against the platform rules before you see it.', 'estasoft-news-distribution' ),
					$where
				),
			),
			array(
				__( 'What is sent', 'estasoft-news-distribution' ),
				__( 'Published articles, and drafts only if you choose that in Settings. Only what a feature needs: for a summary, a search description or a caption, the headline and the first 600 characters of the article. A caption never sends the photo. Never keys, visitor data or email addresses. You can change this in Settings.', 'estasoft-news-distribution' ),
			),
			array(
				__( 'What it costs', 'estasoft-news-distribution' ),
				__( 'Your provider bills you. News Distribution sells no credits and counts requests and tokens, so you can set a limit.', 'estasoft-news-distribution' ),
			),
			array(
				__( 'What News Distribution keeps', 'estasoft-news-distribution' ),
				sprintf(
					/* translators: %d: number of months the AI log is kept. */
					_n(
						'A log of each suggestion and the decision, for %d month. The log holds the suggestions themselves, such as a new title or a translation draft, but not a copy of the article. You can change how long it is kept in Settings, Privacy and data.',
						'A log of each suggestion and the decision, for %d months. The log holds the suggestions themselves, such as a new title or a translation draft, but not a copy of the article. You can change how long it is kept in Settings, Privacy and data.',
						$months,
						'estasoft-news-distribution'
					),
					$months
				),
			),
			array(
				__( 'What stays with you', 'estasoft-news-distribution' ),
				__( 'AI can be wrong. Check suggestions before you use them. Articles with AI-written text are marked AI-assisted automatically, and a person can remove the mark only by editing the article’s content label. Rules on labeling vary by country, so check what applies to you.', 'estasoft-news-distribution' ),
			),
		);

		$body = '';

		foreach ( $parts as $part ) {
			$body .= Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-ai-consent__part' ),
				Html::tag( 'strong', array(), esc_html( $part[0] ) ) . Html::tag( 'p', array(), esc_html( $part[1] ) )
			);
		}

		$need = Query::has( 'need' );

		$tick = Html::tag(
			'label',
			array(
				'class' => 'estasoft-nd-check',
				'for'   => 'estasoft-nd-ai-confirm',
			),
			Html::void(
				'input',
				array(
					'type'             => 'checkbox',
					'id'               => 'estasoft-nd-ai-confirm',
					'name'             => 'confirm',
					'value'            => '1',
					'aria-describedby' => 'estasoft-nd-ai-confirm-help',
					'aria-invalid'     => $need ? 'true' : null,
				)
			) . Html::tag( 'span', array(), esc_html__( 'I understand, and I am allowed to send my site’s text to this provider', 'estasoft-news-distribution' ) )
		) . Html::tag(
			'p',
			array(
				'class' => 'estasoft-nd-small estasoft-nd-ai-consent__help' . ( $need ? ' estasoft-nd-ai-consent__help--need' : '' ),
				'id'    => 'estasoft-nd-ai-confirm-help',
				'role'  => $need ? 'alert' : null,
			),
			Icon::svg( 'warning' ) . Html::tag( 'span', array(), esc_html__( 'Tick the box to confirm. Until you do, the assistant stays off and nothing is sent.', 'estasoft-news-distribution' ) )
		);

		$actions = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-dialog__actions' ),
			Button::link( __( 'Not now', 'estasoft-news-distribution' ), Links::settings( 'ai' ) )
			. Button::submit( __( 'Turn on the assistant', 'estasoft-news-distribution' ), array( 'variant' => 'primary' ) )
		);

		$heading = Html::tag(
			'h2',
			array(
				'class' => 'estasoft-nd-title',
				'id'    => 'estasoft-nd-ai-consent-title',
			),
			esc_html__( 'Turn on the AI assistant?', 'estasoft-news-distribution' ) . ' ' . Html::tag( 'span', array( 'class' => 'estasoft-nd-ai' ), esc_html__( 'AI', 'estasoft-news-distribution' ) )
		);

		$intro = Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Read this first. You can turn it off at any time. Nothing is sent when you turn it on, and nothing is applied until a person accepts a suggestion.', 'estasoft-news-distribution' ) );

		$form = Forms::post(
			self::ACTION,
			self::ACTION,
			$body . $tick . $actions,
			array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-16' )
		);

		return Html::tag(
			'div',
			array(
				'class'       => 'estasoft-nd-scrim',
				'id'          => 'estasoft-nd-dlg-ai-consent',
				'data-dialog' => true,
			),
			Html::tag(
				'div',
				array(
					'class'           => 'estasoft-nd-dialog estasoft-nd-ai-consent',
					'role'            => 'dialog',
					'aria-modal'      => 'true',
					'aria-labelledby' => 'estasoft-nd-ai-consent-title',
				),
				$heading . $intro . $form
			)
		);
	}
}
