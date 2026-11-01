<?php
/**
 * Runs every check on one article and stores the result.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Checks;

use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\ArticleChoices;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Support\Discovery;

defined( 'ABSPATH' ) || exit;

/**
 * Get it with `Plugin::instance()->make( ArticleChecker::class )`.
 *
 * For each channel that is on and takes the article's language it runs
 * `Channel::validate()`; it also runs every `Checks\*Check` class once and adds
 * each finding to the channels it names (or to all). The result is a CheckReport,
 * stored in `_estasoft_nd_check`.
 *
 * It never calls another server and never changes the post.
 */
class ArticleChecker {

	public const META = '_estasoft_nd_check';

	/**
	 * Codes a channel may use for a finding the article checks also report, under the one stable code.
	 *
	 * @var array<string, string>
	 */
	private const ALIASES = array(
		'title.has_publication_name' => 'title.publication_name',
		'title.has_author'           => 'title.author',
		'title.has_date'             => 'title.date',
	);

	private Plugin $plugin;

	/**
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Runs the checks and stores the result.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $store   Whether to write `_estasoft_nd_check`.
	 * @return CheckReport|null Null when the post does not exist.
	 */
	public function check( int $post_id, bool $store = true ): ?CheckReport {
		$article = $this->plugin->reader()->find( $post_id );

		if ( null === $article ) {
			return null;
		}

		$report = $this->build( $article );

		if ( $store ) {
			update_post_meta( $post_id, self::META, $report->to_array() );
		}

		return $report;
	}

	/**
	 * The last stored result, or null when the article was never checked.
	 *
	 * @param int $post_id Post ID.
	 * @return CheckReport|null
	 */
	public function stored( int $post_id ): ?CheckReport {
		$data = get_post_meta( $post_id, self::META, true );

		return is_array( $data ) && ! empty( $data['checked'] ) ? new CheckReport( $data ) : null;
	}

	/**
	 * Runs the checks for an article already read, without storing.
	 *
	 * @param Article $article The article.
	 * @return CheckReport
	 */
	public function build( Article $article ): CheckReport {
		$general  = array();
		$channels = array();

		foreach ( $this->checks() as $check ) {
			foreach ( $check->run( $article ) as $finding ) {
				$general[] = $this->normalise( $finding );
			}
		}

		$choices = $this->plugin->make( ArticleChoices::class );

		foreach ( $this->plugin->registry()->enabled() as $channel ) {
			if ( ! $channel->accepts_language( $article->language() ) ) {
				continue;
			}

			// A channel the person switched off for this article does not carry it, so what it would say about the article
			// is not counted: not in the totals, and not by the gate. A sitemap that other channels share still carries the
			// article until all of them are off, so those stay.
			if ( $choices->is_left_out( $article->id(), $channel->id() ) && $choices->leaves_out( $article->id(), $channel ) ) {
				continue;
			}

			$findings = array();
			foreach ( $channel->validate( $article )->findings() as $finding ) {
				$findings[] = $this->normalise( $finding + array( 'channels' => array( $channel->id() ) ) );
			}

			$channels[ $channel->id() ] = array(
				'eligible' => true,
				'findings' => $findings,
			);
		}

		foreach ( $general as $finding ) {
			$scope = array() === $finding['channels'] ? array_keys( $channels ) : array_intersect( $finding['channels'], array_keys( $channels ) );

			foreach ( $scope as $channel_id ) {
				// A channel's own finding with the same code says it better: keep that one.
				if ( in_array( $finding['code'], array_column( $channels[ $channel_id ]['findings'], 'code' ), true ) ) {
					continue;
				}

				$channels[ $channel_id ]['findings'][] = $finding;
			}
		}

		$all = array();
		foreach ( $channels as $id => $channel ) {
			foreach ( $channel['findings'] as $finding ) {
				if ( Status::BLOCK === $finding['severity'] ) {
					$channels[ $id ]['eligible'] = false;
				}

				$key = $finding['code'] . '|' . $finding['severity'];

				if ( ! isset( $all[ $key ] ) ) {
					$all[ $key ] = $finding;
				}

				$all[ $key ]['channels'] = array_values( array_unique( array_merge( $all[ $key ]['channels'], array( $id ) ) ) );
			}
		}

		// A finding about the article that no channel is on for still shows, with no channel.
		foreach ( $general as $finding ) {
			$key = $finding['code'] . '|' . $finding['severity'];

			if ( ! isset( $all[ $key ] ) ) {
				$all[ $key ] = array_merge( $finding, array( 'channels' => array() ) );
			}
		}

		$rank   = array(
			Status::BLOCK => 0,
			Status::WARN  => 1,
			Status::INFO  => 2,
		);
		$all    = array_values( $all );
		$counts = array(
			Status::BLOCK => 0,
			Status::WARN  => 0,
			Status::INFO  => 0,
		);

		usort(
			$all,
			static function ( array $a, array $b ) use ( $rank ): int {
				return $rank[ $a['severity'] ] <=> $rank[ $b['severity'] ];
			}
		);

		foreach ( $all as $finding ) {
			++$counts[ $finding['severity'] ];
		}

		return new CheckReport(
			array(
				'post_id'  => $article->id(),
				'checked'  => Clock::now(),
				'language' => $article->language(),
				'channels' => $channels,
				'findings' => $all,
				'counts'   => $counts,
			)
		);
	}

	/**
	 * Every check, in run order.
	 *
	 * @return CheckInterface[]
	 */
	public function checks(): array {
		$checks = array();

		foreach ( Discovery::classes( 'Checks' ) as $class ) {
			if ( is_subclass_of( $class, CheckInterface::class ) && 'Check' === substr( $class, -5 ) ) {
				$checks[] = new $class( $this->plugin );
			}
		}

		usort(
			$checks,
			static function ( CheckInterface $a, CheckInterface $b ): int {
				return $a->order() <=> $b->order();
			}
		);

		return $checks;
	}

	/**
	 * Fills the optional keys of a finding.
	 *
	 * @param array<string, mixed> $finding A finding.
	 * @return array{code: string, severity: string, message: string, channels: string[], fix: string}
	 */
	private function normalise( array $finding ): array {
		$code = (string) ( $finding['code'] ?? '' );

		return array(
			'code'     => self::ALIASES[ $code ] ?? $code,
			'severity' => (string) ( $finding['severity'] ?? Status::INFO ),
			'message'  => (string) ( $finding['message'] ?? '' ),
			'channels' => array_values( array_map( 'strval', (array) ( $finding['channels'] ?? array() ) ) ),
			'fix'      => (string) ( $finding['fix'] ?? '' ),
		);
	}
}
