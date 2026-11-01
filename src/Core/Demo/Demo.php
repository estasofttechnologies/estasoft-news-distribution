<?php
/**
 * Demo mode: sample articles, nothing sent, and a way back.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Demo;

use Estasoft\NewsDistribution\Admin\Overview\OverviewData;
use Estasoft\NewsDistribution\Admin\Setup\ChannelChoices;
use Estasoft\NewsDistribution\Admin\Setup\Onboarding;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * "Try the demo" on the first-run screen, and "Exit demo" in the banner and in Settings, Environment.
 *
 * Starting the demo:
 *
 * 1. records what it is about to change, in the option `estasoft_nd_demo`, so exit can put it back: the value of
 *    `environment.demo`, the state of the first-run setup, and the channels it turns on;
 * 2. turns demo mode on (Settings, Environment). While it is on, no push, ping or alert leaves, the feeds and
 *    sitemaps that platforms pull answer "503, not sent: demo mode is on", and the sample articles are hidden from
 *    visitors (`Modules\DemoModule`, `Modules\DeliveryHoldsModule`);
 * 3. turns on the channels of "Start simple" and the open feed readers, if they were off. They only pull, and
 *    nothing is served while demo mode is on;
 * 4. makes the sample articles (`SampleArticles`), once: starting again makes only what is missing.
 *
 * Leaving the demo deletes every post, picture and category that carries the marker `_estasoft_nd_sample` (and
 * nothing else), turns demo mode off, turns off the channels the demo turned on, and puts the first-run state back.
 *
 * The option `estasoft_nd_demo`:
 *
 *     {
 *       started,    // Unix time the demo started, 0 when it did not start here
 *       previous,   // { demo: bool, onboarding: array|null }  what to put back
 *       channels,   // channel ids the demo turned on
 *       ids,        // IDs of the posts and pictures made
 *       terms,      // IDs of the categories made
 *       images,     // whether pictures could be drawn
 *       languages,  // the languages of the samples
 *       held        // true while the plugin is switched off and the samples are drafts
 *     }
 *
 * On a network every site has its own demo: the option, the settings and the posts are all per site.
 */
final class Demo {

	public const OPTION       = 'estasoft_nd_demo';
	public const META         = '_estasoft_nd_sample';
	public const META_LANG    = '_estasoft_nd_sample_language';
	public const META_LANG_BY = '_estasoft_nd_sample_language_by';

	private Plugin $plugin;

	/**
	 * Creates the service.
	 *
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * What is stored, with every key present.
	 *
	 * @return array{started: int, previous: array{demo: bool, onboarding: array<string, mixed>|null}, channels: string[], ids: int[], terms: int[], images: bool, languages: string[], held: bool}
	 */
	public function state(): array {
		$stored   = get_option( self::OPTION, array() );
		$stored   = is_array( $stored ) ? $stored : array();
		$previous = isset( $stored['previous'] ) && is_array( $stored['previous'] ) ? $stored['previous'] : array();

		return array(
			'started'   => (int) ( $stored['started'] ?? 0 ),
			'previous'  => array(
				'demo'       => ! empty( $previous['demo'] ),
				'onboarding' => isset( $previous['onboarding'] ) && is_array( $previous['onboarding'] ) ? $previous['onboarding'] : null,
			),
			'channels'  => array_values( array_map( 'strval', (array) ( $stored['channels'] ?? array() ) ) ),
			'ids'       => array_values( array_filter( array_map( 'intval', (array) ( $stored['ids'] ?? array() ) ) ) ),
			'terms'     => array_values( array_filter( array_map( 'intval', (array) ( $stored['terms'] ?? array() ) ) ) ),
			'images'    => ! array_key_exists( 'images', $stored ) || ! empty( $stored['images'] ),
			'languages' => array_values( array_map( 'strval', (array) ( $stored['languages'] ?? array() ) ) ),
			'held'      => ! empty( $stored['held'] ),
		);
	}

	/**
	 * Whether demo mode is on (Settings, Environment).
	 *
	 * @return bool
	 */
	public function is_on(): bool {
		return (bool) $this->plugin->settings()->get( 'environment.demo', false );
	}

	/**
	 * Whether this site has sample articles the demo made.
	 *
	 * @return bool
	 */
	public function has_samples(): bool {
		return array() !== $this->state()['ids'];
	}

	/**
	 * The IDs of the posts and pictures that are hidden from visitors.
	 *
	 * @return int[]
	 */
	public function hidden_ids(): array {
		return $this->state()['ids'];
	}

	/**
	 * Whether the samples could be given pictures.
	 *
	 * @return bool
	 */
	public function has_pictures(): bool {
		return $this->state()['images'];
	}

	/**
	 * How many samples would be removed, counted from the markers in the database.
	 *
	 * @return array{articles: int, images: int}
	 */
	public function counts(): array {
		return ( new SampleArticles( $this->plugin ) )->counts();
	}

	/**
	 * Starts the demo, or makes what is missing when it has started already.
	 *
	 * @param int $author The person who started it; the samples belong to them.
	 * @return array{created: int, articles: int, images: int, pictures: bool}
	 */
	public function start( int $author ): array {
		$state = $this->state();

		if ( 0 === $state['started'] ) {
			$stored = get_option( Onboarding::OPTION, null );

			$state['started']  = Clock::now();
			$state['previous'] = array(
				'demo'       => $this->is_on(),
				'onboarding' => is_array( $stored ) ? $stored : null,
			);
		}

		$state['images'] = SampleImages::available();

		$this->save( $state );

		$this->plugin->settings()->save_section( 'environment', array( 'demo' => true ) );

		$state['channels'] = array_values( array_unique( array_merge( $state['channels'], $this->turn_on_channels() ) ) );

		$this->save( $state );

		$made = ( new SampleArticles( $this->plugin ) )->create( $author );

		$state['ids']       = array_values( array_unique( array_merge( $state['ids'], $made['ids'] ) ) );
		$state['terms']     = array_values( array_unique( array_merge( $state['terms'], $made['terms'] ) ) );
		$state['languages'] = array_values( array_unique( array_merge( $state['languages'], $made['languages'] ) ) );

		$this->save( $state );

		Onboarding::save(
			array(
				'status' => Onboarding::STATUS_DEMO,
				'step'   => Onboarding::START,
			)
		);

		$this->refresh();

		$counts = $this->counts();

		return array(
			'created'  => $made['created'],
			'articles' => $counts['articles'],
			'images'   => $counts['images'],
			'pictures' => $state['images'],
		);
	}

	/**
	 * Leaves the demo: removes the samples and puts back what the demo changed.
	 *
	 * The posts go first, while demo mode is still on, so nothing about their removal can be sent.
	 *
	 * @return array{articles: int, images: int, terms: int}
	 */
	public function leave(): array {
		$state   = $this->state();
		$removed = ( new SampleArticles( $this->plugin ) )->remove();

		$this->plugin->settings()->save_section( 'environment', array( 'demo' => false ) );

		foreach ( $state['channels'] as $id ) {
			if ( $this->plugin->channel_settings()->is_enabled( $id ) ) {
				$this->plugin->channel_settings()->set_enabled( $id, false );
			}
		}

		if ( $state['started'] > 0 ) {
			if ( null === $state['previous']['onboarding'] ) {
				delete_option( Onboarding::OPTION );
			} else {
				update_option( Onboarding::OPTION, $state['previous']['onboarding'], false );
			}
		}

		delete_option( self::OPTION );

		$this->refresh();

		return $removed;
	}

	/**
	 * Makes the samples drafts while the plugin is switched off.
	 *
	 * The plugin is what hides the samples from visitors, so with the plugin off they would show on the front page and in
	 * WordPress's own feeds. Drafts are not shown to anyone, and nothing is deleted. `resume()` puts them back.
	 *
	 * @return void
	 */
	public function hold_back(): void {
		$state = $this->state();

		if ( array() === $state['ids'] ) {
			return;
		}

		( new SampleArticles( $this->plugin ) )->set_published( false );

		$state['held'] = true;

		$this->save( $state );
		$this->refresh();
	}

	/**
	 * Publishes the samples again when the plugin is switched back on.
	 *
	 * @return void
	 */
	public function resume(): void {
		$state = $this->state();

		if ( ! $state['held'] ) {
			return;
		}

		( new SampleArticles( $this->plugin ) )->set_published( true );

		$state['held'] = false;

		$this->save( $state );
		$this->refresh();
	}

	/**
	 * Turns on the channels of "Start simple" and the open feed readers, if they are off.
	 *
	 * @return string[] The ids it turned on, so exit can turn them off again.
	 */
	private function turn_on_channels(): array {
		$choices  = new ChannelChoices( $this->plugin );
		$settings = $this->plugin->channel_settings();
		$turned   = array();

		foreach ( array_unique( array_merge( $choices->for_preset( 'simple' ), array( 'feed-readers' ) ) ) as $id ) {
			if ( null === $this->plugin->registry()->get( $id ) || $settings->is_enabled( $id ) ) {
				continue;
			}

			$settings->set_enabled( $id, true );
			$turned[] = $id;
		}

		return $turned;
	}

	/**
	 * Stores the state.
	 *
	 * @param array<string, mixed> $state The state.
	 * @return void
	 */
	private function save( array $state ): void {
		update_option( self::OPTION, $state, true );
	}

	/**
	 * Drops what was built or read from the samples: built feeds and the Overview's copy.
	 *
	 * @return void
	 */
	private function refresh(): void {
		$this->plugin->cache()->flush();
		OverviewData::forget();
	}
}
