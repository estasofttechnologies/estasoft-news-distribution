<?php
/**
 * Settings, Connections.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Settings\Sections;

use Estasoft\NewsDistribution\Admin\Operations\ConnectionsView;
use Estasoft\NewsDistribution\Core\Settings\Section;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Optional accounts at Yandex Webmaster, Google Search Console and Bing Webmaster
 * Tools. Baidu is hand-set and has no connection.
 *
 * The credentials are write-only secrets kept in `Core\Secrets` as
 * `connections_<field>`. They are never returned by the REST API, never printed,
 * and never part of an export, an import or a restore point. The plain fields
 * (`google_property`, `bing_site_url` and the two client IDs of the person's own sign-in apps)
 * are ordinary text. Of the secrets, `yandex_token` and `google_key` are what the person pastes
 * to connect with a key, and `google_client_secret` and `yandex_client_secret` belong to the
 * person's own app for the sign-in way (`Integrations\OAuth\Flow`). The token a sign-in
 * gives is a secret too (`connections_<provider>_oauth_token`), written by `Core\Connections`
 * and never by a form.
 *
 * The screen is made of separate actions (Connect, Check now, Disconnect), each
 * a form of its own, so the section is not wrapped in the settings form and has
 * no save bar. The actions are `Core\Connections` and `OperationsModule`.
 */
final class Connections extends Section {

	public const NAME = 'connections';

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array {
		return array(
			'yandex_token'         => array(
				'type'  => 'secret',
				'label' => __( 'Yandex access token', 'estasoft-news-distribution' ),
				'max'   => 600,
			),
			'yandex_client_id'     => array(
				'type'    => 'string',
				'label'   => __( 'Yandex client ID', 'estasoft-news-distribution' ),
				'default' => '',
				'max'     => 100,
			),
			'yandex_client_secret' => array(
				'type'  => 'secret',
				'label' => __( 'Yandex client secret', 'estasoft-news-distribution' ),
				'max'   => 300,
			),
			'google_key'           => array(
				'type'  => 'secret',
				'label' => __( 'Google service account key', 'estasoft-news-distribution' ),
				'max'   => 10000,
			),
			'google_client_id'     => array(
				'type'    => 'string',
				'label'   => __( 'Google client ID', 'estasoft-news-distribution' ),
				'default' => '',
				'max'     => 300,
			),
			'google_client_secret' => array(
				'type'  => 'secret',
				'label' => __( 'Google client secret', 'estasoft-news-distribution' ),
				'max'   => 300,
			),
			'google_property'      => array(
				'type'    => 'string',
				'label'   => __( 'Search Console property', 'estasoft-news-distribution' ),
				'default' => '',
				'max'     => 300,
			),
			'bing_key'             => array(
				'type'  => 'secret',
				'label' => __( 'Bing API key', 'estasoft-news-distribution' ),
				'max'   => 200,
			),
			'bing_site_url'        => array(
				'type'    => 'string',
				'label'   => __( 'Site address in Bing', 'estasoft-news-distribution' ),
				'default' => '',
				'max'     => 300,
			),
		);
	}

	public function title(): string {
		return __( 'Connections', 'estasoft-news-distribution' );
	}

	public function description(): string {
		return __( 'Optional. Connect a search engine account so News Distribution can submit your sitemaps and read their status there.', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return 'operations';
	}

	public function order(): int {
		return 10;
	}

	public function permission(): string {
		return 'admin';
	}

	public function has_save_bar(): bool {
		return false;
	}

	public function in_form(): bool {
		return false;
	}

	public function affects_output(): bool {
		return false;
	}

	/**
	 * @param array<string, mixed> $values The section's values as the screen shows them.
	 * @return string
	 */
	public function render_custom( array $values ): ?string {
		return ( new ConnectionsView( Plugin::instance() ) )->html( $values ) . ConnectionsView::notes();
	}
}
