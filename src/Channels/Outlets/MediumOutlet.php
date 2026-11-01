<?php
/**
 * Medium: a list of stories to import by hand.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\Outlets;

use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\ContentReader;
use Estasoft\NewsDistribution\Core\Event;

defined( 'ABSPATH' ) || exit;

/**
 * Medium will not issue new integration tokens and allows no new integrations, so
 * News Distribution cannot post there. This outlet prepares a list instead: the
 * stories of the last seven days, newest first, each with a link that opens
 * Medium's import page. Medium's import tool applies a canonical link back to the
 * original, which search engines can use to credit it.
 *
 * It never says a story was posted. It cannot see whether a person imported one;
 * "Mark as imported" only changes this list.
 */
final class MediumOutlet extends AbstractOutlet {

	/**
	 * Medium's import page. Its address could not be confirmed here, so it is a constant that is easy to change.
	 */
	public const IMPORT_URL = 'https://medium.com/p/import';

	/**
	 * The most stories listed at once.
	 */
	private const MAX_LISTED = 50;

	/**
	 * Lists read earlier in this request, by the languages they were read for.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $listed = array();

	public function id(): string {
		return 'medium';
	}

	public function label(): string {
		return __( 'Medium', 'estasoft-news-distribution' );
	}

	/**
	 * Settings: only the switch. There is nothing to connect.
	 *
	 * @param string[] $languages Language codes the site publishes in.
	 * @return array<string, array<string, mixed>>
	 */
	public function fields( array $languages ): array {
		return array(
			'medium_on' => array(
				'type'    => 'bool',
				'label'   => __( 'List stories to import into Medium', 'estasoft-news-distribution' ),
				'default' => false,
			),
		);
	}

	public function is_on( array $settings ): bool {
		return ! empty( $settings['medium_on'] );
	}

	/**
	 * One target, which is the list itself.
	 *
	 * @param array<string, mixed> $settings  The channel's settings.
	 * @param string[]             $languages Language codes the site publishes in.
	 * @return Target[]
	 */
	public function targets( array $settings, array $languages ): array {
		return array( new Target( $this->id(), 'import', $this->label() ) );
	}

	/**
	 * Medium hears about new articles, and about removals so it can say a story needs removing by hand.
	 *
	 * @param Event $event The event.
	 * @return bool
	 */
	public function carries( Event $event ): bool {
		return Event::UPDATE !== $event->type();
	}

	public function send( Target $target, Event $event, ?Article $article, array $settings ): Outcome {
		if ( $event->removes_content() ) {
			if ( $this->state->imported( $event->post_id() ) > 0 ) {
				return Outcome::skipped( $target, __( 'This story was imported into Medium by hand. News Distribution cannot remove it there, so remove it in Medium.', 'estasoft-news-distribution' ) );
			}

			return Outcome::skipped( $target, __( 'The story was not marked as imported into Medium, so there is nothing to remove there.', 'estasoft-news-distribution' ) );
		}

		return Outcome::listed( $target, __( 'Added to the Medium import list. Medium stories are imported by hand, so nothing was posted.', 'estasoft-news-distribution' ) );
	}

	public function test( Target $target, array $settings, bool $send ): Outcome {
		return Outcome::skipped( $target, __( 'Medium has no test: News Distribution has no connection to Medium.', 'estasoft-news-distribution' ) );
	}

	/**
	 * The stories to import, newest first.
	 *
	 * Each row says when the story is available. A story waits for the index-first delay, so the
	 * original is crawled before a copy appears elsewhere. A story the person marked as imported
	 * stays on the list, marked.
	 *
	 * @param string[] $languages Language codes the channel carries.
	 * @return array{total: int, rows: array<int, array{id: int, title: string, url: string, language: string, available_ts: int, state: string, imported_ts: int}>}
	 */
	public function stories( array $languages ): array {
		$cache_key = implode( ',', array_map( 'strval', $languages ) );

		if ( isset( $this->listed[ $cache_key ] ) ) {
			return $this->listed[ $cache_key ];
		}

		$reader = new ContentReader( $this->context->languages, $this->context->settings );
		$window = $this->limit( 'medium_window_days', 7 ) * DAY_IN_SECONDS;
		$delay  = $this->context->settings->index_first_delay_seconds();
		$rows   = array();

		foreach ( $languages as $language ) {
			foreach ( $reader->recent( (string) $language, $window, self::MAX_LISTED ) as $article ) {
				if ( '' === PostText::headline( $article ) || '' === $article->permalink() ) {
					continue;
				}

				$available = $article->published_ts() + $delay;
				$imported  = $this->state->imported( $article->id() );
				$state     = 'pending';

				if ( $imported > 0 ) {
					$state = 'imported';
				} elseif ( $available > Clock::now() ) {
					$state = 'waiting';
				}

				$rows[ $article->id() ] = array(
					'id'           => $article->id(),
					'title'        => PostText::headline( $article ),
					'url'          => $article->permalink(),
					'language'     => $article->language(),
					'available_ts' => $available,
					'state'        => $state,
					'imported_ts'  => $imported,
				);
			}
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return $b['available_ts'] <=> $a['available_ts'];
			}
		);

		$this->listed[ $cache_key ] = array(
			'total' => count( $rows ),
			'rows'  => array_slice( $rows, 0, self::MAX_LISTED ),
		);

		return $this->listed[ $cache_key ];
	}

	/**
	 * Forgets the lists read earlier in this request, after a story was marked or a post changed.
	 *
	 * @return void
	 */
	public function forget_stories(): void {
		$this->listed = array();
	}

	/**
	 * How many stories wait for a person to import them: available now and not marked.
	 *
	 * @param string[] $languages Language codes the channel carries.
	 * @return int
	 */
	public function waiting_count( array $languages ): int {
		$count = 0;

		foreach ( $this->stories( $languages )['rows'] as $row ) {
			if ( 'pending' === $row['state'] ) {
				++$count;
			}
		}

		return $count;
	}
}
