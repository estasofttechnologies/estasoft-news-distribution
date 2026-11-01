<?php
/**
 * Settings, AI assistant.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Settings\Sections;

use Estasoft\NewsDistribution\Ai\Config;
use Estasoft\NewsDistribution\Ai\Consent;
use Estasoft\NewsDistribution\Ai\Policy;
use Estasoft\NewsDistribution\Ai\Reviewers;
use Estasoft\NewsDistribution\Ai\View\ConsentView;
use Estasoft\NewsDistribution\Ai\View\SettingsView;
use Estasoft\NewsDistribution\Ai\View\StatesView;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Settings\Section;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * The owner's choices for the AI assistant: which features are on, what may be sent, the limits and
 * who signs off a translation. Only an administrator sees it, and only on WordPress 7.0 and newer,
 * which has the AI Client. On older WordPress the section does not exist.
 *
 * Stored as `estasoft_nd_settings.ai`. Two things are deliberately not here:
 *
 * - No key. WordPress holds the connection to the provider (Settings, Connectors); this plugin never
 *   reads, stores or returns one.
 * - No consent. `enabled` is only the owner's switch. Consent is its own record
 *   (`Ai\Consent`, option `estasoft_nd_ai_consent`), made by the confirmation dialog, so saving this
 *   form cannot create it and nothing is sent without it.
 */
final class Ai extends Section {

	public const NAME = 'ai';

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array {
		$features = array();

		foreach ( Config::TOGGLES as $toggle ) {
			$features[ $toggle ] = array(
				'type'    => 'bool',
				'label'   => $toggle,
				'default' => false,
			);
		}

		return array(
			'enabled'        => array(
				'type'    => 'bool',
				'label'   => __( 'The assistant is on', 'estasoft-news-distribution' ),
				'default' => false,
			),
			'features'       => array(
				'type'   => 'group',
				'label'  => __( 'Features', 'estasoft-news-distribution' ),
				'fields' => $features,
			),
			'drafts'         => array(
				'type'    => 'bool',
				'label'   => __( 'Also drafts and scheduled posts', 'estasoft-news-distribution' ),
				'default' => false,
			),
			'authors'        => array(
				'type'    => 'bool',
				'label'   => __( 'Author names', 'estasoft-news-distribution' ),
				'default' => false,
			),
			'models'         => array(
				'type'      => 'string',
				'label'     => __( 'Preferred models', 'estasoft-news-distribution' ),
				'row_label' => __( 'Preferred models, optional', 'estasoft-news-distribution' ),
				'format'    => 'textarea',
				'max'       => 1000,
				'default'   => '',
				'help'      => __( 'One model name per line, best first. WordPress uses the first one that one of your providers offers. When none match, or the list is empty, WordPress chooses.', 'estasoft-news-distribution' ),
				'rows'      => 3,
				'width'     => 260,
				'dir'       => 'ltr',
			),
			'daily_limit'    => array(
				'type'    => 'int',
				'label'   => __( 'Requests a day', 'estasoft-news-distribution' ),
				'min'     => 1,
				'max'     => 10000,
				'default' => 200,
				'width'   => 90,
				'help'    => __( 'Every suggestion, answer and translation draft is 1 request. Enter a whole number from 1 to 10,000.', 'estasoft-news-distribution' ),
			),
			'monthly_tokens' => array(
				'type'    => 'int',
				'label'   => __( 'Tokens a month', 'estasoft-news-distribution' ),
				'min'     => 1000,
				'max'     => 100000000000,
				'default' => 2000000,
				'width'   => 120,
				'help'    => $this->tokens_help(),
			),
			'currency'       => array(
				'type'    => 'enum',
				'label'   => __( 'Currency', 'estasoft-news-distribution' ),
				'default' => 'USD',
				'options' => array(
					'USD' => 'USD',
					'EUR' => 'EUR',
					'GBP' => 'GBP',
				),
				'width'   => 90,
			),
			'price_in'       => array(
				'type'        => 'string',
				'label'       => __( 'Per million tokens sent', 'estasoft-news-distribution' ),
				'max'         => 12,
				'default'     => '',
				'width'       => 90,
				'placeholder' => '0.00',
			),
			'price_out'      => array(
				'type'        => 'string',
				'label'       => __( 'Per million tokens received', 'estasoft-news-distribution' ),
				'max'         => 12,
				'default'     => '',
				'width'       => 90,
				'placeholder' => '0.00',
			),
			'on_limit'       => array(
				'type'    => 'enum',
				'label'   => __( 'When a limit is reached', 'estasoft-news-distribution' ),
				'default' => 'pause_tell',
				'options' => array(
					'pause_tell'  => __( 'Pause the assistant and tell me', 'estasoft-news-distribution' ),
					'pause_quiet' => __( 'Pause it quietly', 'estasoft-news-distribution' ),
				),
				'width'   => 260,
			),
			'reviewers'      => array(
				'type'        => 'map',
				'label'       => __( 'Signed-off reviewers', 'estasoft-news-distribution' ),
				'default'     => array(),
				'key_pattern' => '/^[a-z]{2,3}([_-][A-Za-z0-9]{2,8})?$/',
				'item'        => array(
					'type'      => 'list',
					'max_items' => 20,
					'item'      => array(
						'type' => 'int',
						'min'  => 1,
					),
				),
			),
		);
	}

	/**
	 * The help under Tokens a month: what the network allows, and what is counted.
	 *
	 * @return string
	 */
	private function tokens_help(): string {
		$cap  = ( new Policy() )->token_cap();
		$help = __( 'News Distribution counts what your provider reports. Prices differ, so it shows money only if you enter your price below.', 'estasoft-news-distribution' );

		if ( $cap < 1 ) {
			return $help;
		}

		return sprintf(
			/* translators: %s: the most tokens a month that the network allows, such as 2,000,000. */
			__( 'Set by your network: you can lower this, not raise it. The most your network allows is %s.', 'estasoft-news-distribution' ),
			number_format_i18n( $cap )
		) . ' ' . $help;
	}

	public function title(): string {
		return __( 'AI assistant', 'estasoft-news-distribution' );
	}

	public function description(): string {
		return __( 'Optional. Off until you turn it on, and nothing is sent before you confirm.', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return 'operations';
	}

	public function order(): int {
		return 25;
	}

	public function permission(): string {
		return Access::ADMIN;
	}

	/**
	 * Only WordPress 7.0 and newer has the AI Client. Elsewhere the section is not there.
	 *
	 * @return bool
	 */
	public function available(): bool {
		return version_compare( (string) get_bloginfo( 'version' ), '7.0', '>=' );
	}

	public function affects_output(): bool {
		return false;
	}

	/**
	 * With the confirmation dialog open the page is not a form: the dialog is, and a form cannot sit inside a form.
	 *
	 * @return bool
	 */
	public function in_form(): bool {
		return ! ConsentView::wanted();
	}

	public function has_save_bar(): bool {
		return ! ConsentView::wanted();
	}

	/**
	 * `&view=states` is the reference page of every state the assistant can be in.
	 *
	 * @param string $tab The `tab` or `view` argument.
	 * @return string|null
	 */
	public function render_tab( string $tab ): ?string {
		if ( 'states' === $tab ) {
			return ( new StatesView( Plugin::instance() ) )->page();
		}

		return null;
	}

	/**
	 * The form, or the form with the confirmation dialog over it.
	 *
	 * @param array<string, mixed> $values The section's values as the screen shows them.
	 * @return string|null
	 */
	public function render_custom( array $values ): ?string {
		if ( ! ConsentView::wanted() ) {
			return null;
		}

		$view = new SettingsView( Plugin::instance() );

		return $view->read_only( $this->layout(), $this->fields(), $values ) . ( new ConsentView( Plugin::instance() ) )->dialog();
	}

	/**
	 * Turns the reviewer check boxes of the form into the stored map.
	 *
	 * The form posts `reviewer_pick[<language>][]` for each language it printed, so a language with nobody
	 * chosen can be cleared. Languages the form did not print keep what they had.
	 *
	 * @param array<string, mixed> $input Unslashed input as submitted.
	 * @return array<string, mixed>
	 */
	public function prepare_input( array $input ): array {
		foreach ( array( 'price_in', 'price_out' ) as $price ) {
			if ( isset( $input[ $price ] ) && is_scalar( $input[ $price ] ) ) {
				$input[ $price ] = str_replace( ',', '.', trim( (string) $input[ $price ] ) );
			}
		}

		if ( isset( $input['reviewer_pick'] ) && is_array( $input['reviewer_pick'] ) ) {
			$plugin  = Plugin::instance();
			$current = $plugin->make( Config::class )->reviewers();
			$shown   = $plugin->make( Reviewers::class )->languages();
			$map     = array();

			foreach ( $current as $language => $ids ) {
				if ( ! in_array( $language, $shown, true ) ) {
					$map[ $language ] = $ids;
				}
			}

			foreach ( $shown as $language ) {
				$picked = isset( $input['reviewer_pick'][ $language ] ) && is_array( $input['reviewer_pick'][ $language ] ) ? $input['reviewer_pick'][ $language ] : array();
				$ids    = array_values( array_unique( array_filter( array_map( 'intval', $picked ) ) ) );

				if ( array() !== $ids ) {
					$map[ $language ] = $ids;
				}
			}

			$input['reviewers'] = $map;
		}

		unset( $input['reviewer_pick'] );

		return $input;
	}

	/**
	 * A limit a network holds a site to, prices that make sense and reviewers who may review.
	 *
	 * @param array<string, mixed> $values Cleaned values.
	 * @return array<string, string>
	 */
	public function cross_check( array $values ): array {
		$plugin = Plugin::instance();
		$errors = array();
		$cap    = $plugin->make( Policy::class )->token_cap();

		if ( $cap > 0 && (int) $values['monthly_tokens'] > $cap ) {
			$errors['monthly_tokens'] = sprintf(
				/* translators: %s: the most tokens a month that the network allows, such as 2,000,000. */
				__( 'Tokens a month can be at most %s, which is what your network allows.', 'estasoft-news-distribution' ),
				number_format_i18n( $cap )
			);
		}

		$in  = (string) $values['price_in'];
		$out = (string) $values['price_out'];

		foreach ( array(
			'price_in'  => $in,
			'price_out' => $out,
		) as $field => $price ) {
			if ( '' !== $price && ( ! is_numeric( $price ) || (float) $price < 0 || (float) $price > 100000 ) ) {
				$errors[ $field ] = __( 'Enter a price as a number such as 3.00, or leave both prices empty.', 'estasoft-news-distribution' );
			}
		}

		if ( array() === $errors && ( '' === $in ) !== ( '' === $out ) ) {
			$errors['price_out'] = __( 'Enter both prices, or leave both empty.', 'estasoft-news-distribution' );
		}

		$reviewers = $plugin->make( Reviewers::class );

		foreach ( (array) $values['reviewers'] as $language => $ids ) {
			foreach ( (array) $ids as $id ) {
				if ( ! user_can( (int) $id, 'edit_others_posts' ) ) {
					$errors['reviewers'] = __( 'A reviewer must be a person who can edit other people’s posts.', 'estasoft-news-distribution' );
					break 2;
				}
			}

			if ( ! $reviewers->accepts( (string) $language ) ) {
				$errors['reviewers'] = __( 'Choose reviewers only for the languages the site writes in.', 'estasoft-news-distribution' );
				break;
			}
		}

		return $errors;
	}

	/**
	 * With no feature on, nothing is on any more: the confirmation is forgotten, so the next time asks again.
	 *
	 * @param array<string, mixed> $values   The saved values.
	 * @param array<string, mixed> $previous The values before the save.
	 * @return void
	 */
	public function saved( array $values, array $previous ): void {
		unset( $previous );

		$plugin = Plugin::instance();

		if ( $plugin->make( Config::class )->any_on() ) {
			return;
		}

		$consent = $plugin->make( Consent::class );

		if ( null !== $consent->record() ) {
			delete_option( Consent::OPTION );
			$plugin->audit()->record( 'ai_consent_revoked', 0, '', get_current_user_id() );
		}

		if ( ! empty( $values['enabled'] ) ) {
			$plugin->settings()->save_section( 'ai', array( 'enabled' => false ) );
		}
	}

	/**
	 * The cards of the page. Each one is built by the view, so this class stays short.
	 *
	 * @return array{cards: array<int, array<string, mixed>>, notes: array<int, array<string, string>>, cannot: string[]}
	 */
	public function layout(): array {
		return ( new SettingsView( Plugin::instance() ) )->layout( $this->fields() );
	}
}
