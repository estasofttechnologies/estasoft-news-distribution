<?php
/**
 * What the plugin stores and which outside services it talks to.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Operations;

use Estasoft\NewsDistribution\Core\Alerts;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Connections;
use Estasoft\NewsDistribution\Core\Db\Schema;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * The one place that lists what News Distribution stores, the outside services
 * it can talk to and the text for the Privacy Policy Guide. The Privacy screen,
 * the suggested policy text and the readme's "External services" section all
 * read from here, so they cannot disagree.
 *
 * Get it with `Plugin::instance()->make( PrivacyInventory::class )`.
 *
 * A row of `stored()`:
 *
 *     array( 'id' => string, 'what' => string, 'why' => string, 'contains' => string, 'kept' => string )
 *
 * A row of `services()`:
 *
 *     array( 'id' => string, 'name' => string, 'kind' => string, 'state' => string, 'on' => bool, 'receives' => string )
 *
 * where `state` is a Core\Status code and `on` is true when the word shown is "On" instead of the code's own word.
 *
 * Another part of the plugin that stores something new, or talks to a new
 * service, adds its row with the filter `estasoft_nd_privacy_inventory`, which
 * receives `array( 'stored' => rows, 'services' => rows )`.
 */
final class PrivacyInventory {

	public const AI_MONTHS = array( 12, 6, 3, 1 );

	private Plugin $plugin;

	/**
	 * Creates the inventory.
	 *
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Whether this site has an AI log at all (WordPress 7.0 and newer).
	 *
	 * @return bool
	 */
	public function has_ai_log(): bool {
		return in_array( 'ai_log', Schema::names(), true );
	}

	/**
	 * The longest time the network lets a site keep its AI log, in months, or 0 when nothing limits it.
	 *
	 * @return int
	 */
	public function network_cap_months(): int {
		if ( ! is_multisite() ) {
			return 0;
		}

		$network = get_site_option( 'estasoft_nd_network', array() );
		$cap     = is_array( $network ) ? (int) ( $network['ai']['max_log_months'] ?? 0 ) : 0;

		return max( 0, $cap );
	}

	/**
	 * The periods a site can choose for its AI log.
	 *
	 * @return int[] Months, longest first.
	 */
	public function ai_month_options(): array {
		$cap = $this->network_cap_months();

		return array_values(
			array_filter(
				self::AI_MONTHS,
				static function ( int $months ) use ( $cap ): bool {
					return 0 === $cap || $months <= $cap;
				}
			)
		);
	}

	/**
	 * How long the AI log is kept, in months: the site's choice, held to what the network allows.
	 *
	 * @return int
	 */
	public function ai_months(): int {
		$options = $this->ai_month_options();
		$chosen  = (int) $this->plugin->settings()->get( 'privacy.ai_log_months', 12 );

		if ( array() === $options ) {
			return 12;
		}

		return in_array( $chosen, $options, true ) ? $chosen : $options[0];
	}

	/**
	 * Deletes AI log rows older than the chosen period. Runs after the daily clean-up.
	 *
	 * @return int Rows deleted.
	 */
	public function purge_ai_log(): int {
		global $wpdb;

		if ( ! $this->has_ai_log() ) {
			return 0;
		}

		$limit = ( new \DateTimeImmutable( '@' . Clock::now() ) )->modify( '-' . $this->ai_months() . ' months' )->getTimestamp();
		$table = Schema::table( 'ai_log' );

		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'DELETE FROM %i WHERE created_gmt < %s', $table, Clock::to_gmt( $limit ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return is_int( $deleted ) ? $deleted : 0;
	}

	/**
	 * What is stored, why, what it holds, and for how long.
	 *
	 * @return array<int, array{id: string, what: string, why: string, contains: string, kept: string}>
	 */
	public function stored(): array {
		return $this->inventory()['stored'];
	}

	/**
	 * The rows of the stored list, before other parts add theirs.
	 *
	 * @return array<int, array{id: string, what: string, why: string, contains: string, kept: string}>
	 */
	private function build_stored(): array {
		$days = $this->plugin->settings()->log_days();
		$logs = sprintf(
			/* translators: %d: number of days. */
			_n( '%d day', '%d days', $days, 'estasoft-news-distribution' ),
			$days
		);

		$rows = array(
			$this->stored_row(
				'delivery_log',
				__( 'Delivery log', 'estasoft-news-distribution' ),
				__( 'To show what was sent and the answer', 'estasoft-news-distribution' ),
				__( 'Article ID, channel, time and the platform’s answer. No visitor data.', 'estasoft-news-distribution' ),
				$logs
			),
			$this->stored_row(
				'fetch_log',
				__( 'Fetch log', 'estasoft-news-distribution' ),
				__( 'To show which platforms fetched', 'estasoft-news-distribution' ),
				__( 'Time, the platform’s name from its user-agent text, and the address fetched. IP addresses are not stored.', 'estasoft-news-distribution' ),
				$logs
			),
			$this->stored_row(
				'audit',
				__( 'Audit trail', 'estasoft-news-distribution' ),
				__( 'To show who changed what', 'estasoft-news-distribution' ),
				__( 'The person’s account, what they did (publishing past the pre-publish gate with the reason they typed, taking an article down, importing or restoring settings), the article and the time.', 'estasoft-news-distribution' ),
				$logs
			),
			$this->stored_row(
				'tokens',
				__( 'Connection tokens', 'estasoft-news-distribution' ),
				__( 'To talk to the services you connect', 'estasoft-news-distribution' ),
				__( 'The tokens or keys you paste for Yandex Webmaster, Google Search Console, Bing Webmaster Tools and the outlets you connect, and the Slack and Microsoft Teams webhook addresses. They are stored in your database, are never shown again and are never in an export.', 'estasoft-news-distribution' ),
				__( 'Until you disconnect', 'estasoft-news-distribution' )
			),
			$this->stored_row(
				'alert_addresses',
				__( 'Alert addresses', 'estasoft-news-distribution' ),
				__( 'To send you alerts', 'estasoft-news-distribution' ),
				__( 'The email addresses you type. An email address can be personal data.', 'estasoft-news-distribution' ),
				__( 'Until you remove them', 'estasoft-news-distribution' )
			),
		);

		if ( $this->has_ai_log() ) {
			$months = $this->ai_months();
			$rows[] = $this->stored_row(
				'ai_log',
				__( 'AI log', 'estasoft-news-distribution' ),
				__( 'To show what the AI suggested and who decided', 'estasoft-news-distribution' ),
				__( 'The suggestion, the decision, staff names and which connection was used. Not your keys. A suggestion can contain text from the article it is about.', 'estasoft-news-distribution' ),
				sprintf(
					/* translators: %d: number of months. */
					_n( 'The time you choose below: now %d month', 'The time you choose below: now %d months', $months, 'estasoft-news-distribution' ),
					$months
				)
			);
		}

		$rows[] = $this->stored_row(
			'notes',
			__( 'Notes and assignments', 'estasoft-news-distribution' ),
			__( 'For the application tracker and assigned fixes', 'estasoft-news-distribution' ),
			__( 'Names of your own staff and your notes.', 'estasoft-news-distribution' ),
			__( 'Until you delete them', 'estasoft-news-distribution' )
		);
		$rows[] = $this->stored_row(
			'settings',
			__( 'Settings and rule packs', 'estasoft-news-distribution' ),
			__( 'To remember your choices', 'estasoft-news-distribution' ),
			__( 'Your choices and the rule packs, plus up to five copies of your settings kept as restore points. The alert addresses above are listed separately.', 'estasoft-news-distribution' ),
			__( 'Until you delete it. What happens when you uninstall is set in Backup and uninstall.', 'estasoft-news-distribution' )
		);

		return $rows;
	}

	/**
	 * The outside services the plugin can talk to, and what each receives.
	 *
	 * @return array<int, array{id: string, name: string, kind: string, state: string, on: bool, receives: string}>
	 */
	public function services(): array {
		return $this->inventory()['services'];
	}

	/**
	 * The rows of the services list, before other parts add theirs.
	 *
	 * @return array<int, array{id: string, name: string, kind: string, state: string, on: bool, receives: string}>
	 */
	private function build_services(): array {
		$rows = array();

		foreach (
			array(
				'indexnow' => array(
					__( 'IndexNow', 'estasoft-news-distribution' ),
					__( 'Push, for Bing, Yandex and other search engines that take part', 'estasoft-news-distribution' ),
					__( 'The address of each article you publish or change, and your IndexNow key.', 'estasoft-news-distribution' ),
				),
				'websub'   => array(
					__( 'WebSub', 'estasoft-news-distribution' ),
					__( 'Push, to the feed hub', 'estasoft-news-distribution' ),
					__( 'The address of the feed that changed.', 'estasoft-news-distribution' ),
				),
				'outlets'  => array(
					__( 'Outlets: Medium, Telegram, Mastodon, Bluesky', 'estasoft-news-distribution' ),
					__( 'Push, to the accounts you connect', 'estasoft-news-distribution' ),
					__( 'The post you choose to send: the article’s title, link and image, with the token you saved.', 'estasoft-news-distribution' ),
				),
			) as $channel_id => $text
		) {
			$channel = $this->plugin->registry()->get( $channel_id );

			if ( null === $channel ) {
				continue;
			}

			$state  = $channel->is_enabled() ? $channel->health() : Status::NOT_SET_UP;
			$rows[] = $this->service_row( $channel_id, $text[0], $text[1], $state, false, $text[2] );
		}

		$alerts = $this->plugin->make( Alerts::class );
		$mail   = array() !== $alerts->addresses() && array() !== $alerts->enabled_types();

		$rows[] = $this->service_row(
			'email_alerts',
			__( 'Email alerts', 'estasoft-news-distribution' ),
			__( 'Alert, through your site’s mail setup', 'estasoft-news-distribution' ),
			$mail ? Status::HEALTHY : Status::NOT_SET_UP,
			$mail,
			$mail
				? __( 'The alert text, with a link back, sent to the addresses you entered. It can name an article’s title. It never holds the article’s text.', 'estasoft-news-distribution' )
				: __( 'Nothing is sent until you enter an address and tick an alert.', 'estasoft-news-distribution' )
		);

		foreach ( $alerts->chat_senders() as $service => $sender ) {
			$chat = $alerts->has_chat_address( $service ) && array() !== $alerts->chat_types( $service );

			$rows[] = $this->service_row(
				$service,
				$sender->label(),
				__( 'Alert, to the webhook address you saved', 'estasoft-news-distribution' ),
				$chat ? Status::HEALTHY : Status::NOT_SET_UP,
				$chat,
				$chat
					? __( 'A short message for each alert you ticked: its name, one sentence that can name a platform, a count or a member of your staff, and a link back. It never holds an article’s text or title.', 'estasoft-news-distribution' )
					: __( 'Nothing is sent until an administrator saves a webhook address and you tick an alert for it.', 'estasoft-news-distribution' )
			);
		}

		$connections = $this->plugin->make( Connections::class );

		foreach ( $connections->all() as $id => $engine ) {
			$on = $connections->is_connected( $id );

			$rows[] = $this->service_row(
				$id,
				$engine->label(),
				__( 'Connection', 'estasoft-news-distribution' ),
				$on ? Status::HEALTHY : Status::NOT_SET_UP,
				$on,
				$on
					? __( 'Your sitemap addresses, with the credential you saved. The engine returns the status of each.', 'estasoft-news-distribution' )
					: __( 'Nothing is sent until you connect. Then: your sitemap addresses, with the credential you saved.', 'estasoft-news-distribution' )
			);
		}

		$rows[] = $this->service_row(
			'baidu',
			__( 'Baidu Webmaster Tools', 'estasoft-news-distribution' ),
			__( 'No connection', 'estasoft-news-distribution' ),
			Status::NOT_SET_UP,
			false,
			__( 'Nothing. Baidu fetches the sitemap you submit by hand.', 'estasoft-news-distribution' )
		);

		if ( null !== $this->plugin->settings()->section_object( 'ai' ) ) {
			$ai = (bool) $this->plugin->settings()->get( 'ai.enabled', false );

			$rows[] = $this->service_row(
				'ai',
				__( 'Your AI provider, through WordPress Connectors', 'estasoft-news-distribution' ),
				__( 'AI assistant', 'estasoft-news-distribution' ),
				$ai ? Status::HEALTHY : Status::NOT_SET_UP,
				$ai,
				$ai
					? __( 'The text each AI feature needs, only for the feature you use. A summary, search description or caption request sends the headline and the first 600 characters of the article, never the author or the photo. News Distribution stores no key.', 'estasoft-news-distribution' )
					: __( 'Nothing is sent while the AI assistant is off.', 'estasoft-news-distribution' )
			);
		}

		return $rows;
	}

	/**
	 * Both lists, with the rows other parts of the plugin added.
	 *
	 * @return array{stored: array<int, array<string, string>>, services: array<int, array<string, mixed>>}
	 */
	public function inventory(): array {
		$inventory = array(
			'stored'   => $this->build_stored(),
			'services' => $this->build_services(),
		);

		/**
		 * Filters what the Privacy screen lists.
		 *
		 * @param array{stored: array<int, array<string, mixed>>, services: array<int, array<string, mixed>>} $inventory Rows of both lists. Add rows with the same keys as the ones already there.
		 */
		$filtered = apply_filters( 'estasoft_nd_privacy_inventory', $inventory );

		if ( ! is_array( $filtered ) || ! isset( $filtered['stored'], $filtered['services'] ) || ! is_array( $filtered['stored'] ) || ! is_array( $filtered['services'] ) ) {
			return $inventory;
		}

		return array(
			'stored'   => array_values( $filtered['stored'] ),
			'services' => array_values( $filtered['services'] ),
		);
	}

	/**
	 * One row of the stored list.
	 *
	 * @param string $id       Row key.
	 * @param string $what     What it is.
	 * @param string $why      Why it is kept.
	 * @param string $contains What it holds.
	 * @param string $kept     For how long.
	 * @return array{id: string, what: string, why: string, contains: string, kept: string}
	 */
	private function stored_row( string $id, string $what, string $why, string $contains, string $kept ): array {
		return array(
			'id'       => $id,
			'what'     => $what,
			'why'      => $why,
			'contains' => $contains,
			'kept'     => $kept,
		);
	}

	/**
	 * One row of the services list.
	 *
	 * @param string $id       Row key.
	 * @param string $name     The service's name.
	 * @param string $kind     How it is used.
	 * @param string $state    A Core\Status code.
	 * @param bool   $on       Whether to print "On" instead of the code's word.
	 * @param string $receives What it receives.
	 * @return array{id: string, name: string, kind: string, state: string, on: bool, receives: string}
	 */
	private function service_row( string $id, string $name, string $kind, string $state, bool $on, string $receives ): array {
		return array(
			'id'       => $id,
			'name'     => $name,
			'kind'     => $kind,
			'state'    => $state,
			'on'       => $on,
			'receives' => $receives,
		);
	}

	/**
	 * The suggested text for the Privacy Policy Guide, as paragraphs separated by a blank line.
	 *
	 * It says only what this site's plugin does now: a service that is not there is not mentioned.
	 *
	 * @return string
	 */
	public function policy_text(): string {
		$paragraphs = array();
		$registry   = $this->plugin->registry();
		$pings      = array();

		if ( null !== $registry->get( 'websub' ) ) {
			$pings[] = __( 'a ping about your feeds to WebSub', 'estasoft-news-distribution' );
		}

		if ( null !== $registry->get( 'indexnow' ) ) {
			$pings[] = __( 'an IndexNow notice about each new or changed address', 'estasoft-news-distribution' );
		}

		$first = __( 'News Distribution sends the address, title, summary, image and publish time of each article you publish to the search engines, news platforms and outlets you turn on, so that they can list or post it.', 'estasoft-news-distribution' );

		if ( array() !== $pings ) {
			$first .= ' ' . sprintf(
				/* translators: %s: a list of what else is sent, such as "a ping about your feeds to WebSub and an IndexNow notice about each new or changed address". */
				__( 'It also sends %s.', 'estasoft-news-distribution' ),
				implode( ' ' . __( 'and', 'estasoft-news-distribution' ) . ' ', $pings )
			);
		}

		$paragraphs[] = $first;
		$paragraphs[] = __( 'It does not set cookies and it does not collect or store data about your site’s visitors. The fetch log records which platform fetched a feed and when, and never an IP address. It stores names of your own staff where you enter them, for example in assigned fixes, notes and the audit trail, and the email addresses you enter for alerts.', 'estasoft-news-distribution' );
		$paragraphs[] = __( 'If you turn on alerts, it sends short messages, with a link back and no article text, to the addresses you entered through your site’s mail setup. If you add a Slack or Microsoft Teams webhook address and tick an alert for it, it posts the same short message to that channel, and the message can name a member of your staff, for example who assigned a fix. If you connect Yandex Webmaster, Google Search Console, Bing Webmaster Tools or an outlet such as Telegram, it sends the information needed to submit sitemaps, read their status or post to that service, using a token or key you provided.', 'estasoft-news-distribution' );

		if ( null !== $this->plugin->settings()->section_object( 'ai' ) ) {
			$paragraphs[] = __( 'If you turn on the AI assistant, it sends the text each feature needs to the AI provider you chose in the WordPress Connectors screen, and keeps a log of what the AI suggested and what a person decided. For a summary, a search description or an image caption that is the headline and the first 600 characters of the article, and never the author. A caption request also sends the photo’s credit, but only if you allow author names.', 'estasoft-news-distribution' );
		}

		$paragraphs[] = $this->has_ai_log()
			? __( 'Delivery and fetch logs are kept for the number of days set in Settings (30 by default), and the AI log for the time you choose.', 'estasoft-news-distribution' )
			: __( 'Delivery and fetch logs are kept for the number of days set in Settings (30 by default).', 'estasoft-news-distribution' );

		/**
		 * Filters the paragraphs of the suggested privacy policy text.
		 *
		 * @param string[] $paragraphs Paragraphs, each plain text.
		 */
		$filtered = apply_filters( 'estasoft_nd_privacy_policy_paragraphs', $paragraphs );

		return implode( "\n\n", array_map( 'strval', is_array( $filtered ) ? $filtered : $paragraphs ) );
	}
}
