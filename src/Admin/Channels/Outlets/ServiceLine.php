<?php
/**
 * One sentence that says what a service does with the accounts it has.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Channels\Outlets;

use Estasoft\NewsDistribution\Channels\Outlets\OutletInterface;
use Estasoft\NewsDistribution\Channels\Outlets\OutletsChannel;
use Estasoft\NewsDistribution\Core\Language\Catalog;
use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * "Mastodon turns on with 3 accounts. Bluesky turns on with 2 of 3 accounts,
 * and Kurdish posts are skipped." The same sentences, in the present tense,
 * say what a service does once it is on. Every number is a numbered
 * placeholder and every plural goes through _n(), so a translator can reorder
 * and inflect them.
 */
final class ServiceLine {

	/**
	 * The sentence for one service.
	 *
	 * @param OutletsChannel  $channel The channel.
	 * @param OutletInterface $outlet  The service.
	 * @return string
	 */
	public static function sentence( OutletsChannel $channel, OutletInterface $outlet ): string {
		$settings  = $channel->settings();
		$languages = $channel->site_languages();
		$have      = array();

		foreach ( $outlet->targets( $settings, $languages ) as $target ) {
			if ( $target->is_complete() ) {
				$have[ $target->language() ] = true;
			}
		}

		$missing = array();

		foreach ( $languages as $code ) {
			if ( ! isset( $have[ $code ] ) ) {
				$missing[] = Catalog::name( $code );
			}
		}

		$accounts = count( $have );
		$total    = count( $languages );
		$name     = $outlet->label();
		$is_on    = Status::NOT_SET_UP !== $channel->service_status( $outlet->id() )['code'];

		if ( 0 === $accounts ) {
			return sprintf(
				/* translators: %s: a service name such as Mastodon. */
				__( '%s needs an account before it can turn on.', 'estasoft-news-distribution' ),
				$name
			);
		}

		if ( array() === $missing ) {
			return $is_on
				? sprintf(
					/* translators: 1: a service name such as Mastodon, 2: how many accounts. */
					_n( '%1$s is on with %2$d account.', '%1$s is on with %2$d accounts.', $accounts, 'estasoft-news-distribution' ),
					$name,
					$accounts
				)
				: sprintf(
					/* translators: 1: a service name such as Mastodon, 2: how many accounts. */
					_n( '%1$s turns on with %2$d account.', '%1$s turns on with %2$d accounts.', $accounts, 'estasoft-news-distribution' ),
					$name,
					$accounts
				);
		}

		$list = wp_sprintf( '%l', $missing );

		return $is_on
			? sprintf(
				/* translators: 1: a service name such as Bluesky, 2: accounts it has, 3: languages the site publishes in, 4: names of the languages with no account, such as Kurdish. */
				_n( '%1$s is on with %2$d of %3$d account, and %4$s posts are skipped.', '%1$s is on with %2$d of %3$d accounts, and %4$s posts are skipped.', $total, 'estasoft-news-distribution' ),
				$name,
				$accounts,
				$total,
				$list
			)
			: sprintf(
				/* translators: 1: a service name such as Bluesky, 2: accounts it has, 3: languages the site publishes in, 4: names of the languages with no account, such as Kurdish. */
				_n( '%1$s turns on with %2$d of %3$d account, and %4$s posts are skipped.', '%1$s turns on with %2$d of %3$d accounts, and %4$s posts are skipped.', $total, 'estasoft-news-distribution' ),
				$name,
				$accounts,
				$total,
				$list
			);
	}
}
