<?php
/**
 * Holds every registered channel.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels;

defined( 'ABSPATH' ) || exit;

/**
 * The built-in channels and other plugins register channels here; the rest of the plugin only asks it.
 */
final class ChannelRegistry {

	/**
	 * @var array<string, ChannelInterface>
	 */
	private array $channels = array();

	/**
	 * Adds a channel. A second channel with the same ID replaces the first.
	 *
	 * @param ChannelInterface $channel The channel.
	 * @return void
	 */
	public function register( ChannelInterface $channel ): void {
		$this->channels[ $channel->id() ] = $channel;
	}

	/**
	 * Puts back an earlier list of channels. Tests use it so a channel one test adds
	 * does not show up in the next.
	 *
	 * @param array<string, ChannelInterface> $channels The list to keep.
	 * @return void
	 */
	public function replace( array $channels ): void {
		$this->channels = $channels;
	}

	public function get( string $id ): ?ChannelInterface {
		return $this->channels[ $id ] ?? null;
	}

	/**
	 * @return array<string, ChannelInterface>
	 */
	public function all(): array {
		return $this->channels;
	}

	/**
	 * @return array<string, ChannelInterface>
	 */
	public function enabled(): array {
		return array_filter(
			$this->channels,
			static function ( ChannelInterface $channel ): bool {
				return $channel->is_enabled();
			}
		);
	}

	/**
	 * @return array<string, ChannelInterface>
	 */
	public function pulled(): array {
		return $this->by_mode( ChannelInterface::MODE_PULLED );
	}

	/**
	 * @return array<string, ChannelInterface>
	 */
	public function pushed(): array {
		return $this->by_mode( ChannelInterface::MODE_PUSHED );
	}

	/**
	 * The channels that serve a feed slug, switched-on ones first.
	 *
	 * @param string $slug Feed slug from the address.
	 * @return ChannelInterface[]
	 */
	public function serving( string $slug ): array {
		$found = array_values(
			array_filter(
				$this->channels,
				static function ( ChannelInterface $channel ) use ( $slug ): bool {
					return $channel->serves( $slug );
				}
			)
		);

		usort(
			$found,
			static function ( ChannelInterface $a, ChannelInterface $b ): int {
				return (int) $b->is_enabled() <=> (int) $a->is_enabled();
			}
		);

		return $found;
	}

	/**
	 * @param string $mode MODE_PULLED or MODE_PUSHED.
	 * @return array<string, ChannelInterface>
	 */
	private function by_mode( string $mode ): array {
		return array_filter(
			$this->channels,
			static function ( ChannelInterface $channel ) use ( $mode ): bool {
				return $mode === $channel->mode();
			}
		);
	}
}
