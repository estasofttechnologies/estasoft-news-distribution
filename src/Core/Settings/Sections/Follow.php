<?php
/**
 * Settings, Follow links.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Settings\Sections;

use Estasoft\NewsDistribution\Core\Settings\Section;
use Estasoft\NewsDistribution\Follow\Accounts;
use Estasoft\NewsDistribution\Follow\Address;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Where the Follow block sends visitors, and whether it is added to the end of every article.
 *
 * Stored as `estasoft_nd_settings.follow`:
 *
 * - `mastodon`, `telegram`, `bluesky` and `apple_news`: one address each, or empty. A typed address is the
 *   same for every language. Empty means "use the account saved in Outlets for the page's language" for
 *   the three services that have an outlet, and "no link" for Apple News. Each address is checked against
 *   its service (`Follow\Address`) and saved in the form the link will have, so `@name` becomes
 *   `https://t.me/name` and a bare Bluesky handle becomes its `bsky.app` profile address. A host that is
 *   not the service's is rejected, and the error names the field.
 * - `append`: add the Follow block after the text of every article of the site's post types. Off by default.
 *   An article that already holds the block or the shortcode does not get it twice.
 * - `follow_url` and `follow_label`: the button the block added to the end of every article carries. A
 *   block placed by hand has its own.
 *
 * The block itself is `Modules\FollowBlockModule`. Nothing here is a secret.
 */
final class Follow extends Section {

	public const NAME = 'follow';

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array {
		$fields = array();

		foreach ( Address::services() as $service ) {
			$fields[ $service ] = array(
				'type'         => 'string',
				'format'       => 'url',
				'schemes'      => array( 'https' ),
				'label'        => $this->label_of( $service ),
				'row_label'    => $this->label_of( $service ),
				'control'      => 'text',
				'default'      => '',
				'max'          => 300,
				'width'        => 480,
				'dir'          => 'ltr',
				'autocomplete' => 'off',
				'placeholder'  => $this->placeholder_of( $service ),
			);
		}

		$fields['append'] = array(
			'type'      => 'bool',
			'label'     => __( 'Add to the end of every article', 'estasoft-news-distribution' ),
			'row_label' => __( 'Every article', 'estasoft-news-distribution' ),
			'default'   => false,
			'control'   => 'checkbox',
			'title'     => __( 'Add to the end of every article', 'estasoft-news-distribution' ),
			'help'      => __( 'Applies to the whole site. Off by default. A block you place by hand in an article is not added twice.', 'estasoft-news-distribution' ),
		);

		$fields['follow_url'] = array(
			'type'        => 'string',
			'format'      => 'url',
			'schemes'     => array( 'https', 'http' ),
			'label'       => __( 'Follow button address', 'estasoft-news-distribution' ),
			'default'     => '',
			'control'     => 'text',
			'max'         => 500,
			'width'       => 480,
			'dir'         => 'ltr',
			'placeholder' => 'https://',
			'help'        => __( 'The button the block at the end of every article shows first, such as a link to your page on a platform. Leave it empty to show no button. A block you place by hand has its own address.', 'estasoft-news-distribution' ),
		);

		$fields['follow_label'] = array(
			'type'    => 'string',
			'label'   => __( 'Follow button text', 'estasoft-news-distribution' ),
			'default' => '',
			'max'     => 60,
			'width'   => 280,
			'help'    => __( 'Leave it empty to use “Follow us”.', 'estasoft-news-distribution' ),
		);

		return $fields;
	}

	public function title(): string {
		return __( 'Follow links', 'estasoft-news-distribution' );
	}

	public function description(): string {
		return __( 'Where the Follow block sends visitors, and whether it is added to the end of every article.', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return 'content';
	}

	public function order(): int {
		return 25;
	}

	/**
	 * A link on the page changes the page and not a feed, so no feed is rebuilt for it.
	 *
	 * @return bool
	 */
	public function affects_output(): bool {
		return false;
	}

	/**
	 * Turns what was typed into the address each link will have, before it is checked.
	 *
	 * @param array<string, mixed> $input Unslashed input as submitted.
	 * @return array<string, mixed>
	 */
	public function prepare_input( array $input ): array {
		foreach ( Address::services() as $service ) {
			if ( isset( $input[ $service ] ) && is_string( $input[ $service ] ) ) {
				$input[ $service ] = Address::normalise( $service, $input[ $service ] );
			}
		}

		return $input;
	}

	/**
	 * Refuses an address that does not belong to its service, and names the field.
	 *
	 * @param array<string, mixed> $values Cleaned values.
	 * @return array<string, string>
	 */
	public function cross_check( array $values ): array {
		$errors = array();

		foreach ( Address::services() as $service ) {
			$typed = isset( $values[ $service ] ) && is_string( $values[ $service ] ) ? trim( $values[ $service ] ) : '';

			if ( '' !== $typed && null === Address::canonical( $service, $typed ) ) {
				$errors[ $service ] = Address::expects( $service );
			}
		}

		return $errors;
	}

	/**
	 * @return array{cards: array<int, array<string, mixed>>, notes: array<int, array<string, string>>, cannot: string[]}
	 */
	public function layout(): array {
		$accounts = new Accounts( Plugin::instance() );
		$rows     = array();

		foreach ( Address::services() as $service ) {
			$rows[] = array(
				'field'  => $service,
				'nested' => array( 'note' => $accounts->default_note( $service ) ),
			);
		}

		return array(
			'cards'  => array(
				array(
					'id'          => 'links',
					'title'       => __( 'Where to follow you', 'estasoft-news-distribution' ),
					'description' => __( 'Each link in the Follow block shows only when it has an address, and a link opens the service’s own page. An address you type here is used for every language.', 'estasoft-news-distribution' ),
					'rows'        => $rows,
				),
				array(
					'id'          => 'placement',
					'title'       => __( 'Placement', 'estasoft-news-distribution' ),
					'description' => __( 'Put the Follow block after the text of every article, without opening each one in the editor. It shows the links above and the RSS feed, and a button when you add an address below.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array( 'field' => 'append' ),
						array( 'field' => 'follow_url' ),
						array( 'field' => 'follow_label' ),
					),
				),
			),
			'notes'  => array(),
			'cannot' => array(
				__( 'Make a platform accept your account or show your articles. A link opens the platform’s own page, and the reader decides.', 'estasoft-news-distribution' ),
				__( 'Find an address for you. It uses what you type here, or the account saved in Outlets. It never reads a token or a password.', 'estasoft-news-distribution' ),
				__( 'Add the platforms’ own button artwork. Every link is a plain button or a text link.', 'estasoft-news-distribution' ),
			),
		);
	}

	/**
	 * The name of a service's field.
	 *
	 * @param string $service One of the `Address` constants.
	 * @return string
	 */
	private function label_of( string $service ): string {
		switch ( $service ) {
			case Address::MASTODON:
				return __( 'Mastodon address', 'estasoft-news-distribution' );
			case Address::TELEGRAM:
				return __( 'Telegram address', 'estasoft-news-distribution' );
			case Address::BLUESKY:
				return __( 'Bluesky address', 'estasoft-news-distribution' );
			case Address::APPLE_NEWS:
				return __( 'Apple News address', 'estasoft-news-distribution' );
		}

		return '';
	}

	/**
	 * An example of a service's address, which is the same in every language.
	 *
	 * @param string $service One of the `Address` constants.
	 * @return string
	 */
	private function placeholder_of( string $service ): string {
		switch ( $service ) {
			case Address::MASTODON:
				return 'https://mastodon.social/@name';
			case Address::TELEGRAM:
				return 'https://t.me/name';
			case Address::BLUESKY:
				return 'https://bsky.app/profile/name.bsky.social';
			case Address::APPLE_NEWS:
				return 'https://apple.news/';
		}

		return '';
	}
}
