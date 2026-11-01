<?php
/**
 * Step 2 of the setup: which channels to turn on.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Setup;

use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\Tag;
use Estasoft\NewsDistribution\Modules\SetupModule;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Three starting setups, then every channel in its group with what it asks of the person (an approval, a
 * verified site, an invitation). Pushes and Listings are folded, with a count of what is chosen.
 *
 * Without a script, choosing a setup and pressing Continue applies that setup. With the script, choosing
 * a setup ticks the boxes at once and the boxes decide. `Admin\Setup\ChannelChoices::read()` reads both.
 */
final class ChannelsStep {

	private Plugin $plugin;

	/**
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * The text the script fills in, with the same placeholders as the server's.
	 *
	 * @return string
	 */
	public static function count_pattern(): string {
		/* translators: 1: how many channels of the group are chosen, 2: how many it has. */
		return __( '%1$s of %2$s selected', 'estasoft-news-distribution' );
	}

	/**
	 * The screen.
	 *
	 * @return string Escaped HTML.
	 */
	public function render(): string {
		$choices  = new ChannelChoices( $this->plugin );
		$state    = Onboarding::state();
		$selected = $choices->selected( $state['preset'] );
		$preset   = $choices->matching( $selected );

		$html = $this->presets( $choices, $preset ) . Frame::hidden( ChannelChoices::SHOWN, $preset );

		foreach ( $choices->groups() as $id => $group ) {
			$html .= $this->group( (string) $id, $group, $selected );
		}

		$html .= Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
			esc_html__( 'Where a platform decides, News Distribution prepares everything first and tells you what to do next.', 'estasoft-news-distribution' )
		);

		return Frame::page(
			'channels',
			__( 'Where should your articles go?', 'estasoft-news-distribution' ),
			__( 'Start with what needs no approval. You can add the rest later in Channels.', 'estasoft-news-distribution' ),
			Frame::form( 'channels', Html::tag( 'div', array( 'class' => 'estasoft-nd-setup__stack' ), $html ) )
			. Frame::actions(
				'channels',
				array(
					'back'     => Onboarding::previous( 'channels' ) === Onboarding::START ? '' : SetupModule::url( Onboarding::previous( 'channels' ) ),
					'skip'     => array(
						'label' => __( 'Skip for now', 'estasoft-news-distribution' ),
						'go'    => 'skip_step',
					),
					'continue' => __( 'Continue', 'estasoft-news-distribution' ),
				)
			)
		);
	}

	/**
	 * The three starting setups.
	 *
	 * @param ChannelChoices $choices The choices.
	 * @param string         $preset  The setup that matches what is ticked.
	 * @return string
	 */
	private function presets( ChannelChoices $choices, string $preset ): string {
		$cards = '';

		foreach ( ChannelChoices::presets() as $id => $item ) {
			$checked = (string) $id === $preset;
			$tag     = $item['recommended'] ? Tag::highlight( __( 'Recommended', 'estasoft-news-distribution' ) ) : '';

			$cards .= Html::tag(
				'label',
				array( 'class' => Html::classes( 'estasoft-nd-choice', 'estasoft-nd-setup__choice', $checked ? 'estasoft-nd-choice--on' : '' ) ),
				Html::void(
					'input',
					array(
						'type'          => 'radio',
						'name'          => ChannelChoices::PRESET,
						'value'         => (string) $id,
						'checked'       => $checked,
						'data-channels' => implode( ' ', $choices->for_preset( (string) $id ) ),
					)
				)
				. Html::tag(
					'span',
					array( 'class' => 'estasoft-nd-setup__choice-body' ),
					Html::tag( 'strong', array(), esc_html( $item['title'] ) )
					. $tag
					. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html( $item['description'] ) )
				)
			);
		}

		return Html::tag(
			'fieldset',
			array( 'class' => 'estasoft-nd-setup__presets' ),
			Html::tag( 'legend', array( 'class' => 'estasoft-nd-title' ), esc_html__( 'Start from a setup', 'estasoft-news-distribution' ) )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-setup__preset-cards' ), $cards )
		);
	}

	/**
	 * One group: a card with its rows, or a folded card with a count.
	 *
	 * @param string               $id       The group id.
	 * @param array<string, mixed> $group    The group.
	 * @param string[]             $selected The ids that are ticked.
	 * @return string
	 */
	private function group( string $id, array $group, array $selected ): string {
		$rows  = '';
		$total = 0;
		$on    = 0;

		foreach ( $group['rows'] as $row ) {
			$ticked = 'always' === $row['kind'] || in_array( (string) $row['id'], $selected, true );
			$rows  .= $this->row( $row, $ticked );

			if ( 'toggle' === $row['kind'] ) {
				++$total;
				$on += $ticked ? 1 : 0;
			}
		}

		$title_id = 'estasoft-nd-setup-group-' . $id;

		if ( ! $group['collapsed'] ) {
			return Html::tag(
				'section',
				array(
					'class'           => 'estasoft-nd-card estasoft-nd-card--flush',
					'aria-labelledby' => $title_id,
				),
				Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-group__head' ),
					Html::tag(
						'h2',
						array(
							'class' => 'estasoft-nd-title',
							'id'    => $title_id,
						),
						esc_html( (string) $group['title'] )
					)
					. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( (string) $group['help'] ) )
				) . Html::tag( 'div', array( 'class' => 'estasoft-nd-setup__rows' ), $rows )
			);
		}

		if ( $total > 0 ) {
			$count = Html::tag(
				'span',
				array(
					'class'        => 'estasoft-nd-setup__count estasoft-nd-muted',
					'data-group'   => $id,
					'data-total'   => (string) $total,
					'data-pattern' => self::count_pattern(),
					'aria-live'    => 'polite',
					'aria-atomic'  => 'true',
				),
				esc_html( sprintf( self::count_pattern(), (string) $on, (string) $total ) )
			);
		} else {
			$count = Html::tag(
				'span',
				array( 'class' => 'estasoft-nd-setup__count estasoft-nd-muted' ),
				esc_html(
					sprintf(
						/* translators: %d: how many platforms the group has. */
						_n( '%d platform', '%d platforms', count( $group['rows'] ), 'estasoft-news-distribution' ),
						count( $group['rows'] )
					)
				)
			);
		}

		return Html::tag(
			'section',
			array(
				'class'           => 'estasoft-nd-card estasoft-nd-card--flush',
				'aria-labelledby' => $title_id,
			),
			Html::tag(
				'details',
				array( 'class' => 'estasoft-nd-collapse estasoft-nd-setup__fold' ),
				Html::tag(
					'summary',
					array(),
					Html::tag(
						'span',
						array( 'class' => 'estasoft-nd-setup__fold-title' ),
						Html::tag(
							'h2',
							array(
								'class' => 'estasoft-nd-title',
								'id'    => $title_id,
							),
							esc_html( (string) $group['title'] )
						)
						. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html( (string) $group['help'] ) )
					)
					. Html::tag(
						'span',
						array( 'class' => 'estasoft-nd-setup__fold-end' ),
						$count
						. Html::tag(
							'span',
							array( 'class' => 'estasoft-nd-strong estasoft-nd-setup__toggle' ),
							Html::tag( 'span', array( 'class' => 'estasoft-nd-setup__show' ), esc_html__( 'Show', 'estasoft-news-distribution' ) )
							. Html::tag( 'span', array( 'class' => 'estasoft-nd-setup__hide' ), esc_html__( 'Hide', 'estasoft-news-distribution' ) )
						)
					)
				)
				. Html::tag( 'div', array( 'class' => 'estasoft-nd-setup__rows' ), $rows )
			)
		);
	}

	/**
	 * One channel: a box, its name, what it is, and what it asks of the person.
	 *
	 * @param array<string, mixed> $row The row.
	 * @param bool                 $on  Whether it is ticked.
	 * @return string
	 */
	private function row( array $row, bool $on ): string {
		$id   = 'estasoft-nd-setup-channel-' . sanitize_key( (string) $row['id'] );
		$note = Html::tag(
			'span',
			array( 'class' => 'estasoft-nd-setup__row-note estasoft-nd-muted estasoft-nd-small' ),
			Icon::svg( 'info' ) . Html::tag( 'span', array(), esc_html( (string) $row['note'] ) )
		);
		$text = Html::tag(
			'span',
			array( 'class' => 'estasoft-nd-group-row__name' ),
			Html::tag( 'strong', array(), esc_html( (string) $row['name'] ) )
			. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html( (string) $row['description'] ) )
		);

		if ( 'link' === $row['kind'] ) {
			return Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-group-row' ),
				$text
				. Html::tag(
					'a',
					array(
						'href'   => (string) $row['url'],
						'target' => '_blank',
						'rel'    => 'noopener',
					),
					esc_html(
						sprintf(
							/* translators: %s: the name of a channel. */
							__( 'Set up %s in Channels', 'estasoft-news-distribution' ),
							(string) $row['name']
						)
					) . Icon::svg( 'external-link' )
				)
			);
		}

		$always = 'always' === $row['kind'];

		return Html::tag(
			'label',
			array(
				'class' => 'estasoft-nd-group-row estasoft-nd-setup__row',
				'for'   => $id,
			),
			Html::void(
				'input',
				array(
					'type'     => 'checkbox',
					'id'       => $id,
					'name'     => ChannelChoices::FIELD . '[]',
					'value'    => (string) $row['id'],
					'checked'  => $on,
					'disabled' => $always,
				)
			)
			. $text
			. $note
		);
	}
}
