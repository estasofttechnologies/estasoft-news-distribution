<?php
/**
 * Where the Follow block sends a visitor for Mastodon, Telegram, Bluesky and Apple News.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Follow;

use Estasoft\NewsDistribution\Channels\Outlets\AbstractOutlet;
use Estasoft\NewsDistribution\Channels\Outlets\BlueskyOutlet;
use Estasoft\NewsDistribution\Channels\Outlets\OutletsChannel;
use Estasoft\NewsDistribution\Channels\Outlets\TelegramOutlet;
use Estasoft\NewsDistribution\Core\Language\Catalog;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Answers one question: for this service and this language, what address does the link have?
 *
 * 1. The address the owner typed in Settings, Follow links, if there is one. It is the same for every
 *    language.
 * 2. Otherwise the public profile of the account saved in Outlets for that language, when that outlet
 *    is switched on: the Telegram channel name, the Bluesky handle, and the Mastodon handle the server
 *    reported when the account was tested. Apple News has no outlet, so it has only the typed address.
 * 3. Otherwise nothing, and the link is left out.
 *
 * Only public names are read. This class never touches `Core\Secrets` (tokens and app passwords stay
 * where the outlets keep them), and it does not call `OutletInterface::is_on()` or `targets()`, which
 * look at the secrets. An outlet counts as on when its own switch is on.
 */
final class Accounts {

	private Plugin $plugin;

	/**
	 * Creates the reader.
	 *
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * The languages the site publishes in.
	 *
	 * @return string[]
	 */
	public function languages(): array {
		return array_values( array_unique( array_map( 'strval', $this->plugin->languages()->languages() ) ) );
	}

	/**
	 * The address the owner typed for a service, already cleaned. Empty when none was typed.
	 *
	 * @param string $service One of the `Address` constants.
	 * @return string
	 */
	public function typed( string $service ): string {
		$typed = $this->plugin->settings()->get( 'follow.' . $service, '' );

		return is_string( $typed ) ? (string) Address::canonical( $service, $typed ) : '';
	}

	/**
	 * The address a link has for a language.
	 *
	 * @param string $service  One of the `Address` constants.
	 * @param string $language Language code of the page.
	 * @return string An address, or an empty string when the link is left out.
	 */
	public function address( string $service, string $language ): string {
		$typed = $this->typed( $service );

		return '' !== $typed ? $typed : $this->outlet_address( $service, $language );
	}

	/**
	 * The public profile of the account an outlet holds for a language.
	 *
	 * @param string $service  One of the `Address` constants.
	 * @param string $language Language code.
	 * @return string An address, or an empty string when the outlet is off, has no account for the language, or the account has no public address (a private Telegram channel).
	 */
	public function outlet_address( string $service, string $language ): string {
		$channel = $this->plugin->registry()->get( OutletsChannel::ID );

		if ( ! $channel instanceof OutletsChannel || Address::APPLE_NEWS === $service ) {
			return '';
		}

		$settings = $channel->settings();
		$suffix   = AbstractOutlet::suffix( $language );
		$text     = '';

		switch ( $service ) {
			case Address::TELEGRAM:
				if ( ! empty( $settings['telegram_on'] ) ) {
					$text = TelegramOutlet::normalise_chat( (string) ( $settings[ 'telegram_chat_' . $suffix ] ?? '' ) );
				}
				break;

			case Address::BLUESKY:
				if ( ! empty( $settings['bluesky_on'] ) ) {
					$text = BlueskyOutlet::normalise_handle( (string) ( $settings[ 'bluesky_handle_' . $suffix ] ?? '' ) );
				}
				break;

			case Address::MASTODON:
				if ( ! empty( $settings['mastodon_on'] ) ) {
					$text = $channel->state()->handle( 'mastodon:' . $language );
				}
				break;
		}

		return (string) Address::canonical( $service, $text );
	}

	/**
	 * The language codes that have a link for a service, and the ones that do not.
	 *
	 * @param string $service One of the `Address` constants.
	 * @return array{with: string[], without: string[], typed: bool}
	 */
	public function coverage( string $service ): array {
		$typed   = '' !== $this->typed( $service );
		$with    = array();
		$without = array();

		foreach ( $this->languages() as $language ) {
			if ( $typed || '' !== $this->outlet_address( $service, $language ) ) {
				$with[] = $language;
			} else {
				$without[] = $language;
			}
		}

		return array(
			'with'    => $with,
			'without' => $without,
			'typed'   => $typed,
		);
	}

	/**
	 * Whether any language has a link for a service.
	 *
	 * @param string $service One of the `Address` constants.
	 * @return bool
	 */
	public function is_available( string $service ): bool {
		return array() !== $this->coverage( $service )['with'];
	}

	/**
	 * The line under a service's switch in the block's controls: what the link will do on this site.
	 *
	 * @param string $service One of the `Address` constants.
	 * @return string Plain text.
	 */
	public function note( string $service ): string {
		$coverage = $this->coverage( $service );
		$count    = count( $coverage['with'] );

		if ( 0 === $count ) {
			return Address::APPLE_NEWS === $service
				? __( 'Add the address of your publication in Settings, Follow links first', 'estasoft-news-distribution' )
				: __( 'Not set up in Outlets', 'estasoft-news-distribution' );
		}

		if ( $coverage['typed'] ) {
			return __( 'One address, set in Follow links, for every language', 'estasoft-news-distribution' );
		}

		if ( array() === $coverage['without'] ) {
			return sprintf(
				/* translators: %d: the number of accounts, one for each language the site publishes in. */
				_n( '%d account, one per language', '%d accounts, one per language', $count, 'estasoft-news-distribution' ),
				$count
			);
		}

		return sprintf(
			/* translators: 1: the number of accounts, 2: the languages that have one, such as "English and Arabic", 3: the languages that have none, such as "Kurdish". */
			_n( '%1$d account: %2$s. %3$s pages show no link.', '%1$d accounts: %2$s. %3$s pages show no link.', $count, 'estasoft-news-distribution' ),
			$count,
			$this->names( $coverage['with'] ),
			$this->names( $coverage['without'] )
		);
	}

	/**
	 * The line under a service's field in Settings: what is used when the field is left empty.
	 *
	 * @param string $service One of the `Address` constants.
	 * @return string Plain text.
	 */
	public function default_note( string $service ): string {
		if ( Address::APPLE_NEWS === $service ) {
			return __( 'News Distribution cannot find this address for you. Copy it from your publication on Apple News. The link shows only when an address is here.', 'estasoft-news-distribution' );
		}

		$found = array();

		foreach ( $this->languages() as $language ) {
			$address = $this->outlet_address( $service, $language );

			if ( '' !== $address ) {
				$found[] = Catalog::name( $language ) . ' ' . $address;
			}
		}

		if ( array() === $found ) {
			return __( 'Leave it empty to use the account saved in Outlets. No account is saved there for this service yet, so no link shows.', 'estasoft-news-distribution' );
		}

		return sprintf(
			/* translators: %s: the accounts saved in Outlets, one for each language, such as "English https://t.me/example, Arabic https://t.me/example_ar". */
			__( 'Leave it empty to use the accounts saved in Outlets: %s.', 'estasoft-news-distribution' ),
			implode( ', ', $found )
		);
	}

	/**
	 * Language names as a list in words, such as "English and Arabic".
	 *
	 * @param string[] $codes Language codes.
	 * @return string
	 */
	private function names( array $codes ): string {
		return wp_sprintf( '%l', array_map( array( Catalog::class, 'name' ), $codes ) );
	}
}
