<?php
/**
 * Settings, Access and gate.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Settings\Sections;

use Estasoft\NewsDistribution\Core\Settings\Section;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Who can use News Distribution, and what the pre-publish gate does.
 *
 * Only Administrators save this section. Nothing here can be given to people
 * below the roles the spec names.
 */
final class Access extends Section {

	public const NAME = 'access';

	/**
	 * Channels that are on and have rules an article can fail, for the gate list.
	 *
	 * @return array<string, array{label: string, help: string}>
	 */
	public static function gate_channel_options(): array {
		$options = array();

		foreach ( Plugin::instance()->registry()->enabled() as $channel ) {
			$note = $channel->gate_note();

			if ( '' === $note ) {
				continue;
			}

			$options[ $channel->id() ] = array(
				'label' => $channel->label(),
				'help'  => $note,
			);
		}

		return $options;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array {
		return array(
			'article_check'        => array(
				'type'    => 'enum',
				'label'   => __( 'See Article check', 'estasoft-news-distribution' ),
				'default' => 'editors',
				'control' => 'radio',
				'options' => array(
					'admins'      => array(
						'label' => __( 'Administrators only', 'estasoft-news-distribution' ),
						'help'  => __( 'Only administrators open Article check.', 'estasoft-news-distribution' ),
					),
					'editors'     => array(
						'label' => __( 'Editors and above', 'estasoft-news-distribution' ),
						'help'  => __( 'The default. Editors and administrators see every article. An author cannot open Article check, so authors are not offered when someone assigns a fix.', 'estasoft-news-distribution' ),
					),
					'authors_own' => array(
						'label' => __( 'Authors, only their own articles', 'estasoft-news-distribution' ),
						'help'  => __( 'Authors open Article check and see only their own articles and the fixes assigned to them. Editors and administrators still see everything.', 'estasoft-news-distribution' ),
					),
				),
			),
			'manage'               => array(
				'type'    => 'enum',
				'label'   => __( 'Change channels and settings', 'estasoft-news-distribution' ),
				'default' => 'admins',
				'control' => 'select',
				'width'   => 260,
				'options' => array(
					'admins'  => __( 'Administrators only', 'estasoft-news-distribution' ),
					'editors' => __( 'Editors and above', 'estasoft-news-distribution' ),
				),
				'help'    => __( 'Requesting a listing or changing a channel’s rules affects how your articles reach platforms, so keep this to the people who answer for it.', 'estasoft-news-distribution' ),
			),
			'gate_mode'            => array(
				'type'    => 'enum',
				'label'   => __( 'When a check fails', 'estasoft-news-distribution' ),
				'default' => 'off',
				'control' => 'radio',
				'options' => array(
					'off'   => array(
						'label' => __( 'Don’t check when publishing', 'estasoft-news-distribution' ),
						'help'  => __( 'Checks still run in Article check. Nothing is shown in the publish panel.', 'estasoft-news-distribution' ),
					),
					'warn'  => array(
						'label' => __( 'Warn', 'estasoft-news-distribution' ),
						'help'  => __( 'Show the problem in the publish panel. Anyone who can publish can go ahead.', 'estasoft-news-distribution' ),
					),
					'block' => array(
						'label' => __( 'Block publishing', 'estasoft-news-distribution' ),
						'help'  => __( 'The article cannot go live until it passes, unless someone with the role chosen below publishes it anyway.', 'estasoft-news-distribution' ),
					),
				),
			),
			'gate_override'        => array(
				'type'    => 'enum',
				'label'   => __( 'Who can override', 'estasoft-news-distribution' ),
				'default' => 'editors',
				'control' => 'select',
				'width'   => 260,
				'options' => array(
					'editors' => __( 'Editors and above', 'estasoft-news-distribution' ),
					'admins'  => __( 'Administrators only', 'estasoft-news-distribution' ),
				),
				'help'    => __( 'Authors cannot override. An author’s article that fails stays unpublished until it passes.', 'estasoft-news-distribution' ),
			),
			'gate_reason_required' => array(
				'type'    => 'bool',
				'label'   => __( 'Ask for a reason', 'estasoft-news-distribution' ),
				'default' => true,
				'control' => 'checkbox',
				'title'   => __( 'Ask for a reason', 'estasoft-news-distribution' ),
				'help'    => __( 'The person types a reason before publishing anyway. It is saved with the article’s record in the delivery log and kept for as long as the delivery log is (30 days by default).', 'estasoft-news-distribution' ),
			),
			'gate_channels'        => array(
				'type'      => 'list',
				'label'     => __( 'Channels that must pass', 'estasoft-news-distribution' ),
				'default'   => array(),
				'control'   => 'checkboxes',
				'item'      => array(
					'type'   => 'string',
					'format' => 'key',
					'max'    => 60,
				),
				'options'   => array( self::class, 'gate_channel_options' ),
				'max_items' => 40,
				'help'      => __( 'The list shows the channels that are set up and have rules an article can fail. Channels that are not set up are not listed; they appear here once they are.', 'estasoft-news-distribution' ),
			),
		);
	}

	public function title(): string {
		return __( 'Access and gate', 'estasoft-news-distribution' );
	}

	public function description(): string {
		return __( 'Choose who can open each area, and what happens before an article goes live.', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return 'operations';
	}

	public function order(): int {
		return 50;
	}

	public function permission(): string {
		return 'admin';
	}

	public function affects_output(): bool {
		return false;
	}

	/**
	 * @return array{cards: array<int, array<string, mixed>>, cannot: string[]}
	 */
	public function layout(): array {
		return array(
			'cards'  => array(
				array(
					'id'          => 'who',
					'title'       => __( 'Who can use News Distribution', 'estasoft-news-distribution' ),
					'description' => __( 'Choose who can open each area.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array( 'field' => 'article_check' ),
						array( 'field' => 'manage' ),
					),
				),
				array(
					'id'          => 'gate',
					'title'       => __( 'Pre-publish gate', 'estasoft-news-distribution' ),
					'description' => __( 'Check an article against channels before it can go live.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array(
							'field'  => 'gate_mode',
							'nested' => array(
								'show_if' => array( 'gate_mode' => 'block' ),
								'note'    => __( 'These two settings appear only when Block publishing is chosen.', 'estasoft-news-distribution' ),
								'fields'  => array( 'gate_override', 'gate_reason_required' ),
							),
						),
						array( 'field' => 'gate_channels' ),
					),
				),
			),
			'notes'  => array(
				array(
					'title' => __( 'Where the gate applies', 'estasoft-news-distribution' ),
					'text'  => __( 'When someone presses Publish or Schedule in the block editor or the classic editor, uses Quick Edit or bulk edit, or publishes through the REST API or XML-RPC, and Block publishing is chosen. A scheduled post is checked when it is scheduled, not again when it goes live. Warn only shows the problem and stops nothing.', 'estasoft-news-distribution' ),
				),
			),
			'cannot' => array(
				__( 'Stop an article that is published by an import or WP-CLI, or a scheduled post when WordPress publishes it on time. Those are checked in Article check after they go live.', 'estasoft-news-distribution' ),
				__( 'Hold an article back when the check cannot run or takes longer than 2 seconds. The article goes out, and the audit log says why, so the gate never locks you out.', 'estasoft-news-distribution' ),
				__( 'Make a platform accept an article. It applies News Distribution’s own checks only.', 'estasoft-news-distribution' ),
			),
		);
	}
}
