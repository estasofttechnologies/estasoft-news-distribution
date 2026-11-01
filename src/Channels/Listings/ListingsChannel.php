<?php
/**
 * Listings: Ground News and Particle.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\Listings;

use Estasoft\NewsDistribution\Channels\AbstractChannel;
use Estasoft\NewsDistribution\Channels\Group;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Ground News and Particle do not take a feed from publishers. A publisher asks
 * to be listed and the platform decides. This channel only prepares the details
 * and keeps the person's own record of where they stand (`Core\Applications`).
 * It sends nothing and cannot see any decision.
 */
final class ListingsChannel extends AbstractChannel {

	/**
	 * The platforms on this page, with what the page tells the person about each.
	 *
	 * @return array<string, array{name: string, email: string, form_url: string, form_label: string, help_url: string, help_label: string, site_url: string, site_label: string, no_timeframe: bool}>
	 */
	public static function platforms(): array {
		return array(
			'ground-news' => array(
				'name'         => 'Ground News',
				'email'        => 'feedback@ground.news',
				'form_url'     => 'https://ground.news/contact-us?form=source',
				'form_label'   => __( 'Open the submission form', 'estasoft-news-distribution' ),
				'help_url'     => 'https://help.ground.news/en/articles/1750273',
				'help_label'   => __( 'How Ground News adds sources', 'estasoft-news-distribution' ),
				'site_url'     => '',
				'site_label'   => '',
				'no_timeframe' => true,
			),
			'particle'    => array(
				'name'         => 'Particle',
				'email'        => '',
				'form_url'     => '',
				'form_label'   => '',
				'help_url'     => '',
				'help_label'   => '',
				'site_url'     => 'https://particle.news',
				'site_label'   => __( 'Particle’s website', 'estasoft-news-distribution' ),
				'no_timeframe' => false,
			),
		);
	}

	public function id(): string {
		return 'listings';
	}

	public function label(): string {
		return __( 'Listings', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return Group::LISTINGS;
	}

	public function order(): int {
		return 80;
	}

	public function mode(): string {
		return self::MODE_MANUAL;
	}

	public function feed_slug(): string {
		return '';
	}

	/**
	 * Listings have no switch: the page is always there, and nothing is sent.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return true;
	}

	/**
	 * A manual channel builds nothing.
	 *
	 * @param \Estasoft\NewsDistribution\Core\Article[] $articles Unused.
	 * @param string                                    $language Unused.
	 * @param int|null                                  $now      Unused.
	 * @return string
	 */
	public function build( array $articles, string $language, ?int $now = null ): string {
		unset( $articles, $language, $now );

		return '';
	}

	public function health(): string {
		return Status::HEALTHY;
	}

	/**
	 * One hub row per platform, with the status the person set by hand. A row has
	 * no switch, because nothing is turned on.
	 *
	 * @return array<int, array{key: string, name: string, summary: string, status: string, enabled: bool|null, action: string}>
	 */
	public function hub_rows(): array {
		$rows = array();

		foreach ( self::platforms() as $platform => $details ) {
			$rows[] = array(
				'key'     => $platform,
				'name'    => $details['name'],
				'summary' => $this->summary( $platform ),
				'status'  => $this->context->applications->display_status( $platform ),
				'enabled' => null,
				'action'  => 'open',
			);
		}

		return $rows;
	}

	/**
	 * The line under a platform's name on the hub.
	 *
	 * @param string $platform Platform id.
	 * @return string
	 */
	public function summary( string $platform ): string {
		$record  = $this->context->applications->get( $platform );
		$details = self::platforms()[ $platform ] ?? array( 'no_timeframe' => false );
		$day     = self::short_date( $record['applied_on'] );

		switch ( $record['status'] ) {
			case Status::REQUESTED:
			case Status::IN_REVIEW:
				$text = '' !== $day
					? sprintf(
						/* translators: %s: a short date such as "14 Sep". */
						__( 'Requested %s.', 'estasoft-news-distribution' ),
						$day
					)
					: __( 'Requested.', 'estasoft-news-distribution' );

				if ( ! empty( $details['no_timeframe'] ) ) {
					$text .= ' ' . sprintf(
						/* translators: %s: a platform name such as Ground News. */
						__( '%s gave no timeframe.', 'estasoft-news-distribution' ),
						(string) ( $details['name'] ?? '' )
					);
				}

				return $text;
			case Status::LISTED:
				return '' !== $day
					? sprintf(
						/* translators: %s: a short date such as "14 Sep". */
						__( 'Listed. You asked on %s.', 'estasoft-news-distribution' ),
						$day
					)
					: __( 'Listed.', 'estasoft-news-distribution' );
			case Status::DECLINED:
				return __( 'Declined. You can ask again later.', 'estasoft-news-distribution' );
			default:
				return __( 'Not requested yet.', 'estasoft-news-distribution' );
		}
	}

	/**
	 * A stored day (YYYY-MM-DD) as a short date such as "14 Sep", or an empty string.
	 *
	 * @param string $date Stored day.
	 * @return string
	 */
	public static function short_date( string $date ): string {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return '';
		}

		$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, Clock::zone() );

		return false === $parsed ? '' : Clock::format( $parsed->getTimestamp(), 'j M' );
	}
}
