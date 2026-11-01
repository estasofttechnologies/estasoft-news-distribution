<?php
/**
 * The services a channel may use.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels;

use Estasoft\NewsDistribution\Core\Applications;
use Estasoft\NewsDistribution\Core\DeliveryLog;
use Estasoft\NewsDistribution\Core\FetchLog;
use Estasoft\NewsDistribution\Core\Language\LanguageResolverInterface;
use Estasoft\NewsDistribution\Core\Rules;
use Estasoft\NewsDistribution\Core\Secrets;
use Estasoft\NewsDistribution\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * A plain holder, so a channel never reaches for global state and a test can
 * build one from fakes.
 */
final class ChannelContext {

	public Settings $settings;
	public ChannelSettings $channels;
	public Secrets $secrets;
	public Applications $applications;
	public Rules $rules;
	public DeliveryLog $log;
	public FetchLog $fetches;
	public LanguageResolverInterface $languages;

	/**
	 * Creates the context.
	 *
	 * @param Settings                  $settings     Plugin settings.
	 * @param ChannelSettings           $channels     Per-channel settings.
	 * @param Secrets                   $secrets      Tokens and keys.
	 * @param Applications              $applications Application tracker.
	 * @param Rules                     $rules        Platform rule packs.
	 * @param DeliveryLog               $log          Delivery log.
	 * @param FetchLog                  $fetches      Fetch log.
	 * @param LanguageResolverInterface $languages Language lookups.
	 */
	public function __construct( Settings $settings, ChannelSettings $channels, Secrets $secrets, Applications $applications, Rules $rules, DeliveryLog $log, FetchLog $fetches, LanguageResolverInterface $languages ) {
		$this->settings     = $settings;
		$this->channels     = $channels;
		$this->secrets      = $secrets;
		$this->applications = $applications;
		$this->rules        = $rules;
		$this->log          = $log;
		$this->fetches      = $fetches;
		$this->languages    = $languages;
	}
}
