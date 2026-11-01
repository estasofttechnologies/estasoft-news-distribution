<?php
/**
 * Settings, Platform rules.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Settings\Sections;

use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\Disclosure;
use Estasoft\NewsDistribution\Admin\Ui\Grid;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Core\RulePacks;
use Estasoft\NewsDistribution\Core\Settings\Section;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Modules\ContentSettingsModule;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * The rule packs that ship with this version, how sure each rule is, and where it came from.
 *
 * Stored as `estasoft_nd_settings.rules`: `check_updates`, which is off and cannot be
 * turned on in 1.0. This version does not look for new packs and downloads nothing, so
 * there is no request to describe, no code to trust and no signature to check. What a person
 * can do is choose an older bundled pack for a platform (`Core\RulePacks::use_version()`),
 * with a button that posts to `admin-post.php`. Only an administrator may open this section.
 */
final class Rules extends Section {

	public const NAME = 'rules';

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array {
		return array(
			'check_updates' => array(
				'type'    => 'bool',
				'label'   => __( 'Check for rule updates', 'estasoft-news-distribution' ),
				'default' => false,
			),
		);
	}

	public function title(): string {
		return __( 'Platform rules', 'estasoft-news-distribution' );
	}

	public function description(): string {
		return __( 'These apply to every channel unless a channel says otherwise.', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return 'content';
	}

	public function order(): int {
		return 60;
	}

	public function permission(): string {
		return 'admin';
	}

	/**
	 * Every choice here is a button of its own, so the page has no save bar and no one form.
	 *
	 * @return bool
	 */
	public function has_save_bar(): bool {
		return false;
	}

	/**
	 * @return bool
	 */
	public function in_form(): bool {
		return false;
	}

	/**
	 * Saving this section changes no feed, so the output cache stays.
	 *
	 * @return bool
	 */
	public function affects_output(): bool {
		return false;
	}

	/**
	 * Rule updates cannot be turned on in this version.
	 *
	 * @param array<string, mixed> $values Cleaned values.
	 * @return array<string, string>
	 */
	public function cross_check( array $values ): array {
		if ( ! empty( $values['check_updates'] ) ) {
			return array(
				'check_updates' => __( 'Check for rule updates cannot be turned on in this version. News Distribution does not look for new rule packs and downloads nothing.', 'estasoft-news-distribution' ),
			);
		}

		return array();
	}

	/**
	 * @return array{cards: array<int, array<string, mixed>>, notes: array<int, array<string, string>>, cannot: string[]}
	 */
	public function layout(): array {
		return array(
			'cards'  => array(
				array(
					'id'          => 'updates',
					'title'       => __( 'Rule updates', 'estasoft-news-distribution' ),
					'description' => __( 'Platforms change their rules. A rule pack is a small data file that holds them, and a newer pack ships with a plugin update.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array(
							'label' => __( 'Check for updates', 'estasoft-news-distribution' ),
							'html'  => array( $this, 'render_switch' ),
						),
						array(
							'label' => __( 'What is sent, and to whom', 'estasoft-news-distribution' ),
							'html'  => array( $this, 'render_sent' ),
						),
						array(
							'label' => __( 'What is received', 'estasoft-news-distribution' ),
							'html'  => array( $this, 'render_received' ),
						),
					),
				),
				array(
					'id'          => 'packs',
					'title'       => __( 'Rule packs', 'estasoft-news-distribution' ),
					'description' => __( 'One pack per platform or standard. Pack numbers are year, month and revision.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array(
							'label' => '',
							'html'  => array( $this, 'render_packs' ),
						),
					),
				),
			),
			'notes'  => array(
				array(
					'title' => __( 'How to read a rule', 'estasoft-news-distribution' ),
					'html'  => $this->reading_body(),
				),
			),
			'cannot' => array(
				__( 'Make a platform accept an article. A pack records what a platform publishes, and the platform decides.', 'estasoft-news-distribution' ),
				__( 'Confirm a rule with the platform. A rule marked unverified has not been read from the platform’s own page yet.', 'estasoft-news-distribution' ),
				__( 'Download rules or code. This version reads only the packs that ship with it, and a plugin update brings newer ones.', 'estasoft-news-distribution' ),
			),
		);
	}

	/**
	 * The card that lists every rule of every pack, closed until opened.
	 *
	 * @param array<string, mixed> $values The section's values.
	 * @return string Escaped HTML.
	 */
	public function render_extra( array $values ): string {
		$service = $this->service();
		$body    = '';

		foreach ( $service->all() as $pack ) {
			$rules = $service->rules_of( (string) $pack['platform'] );
			$rows  = array();

			foreach ( $rules as $rule ) {
				$rows[] = array(
					Html::tag( 'code', array(), esc_html( $rule['code'] ) ),
					StatusView::inline( $this->severity_code( $rule['severity'] ) ),
					esc_html( $this->confidence_word( $rule['confidence'] ) ),
					$this->source_html( $rule['source'], $rule['read_on'] ),
				);
			}

			$table = array() === $rows
				? Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-pad' ), esc_html__( 'This pack has no rules of its own.', 'estasoft-news-distribution' ) )
				: Grid::render(
					'estasoft-nd-cols-rule',
					array(
						__( 'Rule', 'estasoft-news-distribution' ),
						__( 'When it applies', 'estasoft-news-distribution' ),
						__( 'How sure', 'estasoft-news-distribution' ),
						__( 'Source and date read', 'estasoft-news-distribution' ),
					),
					$rows,
					/* translators: %s: a platform name, such as Google News. */
					sprintf( __( 'Rules in the %s pack', 'estasoft-news-distribution' ), (string) $pack['label'] )
				);

			$body .= Disclosure::render(
				(string) $pack['label'],
				sprintf(
					/* translators: %s: a rule pack version such as 2026.09.2. */
					__( 'Pack %s', 'estasoft-news-distribution' ),
					(string) $pack['version']
				),
				sprintf(
					/* translators: %s: number of rules. */
					_n( '%s rule', '%s rules', (int) $pack['rules'], 'estasoft-news-distribution' ),
					number_format_i18n( (int) $pack['rules'] )
				),
				$table
			);
		}

		if ( '' === $body ) {
			return '';
		}

		return Card::flush(
			'rules-each',
			__( 'Rules in each pack', 'estasoft-news-distribution' ),
			__( 'Every rule lists its source and the date it was read, so you can read the platform’s own words.', 'estasoft-news-distribution' ),
			$body
		);
	}

	/**
	 * The "Check for updates" row: a box that cannot be ticked, and why.
	 *
	 * @return string Escaped HTML.
	 */
	public function render_switch(): string {
		return Html::tag(
			'label',
			array( 'class' => 'estasoft-nd-check estasoft-nd-check--top' ),
			Html::void(
				'input',
				array(
					'type'     => 'checkbox',
					'disabled' => true,
				)
			)
			. Html::tag(
				'span',
				array(),
				Html::tag( 'strong', array(), esc_html__( 'Check once a day', 'estasoft-news-distribution' ) )
				. '<br>'
				. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Not available in this version, so it is off and makes no request. The rules that ship with this version keep working.', 'estasoft-news-distribution' ) )
			)
		);
	}

	/**
	 * The "What is sent, and to whom" row.
	 *
	 * @return string Escaped HTML.
	 */
	public function render_sent(): string {
		return Html::tag(
			'p',
			array(),
			esc_html__( 'Nothing. This version never contacts anyone about rule packs, not the plugin’s version, not your site’s address and not the platforms you use.', 'estasoft-news-distribution' )
		);
	}

	/**
	 * The "What is received" row.
	 *
	 * @return string Escaped HTML.
	 */
	public function render_received(): string {
		return Html::tag(
			'p',
			array(),
			esc_html__( 'Nothing. A pack is a data file inside the plugin, and a plugin update brings newer ones. No code and no rules arrive from outside, so nothing from outside can change a rule.', 'estasoft-news-distribution' )
		);
	}

	/**
	 * The rule packs table, with the roll back buttons.
	 *
	 * @return string Escaped HTML.
	 */
	public function render_packs(): string {
		$rows = array();

		foreach ( $this->service()->all() as $pack ) {
			$rows[] = array(
				Html::tag( 'strong', array(), esc_html( (string) $pack['label'] ) ),
				Html::tag( 'code', array(), esc_html( (string) $pack['version'] ) ),
				$this->source_cell( $pack ),
				Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $this->counts_text( (array) $pack['counts'] ) ) ),
				$pack['rolled_back']
					? StatusView::inline(
						Status::PROCESSING,
						sprintf(
							/* translators: %s: a rule pack version such as 2026.10.1. */
							__( 'Older pack in use. Newest is %s', 'estasoft-news-distribution' ),
							(string) $pack['newest']
						)
					)
					: StatusView::inline( Status::INSTALLED, __( 'In use', 'estasoft-news-distribution' ) ),
				$this->action_cell( $pack ),
			);
		}

		$table = array() === $rows
			? Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-pad' ), esc_html__( 'No rule packs are installed.', 'estasoft-news-distribution' ) )
			: Grid::render(
				'estasoft-nd-cols-packs',
				array(
					__( 'Platform or standard', 'estasoft-news-distribution' ),
					__( 'Installed pack', 'estasoft-news-distribution' ),
					__( 'Where the rules come from', 'estasoft-news-distribution' ),
					__( 'How sure', 'estasoft-news-distribution' ),
					__( 'Status', 'estasoft-news-distribution' ),
					'',
				),
				$rows,
				__( 'Installed rule packs', 'estasoft-news-distribution' )
			);

		return $this->flash()
			. $table
			. Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small estasoft-nd-pad' ),
				esc_html__( 'Roll back is offered only when an older pack ships with this version. It asks for no confirmation, because you can use the newest pack again at any time.', 'estasoft-news-distribution' )
			);
	}

	/**
	 * The body of the "How to read a rule" card.
	 *
	 * @return string Escaped HTML.
	 */
	private function reading_body(): string {
		$items = array(
			array(
				__( 'Confirmed', 'estasoft-news-distribution' ),
				__( 'A page of the platform, or a named source, was read and the rule follows it. The date read is shown.', 'estasoft-news-distribution' ),
			),
			array(
				__( 'Softened', 'estasoft-news-distribution' ),
				__( 'The claim could not be confirmed, so the wording stays careful. Check with the platform.', 'estasoft-news-distribution' ),
			),
			array(
				__( 'Unverified', 'estasoft-news-distribution' ),
				__( 'Not read from a source yet. Treat it as a guess until a date appears.', 'estasoft-news-distribution' ),
			),
		);
		$html  = '';

		foreach ( $items as $item ) {
			$html .= Html::tag(
				'div',
				array(),
				Html::tag( 'strong', array(), esc_html( $item[0] ) ) . Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( $item[1] ) )
			);
		}

		return $html;
	}

	/**
	 * The message after a roll back, built from what is installed now.
	 *
	 * @return string Escaped HTML.
	 */
	private function flash(): string {
		$platform = Query::key( 'rule_pack' );

		if ( '' === $platform ) {
			if ( Query::is( 'rule_pack_error', '1' ) ) {
				return Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-pad' ),
					Message::text( 'danger', __( 'That pack could not be used. Only packs that ship with this version of News Distribution can be chosen.', 'estasoft-news-distribution' ) )
				);
			}

			return '';
		}

		$pack = $this->service()->describe( $platform );

		if ( array() === $pack ) {
			return '';
		}

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-pad' ),
			Message::text(
				'success',
				sprintf(
					/* translators: 1: a platform name such as Google News, 2: a rule pack version such as 2026.09.2. */
					__( '%1$s now uses pack %2$s.', 'estasoft-news-distribution' ),
					(string) $pack['label'],
					(string) $pack['version']
				)
			)
		);
	}

	/**
	 * Where a pack's rules come from, and when they were read.
	 *
	 * @param array<string, mixed> $pack One pack from RulePacks::describe().
	 * @return string Escaped HTML.
	 */
	private function source_cell( array $pack ): string {
		$source = '' === (string) $pack['source'] ? __( 'No source named', 'estasoft-news-distribution' ) : (string) $pack['source'];
		$read   = null === $pack['read_on']
			? __( 'Not read from its source yet', 'estasoft-news-distribution' )
			: sprintf(
				/* translators: %s: a date such as 21 Oct 2026. */
				__( 'Read on %s', 'estasoft-news-distribution' ),
				$this->date( (string) $pack['read_on'] )
			);

		return Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small estasoft-nd-block' ), esc_html( $source ) )
			. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small estasoft-nd-block' ), esc_html( $read ) );
	}

	/**
	 * The roll back or use newest button of one row, as its own form.
	 *
	 * @param array<string, mixed> $pack One pack from RulePacks::describe().
	 * @return string Escaped HTML.
	 */
	private function action_cell( array $pack ): string {
		$platform = (string) $pack['platform'];
		$older    = array_values( (array) $pack['older'] );

		if ( $pack['rolled_back'] ) {
			$label   = __( 'Use newest', 'estasoft-news-distribution' );
			$version = '';
		} elseif ( array() !== $older ) {
			$label   = __( 'Roll back', 'estasoft-news-distribution' );
			$version = (string) $older[0];
		} else {
			return '';
		}

		return Html::tag(
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
					'value' => 'estasoft_nd_rules_use',
				)
			)
			. Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'platform',
					'value' => $platform,
				)
			)
			. Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'version',
					'value' => $version,
				)
			)
			. wp_nonce_field( ContentSettingsModule::NONCE_USE_PACK, '_wpnonce', true, false )
			. Html::tag(
				'button',
				array(
					'type'       => 'submit',
					'class'      => 'estasoft-nd-btn estasoft-nd-btn--subtle',
					'aria-label' => sprintf(
						/* translators: 1: the button text, such as Roll back, 2: a platform name such as Google News. */
						__( '%1$s: %2$s', 'estasoft-news-distribution' ),
						$label,
						(string) $pack['label']
					),
				),
				esc_html( $label )
			)
		);
	}

	/**
	 * "3 confirmed, 2 softened, 10 unverified".
	 *
	 * @param array<string, mixed> $counts Counts by confidence.
	 * @return string
	 */
	private function counts_text( array $counts ): string {
		$parts = array();

		if ( ! empty( $counts['confirmed'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: number of rules. */
				__( '%s confirmed', 'estasoft-news-distribution' ),
				number_format_i18n( (int) $counts['confirmed'] )
			);
		}

		if ( ! empty( $counts['softened'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: number of rules. */
				__( '%s softened', 'estasoft-news-distribution' ),
				number_format_i18n( (int) $counts['softened'] )
			);
		}

		if ( ! empty( $counts['unverified'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: number of rules. */
				__( '%s unverified', 'estasoft-news-distribution' ),
				number_format_i18n( (int) $counts['unverified'] )
			);
		}

		return array() === $parts ? __( 'No rules', 'estasoft-news-distribution' ) : implode( ', ', $parts );
	}

	/**
	 * The word for a confidence.
	 *
	 * @param string $confidence confirmed, softened or unverified.
	 * @return string
	 */
	private function confidence_word( string $confidence ): string {
		switch ( $confidence ) {
			case 'confirmed':
				return __( 'Confirmed', 'estasoft-news-distribution' );
			case 'softened':
				return __( 'Softened', 'estasoft-news-distribution' );
		}

		return __( 'Unverified', 'estasoft-news-distribution' );
	}

	/**
	 * A rule's severity as a Status code.
	 *
	 * @param string $severity block, warn or info.
	 * @return string
	 */
	private function severity_code( string $severity ): string {
		return in_array( $severity, array( Status::BLOCK, Status::WARN, Status::INFO ), true ) ? $severity : Status::INFO;
	}

	/**
	 * A rule's source: a link when it is an address, the text otherwise, then the date read.
	 *
	 * @param string      $source  The source.
	 * @param string|null $read_on The date it was read, or null.
	 * @return string Escaped HTML.
	 */
	private function source_html( string $source, ?string $read_on ): string {
		$read = null === $read_on
			? __( 'not read yet', 'estasoft-news-distribution' )
			: sprintf(
				/* translators: %s: a date such as 21 Oct 2026. */
				__( 'read on %s', 'estasoft-news-distribution' ),
				$this->date( $read_on )
			);
		$host = '' === $source ? '' : wp_parse_url( $source, PHP_URL_HOST );
		$text = '';

		if ( is_string( $host ) && '' !== $host && 0 === strpos( $source, 'http' ) ) {
			$text = Html::tag(
				'a',
				array(
					'href'   => $source,
					'target' => '_blank',
					'rel'    => 'noopener noreferrer',
				),
				esc_html( $host )
			);
		} elseif ( '' !== $source ) {
			$text = esc_html( $source );
		}

		return ( '' === $text ? '' : $text . ', ' ) . Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html( $read ) );
	}

	/**
	 * A date such as 2026-10-21 as "21 Oct 2026".
	 *
	 * @param string $date A date, year first.
	 * @return string
	 */
	private function date( string $date ): string {
		$time = strtotime( $date . ' 12:00:00 UTC' );

		return false === $time ? $date : wp_date( 'j M Y', $time, new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * The rule packs service.
	 *
	 * @return RulePacks
	 */
	private function service(): RulePacks {
		return Plugin::instance()->make( RulePacks::class );
	}
}
