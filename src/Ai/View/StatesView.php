<?php
/**
 * Settings, AI assistant, states: everything a person can see when the assistant cannot run or needs something.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Ai\View;

use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\PageHeader;
use Estasoft\NewsDistribution\Ai\Availability;
use Estasoft\NewsDistribution\Ai\Config;
use Estasoft\NewsDistribution\Ai\Features\Fix;
use Estasoft\NewsDistribution\Ai\Policy;
use Estasoft\NewsDistribution\Ai\Usage;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Operations\Links;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * `&section=ai&view=states`. A reference page: one card for each state, written as the screens write it, plus one
 * line saying which state this site is in now. Every button on it is a link to the place that fixes the state.
 */
final class StatesView {

	private Plugin $plugin;

	/**
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * The page.
	 *
	 * @return string Escaped HTML, a whole page with its own heading.
	 */
	public function page(): string {
		$availability = $this->plugin->make( Availability::class );
		$config       = $this->plugin->make( Config::class );
		$usage        = $this->plugin->make( Usage::class );
		$policy       = $this->plugin->make( Policy::class );
		$settings_url = Links::settings( 'ai' );
		$connectors   = $availability->connectors_url();

		$bar = static function ( string $tone, string $bold, string $rest, string $actions = '' ): string {
			return Message::bar( $tone, Html::tag( 'strong', array(), esc_html( $bold ) ) . ' ' . esc_html( $rest ), $actions );
		};

		$open_connectors = Button::link( __( 'Open Connectors', 'estasoft-news-distribution' ), $connectors, array( 'size' => 'sm' ) );
		$send_test       = Button::link( __( 'Send a test request', 'estasoft-news-distribution' ), $settings_url . '#estasoft-nd-card-ai-connection', array( 'size' => 'sm' ) );

		$month_limit = $config->monthly_limit();
		$resets      = wp_date( 'j M', $usage->resets_at( 'month' ), Clock::zone() );
		$network_set = $policy->token_cap() > 0 && $policy->token_cap() <= $month_limit;

		$limit_rest = sprintf(
			/* translators: %s: the date suggestions start again, such as "1 Nov". */
			__( 'Suggestions stop until %s.', 'estasoft-news-distribution' ),
			$resets
		) . ' ' . ( $network_set
			? __( 'Your network sets this limit, so ask your network administrator to raise it. Nothing else is affected.', 'estasoft-news-distribution' )
			: __( 'You can raise it in Settings, AI assistant. Nothing else is affected.', 'estasoft-news-distribution' ) );

		$locked = Html::tag(
			'div',
			array(
				'class' => 'estasoft-nd-message estasoft-nd-message--info',
				'role'  => 'status',
			),
			Icon::svg( 'lock' ) . Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-grow' ),
				Html::tag( 'strong', array(), esc_html__( 'Your network administrator has turned the AI assistant off for this site.', 'estasoft-news-distribution' ) )
				. ' ' . esc_html__( 'No AI buttons or panels appear. Ask your network administrator if you need it.', 'estasoft-news-distribution' )
			)
		);

		$checking = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-ai-wait' ),
			Html::tag( 'span', array( 'class' => 'estasoft-nd-skeleton estasoft-nd-ai-wait__a' ), '' )
			. Html::tag( 'span', array( 'class' => 'estasoft-nd-skeleton estasoft-nd-ai-wait__b' ), '' )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Asking WordPress whether it can generate text. This takes a moment, and nothing is sent to a provider.', 'estasoft-news-distribution' ) )
		);

		$cards = array(
			array( 'checking', __( 'Checking WordPress', 'estasoft-news-distribution' ), $checking ),
			array(
				'older',
				__( 'WordPress is older than 7.0', 'estasoft-news-distribution' ),
				$bar(
					'info',
					__( 'AI features need WordPress 7.0 or newer.', 'estasoft-news-distribution' ),
					__( 'Everything else in News Distribution works. The AI features become available after you update WordPress, and each one is still off until you turn it on.', 'estasoft-news-distribution' ),
					Button::link( __( 'Check for updates', 'estasoft-news-distribution' ), admin_url( 'update-core.php' ), array( 'size' => 'sm' ) )
				),
			),
			array(
				'no-provider',
				__( 'No provider set up', 'estasoft-news-distribution' ),
				$bar(
					'info',
					__( 'Connect an AI provider to use the assistant.', 'estasoft-news-distribution' ),
					__( 'Add one under Settings, Connectors. News Distribution never asks for your key.', 'estasoft-news-distribution' ),
					$open_connectors
				),
			),
			array(
				'consent',
				__( 'Needs your confirmation', 'estasoft-news-distribution' ),
				$bar(
					'info',
					__( 'The assistant needs your confirmation before it starts.', 'estasoft-news-distribution' ),
					__( 'Read what is sent and to whom, then confirm. Until you do, the assistant stays off and nothing is sent.', 'estasoft-news-distribution' ),
					Button::link( __( 'Read and confirm', 'estasoft-news-distribution' ), ConsentView::url(), array( 'size' => 'sm' ) )
				),
			),
			array(
				'test-failed',
				__( 'The test request failed', 'estasoft-news-distribution' ),
				Message::bar(
					'danger',
					Html::tag( 'strong', array(), esc_html__( 'Your provider did not accept the test request.', 'estasoft-news-distribution' ) )
					. ' ' . esc_html__( 'It said the key is not valid. Nothing from your site was sent. Check the key under Settings, Connectors, then test again.', 'estasoft-news-distribution' )
				) . Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8 estasoft-nd-ai-after' ), $open_connectors . $send_test ),
			),
			array(
				'limit',
				__( 'A limit was reached', 'estasoft-news-distribution' ),
				$bar(
					'warning',
					sprintf(
						/* translators: %s: the monthly token limit, such as 2,000,000. */
						__( 'The monthly limit of %s tokens is reached.', 'estasoft-news-distribution' ),
						number_format_i18n( $month_limit )
					),
					$limit_rest,
					Button::link( __( 'See usage', 'estasoft-news-distribution' ), $settings_url . '#estasoft-nd-card-ai-limits', array( 'size' => 'sm' ) )
				),
			),
			array(
				'limit-network',
				__( 'The network’s budget is used', 'estasoft-news-distribution' ),
				$bar(
					'warning',
					__( 'The network’s monthly AI budget is used.', 'estasoft-news-distribution' ),
					sprintf(
						/* translators: %s: the date the count starts again, such as "1 Nov 2026". */
						__( 'The assistant is paused on every site until %s. Your network administrator sets this budget in Network Admin, AI policy. Nothing else is affected.', 'estasoft-news-distribution' ),
						wp_date( 'j M Y', $usage->resets_at( 'network' ), new \DateTimeZone( 'UTC' ) )
					)
				),
			),
			array(
				'provider-error',
				__( 'The provider answered with an error', 'estasoft-news-distribution' ),
				$bar(
					'danger',
					__( 'Your provider said its quota is used up.', 'estasoft-news-distribution' ),
					__( 'Nothing was changed. Add credit or raise the quota in your provider’s account, then send a test request.', 'estasoft-news-distribution' ),
					$send_test
				),
			),
			array(
				'rule-check',
				__( 'No suggestion passed the rule check', 'estasoft-news-distribution' ),
				$bar(
					'warning',
					sprintf(
						/* translators: %d: how many suggestions were asked for. */
						__( 'None of the %d suggestions followed the platform rule, so none is shown.', 'estasoft-news-distribution' ),
						Fix::ASKED
					),
					__( 'Nothing was changed. Write one yourself, or ask again for new suggestions.', 'estasoft-news-distribution' )
				),
			),
			array( 'locked', __( 'Locked by your network', 'estasoft-news-distribution' ), $locked ),
			array(
				'off',
				__( 'The assistant is off', 'estasoft-news-distribution' ),
				Message::text( 'info', __( 'No AI buttons or panels appear anywhere. Turn it on in Settings, AI assistant, when you are ready.', 'estasoft-news-distribution' ) ),
			),
		);

		$html = '';

		foreach ( $cards as $card ) {
			$html .= Card::note( 'ai-state-' . $card[0], $card[1], $card[2] );
		}

		return $this->crumbs()
			. PageHeader::render(
				__( 'AI assistant, states', 'estasoft-news-distribution' ),
				__( 'What a person sees when the assistant cannot run or needs something. It never shows a disabled button.', 'estasoft-news-distribution' )
			)
			. $this->now( $availability->state() )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-ai-states' ), $html );
	}

	/**
	 * One line saying which state this site is in now.
	 *
	 * @param string $state One of Availability::STATES.
	 * @return string Escaped HTML.
	 */
	private function now( string $state ): string {
		$words = array(
			'unsupported'   => __( 'WordPress has AI switched off, so the assistant cannot run.', 'estasoft-news-distribution' ),
			'locked'        => __( 'Your network has turned the assistant off for this site.', 'estasoft-news-distribution' ),
			'no_provider'   => __( 'No provider is set up.', 'estasoft-news-distribution' ),
			'off'           => __( 'The assistant is off.', 'estasoft-news-distribution' ),
			'needs_consent' => __( 'The assistant needs your confirmation.', 'estasoft-news-distribution' ),
			'limit'         => __( 'A limit is reached, so suggestions are paused.', 'estasoft-news-distribution' ),
			'ready'         => __( 'The assistant is on and ready.', 'estasoft-news-distribution' ),
		);

		return Message::text(
			'ready' === $state ? 'success' : 'info',
			sprintf(
				/* translators: %s: a sentence saying what state the assistant is in. */
				__( 'This site now: %s', 'estasoft-news-distribution' ),
				$words[ $state ] ?? $words['off']
			)
		);
	}

	/**
	 * "Settings / AI assistant / States".
	 *
	 * @return string Escaped HTML.
	 */
	private function crumbs(): string {
		return Html::tag(
			'nav',
			array(
				'class'      => 'estasoft-nd-small estasoft-nd-ops-crumbs',
				'aria-label' => __( 'Breadcrumb', 'estasoft-news-distribution' ),
			),
			Html::tag( 'a', array( 'href' => esc_url( Links::settings() ) ), esc_html__( 'Settings', 'estasoft-news-distribution' ) )
			. ' / '
			. Html::tag( 'a', array( 'href' => esc_url( Links::settings( 'ai' ) ) ), esc_html__( 'AI assistant', 'estasoft-news-distribution' ) )
			. ' / '
			. Html::tag( 'span', array( 'aria-current' => 'page' ), esc_html__( 'States', 'estasoft-news-distribution' ) )
		);
	}
}
