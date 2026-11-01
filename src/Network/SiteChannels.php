<?php
/**
 * The channels of the site that is current, built from that site's own settings.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Network;

use Estasoft\NewsDistribution\Channels\AbstractChannel;
use Estasoft\NewsDistribution\Channels\ChannelContext;
use Estasoft\NewsDistribution\Channels\ChannelSettings;
use Estasoft\NewsDistribution\Core\Applications;
use Estasoft\NewsDistribution\Core\DeliveryLog;
use Estasoft\NewsDistribution\Core\FetchLog;
use Estasoft\NewsDistribution\Core\Language\LanguageResolverFactory;
use Estasoft\NewsDistribution\Core\Rules;
use Estasoft\NewsDistribution\Core\Secrets;
use Estasoft\NewsDistribution\Core\Settings;
use Estasoft\NewsDistribution\Support\Discovery;

defined( 'ABSPATH' ) || exit;

/**
 * The running plugin's services were built for the site WordPress started on and remember what
 * they read, so after `switch_to_blog()` they still describe that site. This builds a fresh set
 * (settings, channel settings, applications, logs, language resolver) so that a channel's
 * `is_enabled()` and `health()` read the site that is current now. Only the channels that ship
 * with the plugin are built; a channel another plugin adds is not counted.
 */
final class SiteChannels {

	/**
	 * The built-in channels, ready to answer for the current site.
	 *
	 * @return array<string, AbstractChannel> Channel id => channel.
	 */
	public static function build(): array {
		$context = new ChannelContext(
			new Settings(),
			new ChannelSettings(),
			new Secrets(),
			new Applications(),
			new Rules(),
			new DeliveryLog(),
			new FetchLog(),
			LanguageResolverFactory::detect()
		);

		$channels = array();

		foreach ( Discovery::nested( 'Channels', 'Channel' ) as $class ) {
			if ( class_exists( $class ) && is_subclass_of( $class, AbstractChannel::class ) ) {
				$channel                    = new $class( $context );
				$channels[ $channel->id() ] = $channel;
			}
		}

		return $channels;
	}
}
