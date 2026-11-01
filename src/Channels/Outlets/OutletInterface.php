<?php
/**
 * What every outlet does.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\Outlets;

use Estasoft\NewsDistribution\Channels\ValidationResult;
use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Event;

defined( 'ABSPATH' ) || exit;

/**
 * An outlet is one service the Outlets channel can post to: Telegram, Mastodon,
 * Bluesky, Medium (a list for a person to act on) or a signed webhook. The
 * channel owns the queue, the retries, the marks and the log; an outlet only
 * knows its own service.
 *
 * `$settings` is the channel's stored settings (see OutletsChannel::fields()).
 * It never holds a secret; an outlet reads its own secrets through the channel's
 * secret store.
 */
interface OutletInterface {

	/**
	 * Stable id: telegram, mastodon, bluesky, medium or webhook.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Name as the service writes it.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * The outlet's settings fields, in the settings framework's format.
	 *
	 * @param string[] $languages Language codes the site publishes in.
	 * @return array<string, array<string, mixed>>
	 */
	public function fields( array $languages ): array;

	/**
	 * Whether the owner turned this outlet on and something is ready to post to.
	 *
	 * @param array<string, mixed> $settings The channel's settings.
	 * @return bool
	 */
	public function is_on( array $settings ): bool;

	/**
	 * Every place the outlet could post to, complete or not.
	 *
	 * @param array<string, mixed> $settings  The channel's settings.
	 * @param string[]             $languages Language codes the site publishes in.
	 * @return Target[]
	 */
	public function targets( array $settings, array $languages ): array;

	/**
	 * Whether the outlet acts on this kind of event.
	 *
	 * @param Event $event The event.
	 * @return bool
	 */
	public function carries( Event $event ): bool;

	/**
	 * Checks one article against the outlet's rules. A finding that blocks means this outlet will not post the article.
	 *
	 * @param Article              $article  The article.
	 * @param array<string, mixed> $settings The channel's settings.
	 * @return ValidationResult
	 */
	public function check( Article $article, array $settings ): ValidationResult;

	/**
	 * Sends the event to one target. Only the queue calls this, or a person pressing a button.
	 *
	 * @param Target               $target   Where to post.
	 * @param Event                $event    The event.
	 * @param Article|null         $article  The article, or null when it was removed.
	 * @param array<string, mixed> $settings The channel's settings.
	 * @return Outcome
	 */
	public function send( Target $target, Event $event, ?Article $article, array $settings ): Outcome;

	/**
	 * A test a person started. With `$send` false it only checks the connection and posts nothing;
	 * with `$send` true it posts one short message that says it is a test.
	 *
	 * @param Target               $target   The target.
	 * @param array<string, mixed> $settings The channel's settings.
	 * @param bool                 $send     Whether to post a test message.
	 * @return Outcome
	 */
	public function test( Target $target, array $settings, bool $send ): Outcome;
}
