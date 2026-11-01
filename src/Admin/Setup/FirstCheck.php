<?php
/**
 * The first check of the setup: the latest articles, read the same way the Article check reads them.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Setup;

use Estasoft\NewsDistribution\Checks\ArticleChecker;
use Estasoft\NewsDistribution\Core\ArticleService;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Runs `Checks\ArticleChecker` on the newest published articles and counts what it found. The
 * result is stored in the setup state so the last step can show it again without running anything.
 * Nothing is sent to any platform and no other server is called; the checks read the site.
 *
 * Result:
 *
 *     {
 *       at,       // Unix time
 *       checked,  // how many articles
 *       ready,    // articles with no finding that blocks or warns
 *       problems  // [ { code, articles, severity, message } ] most common first; blocks and warnings only
 *       no_image  // articles that have no featured image, whatever the severity
 *     }
 */
final class FirstCheck {

	public const LIMIT = 18;

	private Plugin $plugin;

	/**
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Checks the newest published articles and stores each result, as the Article check does.
	 *
	 * @param int $limit How many articles.
	 * @return array<string, mixed>
	 */
	public function run( int $limit = self::LIMIT ): array {
		$checker = new ArticleChecker( $this->plugin );
		$page    = $this->plugin->make( ArticleService::class )->list(
			array(
				'status'   => 'publish',
				'per_page' => max( 1, $limit ),
			)
		);

		$checked  = 0;
		$ready    = 0;
		$no_image = 0;
		$found    = array();

		foreach ( $page['items'] as $item ) {
			$report = $checker->check( (int) $item['id'] );

			if ( null === $report ) {
				continue;
			}

			++$checked;

			if ( $report->is_clean() ) {
				++$ready;
			}

			$codes = array();

			foreach ( $report->findings() as $finding ) {
				$code = (string) $finding['code'];

				if ( 'image.missing' === $code ) {
					$codes[ $code ] = true;
					++$no_image;
				}

				if ( ! in_array( (string) $finding['severity'], array( Status::BLOCK, Status::WARN ), true ) ) {
					continue;
				}

				$codes[ $code ] = true;

				if ( ! isset( $found[ $code ] ) ) {
					$found[ $code ] = array(
						'code'     => $code,
						'articles' => 0,
						'severity' => (string) $finding['severity'],
						'message'  => (string) $finding['message'],
					);
				}
			}

			foreach ( array_keys( $codes ) as $code ) {
				if ( isset( $found[ $code ] ) ) {
					++$found[ $code ]['articles'];
				}
			}
		}

		$problems = array_values( $found );

		usort(
			$problems,
			static function ( array $a, array $b ): int {
				return $b['articles'] <=> $a['articles'];
			}
		);

		return array(
			'at'       => Clock::now(),
			'checked'  => $checked,
			'ready'    => $ready,
			'no_image' => $no_image,
			'problems' => $problems,
		);
	}
}
