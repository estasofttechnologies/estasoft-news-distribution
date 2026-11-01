<?php
/**
 * Export, import, restore points and reset of the plugin's settings.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Operations;

use Estasoft\NewsDistribution\Channels\ChannelSettings;
use Estasoft\NewsDistribution\Core\Applications;
use Estasoft\NewsDistribution\Core\Audit;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Referrals\Counter;
use Estasoft\NewsDistribution\Core\Settings;
use Estasoft\NewsDistribution\Core\Settings\Section;
use Estasoft\NewsDistribution\Core\Settings\Validator;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * The service behind Settings, Backup and uninstall.
 *
 * Get it with `Plugin::instance()->make( SettingsBackup::class )`.
 *
 * What an export holds and never holds:
 *
 * - It holds the values of every settings section except `connections` and `data`,
 *   the saved settings of each channel (which are on and how they are set), and,
 *   when asked, the alert addresses, the application tracker and the counts of visits by source.
 * - It never holds a secret: no key, token, password or webhook address, and no
 *   connection. A channel field whose name says it is a secret is left out too.
 * - It holds no article text, no log and no rule pack file.
 *
 * The counts of visits by source (`referrals`) are figures, not a log: one number per day, article and source name,
 * for the last 90 days, the newest `Counter::EXPORT_ROWS` of them, each as one line of text. They name articles by number, so a file read on
 * another site writes none of them: the file says which site it came from (`referrals.site`), and the figures are
 * taken only when that is this site. They are added to the counts here and never lower one. They are the one thing an
 * import writes that a restore point does not hold (a restore point is a copy of options, and the counts are a table),
 * which is why a count is only ever added or raised.
 *
 * File format (`format_version` 1):
 *
 *     array(
 *       'format'         => 'estasoft-news-distribution-settings',
 *       'format_version' => 1,
 *       'plugin_version' => string,
 *       'created'        => string,                              // ISO 8601, UTC
 *       'includes'       => array( 'settings', 'alerts', 'notes', 'referrals' ), // what was asked for
 *       'settings'       => array( section => array( field => value ) ),
 *       'channels'       => array( stored channel key => array( field => value ) ),   // with 'settings'
 *       'notes'          => array( platform id => array( status, applied_on, contact, reminder_on, note ) ), // with 'notes'
 *       'referrals'      => array(                                                    // with 'referrals'
 *         'site'        => string,                 // this site's address, with no trailing slash
 *         'window_days' => int,                    // how far back the counts go
 *         'truncated'   => bool,                   // true when there were more figures than the file holds
 *         'rows'        => array( 'Y-m-d,article ID,source,visits' ),              // one text per figure, so a pretty-printed file keeps one line each
 *       ),
 *     )
 *
 * `preview()` and `apply()` read that array. A preview row:
 *
 *     array(
 *       'path'    => 'general.index_first_delay',
 *       'section' => 'General',
 *       'label'   => 'Index-first delay',
 *       'now'     => '15 minutes',
 *       'file'    => '30 minutes',
 *       'result'  => 'changes' | 'same' | 'skipped' | 'kept',
 *       'reason'  => string,        // why it was skipped, or ''
 *     )
 *
 * A restore point is a copy of the three options that hold settings
 * (`estasoft_nd_settings`, `estasoft_nd_channels`, `estasoft_nd_applications`). It
 * never holds a secret: the secrets live in their own option (`Core\Secrets`), which
 * is not part of a copy. The newest ten are kept for 90 days in the option
 * `estasoft_nd_settings_backups`, which is not autoloaded. The newest point made
 * before an update is never the one dropped when there are more than ten.
 *
 * A point records why it was made (`reason`): `import`, `reset`, `restore`, or
 * `update` (before the plugin's files were replaced; see `UpdatePoints`). A point
 * made before an update also records the version it was going to (`version`, empty when
 * WordPress did not say), the version it came from (`from`) and how it was noticed
 * (`how`: `install` while WordPress replaced the files, `load` when the plugin found
 * at load time that the files had been replaced some other way).
 */
final class SettingsBackup {

	public const FORMAT  = 'estasoft-news-distribution-settings';
	public const VERSION = 1;
	public const OPTION  = 'estasoft_nd_settings_backups';

	public const RESULT_CHANGES = 'changes';
	public const RESULT_SAME    = 'same';
	public const RESULT_SKIPPED = 'skipped';
	public const RESULT_KEPT    = 'kept';

	public const REASON_UPDATE = 'update';
	public const HOW_INSTALL   = 'install';
	public const HOW_LOAD      = 'load';

	/**
	 * What an export can be asked to hold.
	 *
	 * @var string[]
	 */
	public const INCLUDES = array( 'settings', 'alerts', 'notes', 'referrals' );

	public const MAX_POINTS = 10;
	public const MAX_DAYS   = 90;
	public const MAX_BYTES  = 1048576;

	/**
	 * Sections that are never exported, imported or reset: the connections hold keys, and the data choice is this screen's own.
	 *
	 * @var string[]
	 */
	public const EXCLUDED = array( 'connections', 'data' );

	private const STASH = 'estasoft_nd_import_';

	/**
	 * Names of channel fields that look like a secret, left out of an export whatever their spec says.
	 */
	private const SECRET_NAME = '/(^|_)(key|token|secret|password|webhook|signature|salt)(_|$)/i';

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
	 * The sections an export, import or reset can touch.
	 *
	 * @return array<string, Section>
	 */
	public function sections(): array {
		$list = array();

		foreach ( $this->plugin->settings()->sections() as $name => $section ) {
			if ( ! in_array( $name, self::EXCLUDED, true ) && $section->available() ) {
				$list[ $name ] = $section;
			}
		}

		return $list;
	}

	/**
	 * The fields of a section that belong in a file: not a secret, not kept on the person, and not one that says it
	 * is only this site's own state (`portable` => false, such as demo mode).
	 *
	 * @param Section $section The section.
	 * @return array<string, array<string, mixed>>
	 */
	private function portable_fields( Section $section ): array {
		return array_filter(
			$section->fields(),
			static function ( array $spec ): bool {
				return 'secret' !== ( $spec['type'] ?? '' ) && ! isset( $spec['storage'] ) && false !== ( $spec['portable'] ?? true );
			}
		);
	}

	/**
	 * Builds the export.
	 *
	 * @param array<int, string> $includes What to include: `settings`, `alerts`, `notes`, `referrals`. Empty means settings.
	 * @return array<string, mixed> See the class description.
	 */
	public function export( array $includes = array( 'settings' ) ): array {
		$includes = array_values( array_intersect( self::INCLUDES, array_map( 'strval', $includes ) ) );
		$all      = in_array( 'settings', $includes, true );
		$alerts   = in_array( 'alerts', $includes, true );
		$file     = array(
			'format'         => self::FORMAT,
			'format_version' => self::VERSION,
			'plugin_version' => defined( 'ESTASOFT_ND_VERSION' ) ? (string) ESTASOFT_ND_VERSION : '',
			'created'        => gmdate( 'c', Clock::now() ),
			'includes'       => $includes,
		);

		$settings = array();

		foreach ( $this->sections() as $name => $section ) {
			$values = $this->plugin->settings()->section( $name );

			foreach ( array_keys( $this->portable_fields( $section ) ) as $field ) {
				$is_addresses = 'alerts' === $name && 'send_to' === $field;

				if ( $is_addresses ? ! $alerts : ! $all ) {
					continue;
				}

				$settings[ $name ][ $field ] = $values[ $field ] ?? null;
			}
		}

		$file['settings'] = $settings;

		if ( $all ) {
			$file['channels'] = $this->exported_channels();
		}

		if ( in_array( 'notes', $includes, true ) ) {
			$file['notes'] = $this->plugin->applications()->all();
		}

		if ( in_array( 'referrals', $includes, true ) ) {
			$figures = $this->plugin->make( Counter::class )->export();

			$file['referrals'] = array(
				'site'        => self::site_address(),
				'window_days' => Counter::RETENTION_DAYS,
				'truncated'   => $figures['truncated'],
				'rows'        => array_map(
					static function ( array $figure ): string {
						return implode( ',', $figure );
					},
					$figures['rows']
				),
			);
		}

		return $file;
	}

	/**
	 * This site's address as a file records it, so a file read elsewhere can tell the counts are not about its articles.
	 *
	 * @return string The home address with no trailing slash.
	 */
	private static function site_address(): string {
		return untrailingslashit( home_url() );
	}

	/**
	 * Whether the address a file names is this site's, ignoring http or https and the case of the host.
	 *
	 * @param mixed $address What the file says.
	 * @return bool
	 */
	private static function is_this_site( $address ): bool {
		if ( ! is_string( $address ) || '' === $address ) {
			return false;
		}

		$strip = static function ( string $url ): string {
			return strtolower( (string) preg_replace( '#^https?://#i', '', untrailingslashit( $url ) ) );
		};

		return $strip( $address ) === $strip( self::site_address() );
	}

	/**
	 * The channels' saved settings, without anything that is a secret or a log of a test.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function exported_channels(): array {
		$out = array();

		foreach ( $this->plugin->channel_settings()->all() as $key => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			foreach ( array( 'last_test', 'enabled_since' ) as $drop ) {
				unset( $row[ $drop ] );
			}

			foreach ( array_keys( $row ) as $name ) {
				if ( 1 === preg_match( self::SECRET_NAME, (string) $name ) ) {
					unset( $row[ $name ] );
				}
			}

			$out[ (string) $key ] = $row;
		}

		return $out;
	}

	/**
	 * The name the downloaded file gets.
	 *
	 * @return string
	 */
	public function filename(): string {
		return 'news-distribution-settings-' . wp_date( 'Y-m-d', Clock::now(), Clock::zone() ) . '.json';
	}

	/**
	 * Reads the text of a file a person chose.
	 *
	 * @param string $json The file's text.
	 * @return array<string, mixed>|\WP_Error The file as an array, or an error that says what is wrong with it.
	 */
	public function parse( string $json ) {
		if ( strlen( $json ) > self::MAX_BYTES ) {
			return $this->bad( __( 'That file is too large for a settings file. A settings file is plain text of a few kilobytes.', 'estasoft-news-distribution' ) );
		}

		$file = json_decode( $json, true );

		if ( ! is_array( $file ) || ( $file['format'] ?? '' ) !== self::FORMAT ) {
			return $this->bad( __( 'That is not a News Distribution settings file. Choose a file you downloaded from Export settings.', 'estasoft-news-distribution' ) );
		}

		if ( (int) ( $file['format_version'] ?? 0 ) > self::VERSION ) {
			return $this->bad( __( 'That file was made by a newer version of News Distribution. Update this plugin, then try again.', 'estasoft-news-distribution' ) );
		}

		if ( (int) ( $file['format_version'] ?? 0 ) < 1 ) {
			return $this->bad( __( 'That settings file has no version, so it cannot be read.', 'estasoft-news-distribution' ) );
		}

		return $file;
	}

	/**
	 * What an import would change. Nothing is saved.
	 *
	 * @param array<string, mixed> $file The file, from `parse()`.
	 * @return array{file: array{plugin_version: string, read: int}, rows: array<int, array<string, string>>, counts: array{changes: int, same: int, skipped: int, kept: int}}
	 */
	public function preview( array $file ): array {
		$plan = $this->plan( $file );

		return array(
			'file'   => array(
				'plugin_version' => (string) ( $file['plugin_version'] ?? '' ),
				'read'           => $plan['read'],
			),
			'rows'   => $plan['rows'],
			'counts' => $plan['counts'],
		);
	}

	/**
	 * Saves what the preview showed as a change. A restore point is saved first.
	 *
	 * Nothing is half applied: if a section refuses its values, the restore point
	 * is put back and an error is returned.
	 *
	 * @param array<string, mixed> $file The file, from `parse()`.
	 * @return array{applied: int, skipped: int, restore_point: string}|\WP_Error
	 */
	public function apply( array $file ) {
		$plan = $this->plan( $file );

		if ( 0 === $plan['counts'][ self::RESULT_CHANGES ] ) {
			return new \WP_Error(
				'estasoft_nd_nothing_to_import',
				__( 'The file changes nothing, so nothing was imported.', 'estasoft-news-distribution' ),
				array( 'status' => 409 )
			);
		}

		$point    = self::create_point( 'import', '', get_current_user_id() );
		$snapshot = self::snapshot();
		$failed   = null;

		foreach ( $plan['changes']['settings'] as $name => $values ) {
			$saved = $this->plugin->settings()->save_section( $name, $values );

			if ( is_wp_error( $saved ) ) {
				$failed = $saved;
				break;
			}
		}

		if ( null === $failed ) {
			foreach ( $plan['changes']['channels'] as $channel_id => $values ) {
				$channel = $this->plugin->registry()->get( $channel_id );

				if ( null === $channel ) {
					continue;
				}

				$saved = $this->plugin->channel_settings()->save( $channel->id(), $channel->fields(), $values );

				if ( is_wp_error( $saved ) ) {
					$failed = $saved;
					break;
				}
			}
		}

		if ( null === $failed ) {
			foreach ( $plan['changes']['notes'] as $platform => $values ) {
				$saved = $this->plugin->applications()->save( (string) $platform, $values );

				if ( is_wp_error( $saved ) ) {
					$failed = $saved;
					break;
				}
			}
		}

		// Last, because it is the one step a restore point cannot undo: it only runs when everything before it was accepted.
		$figures = 0;

		if ( null === $failed && ( array() !== $plan['changes']['referrals']['add'] || array() !== $plan['changes']['referrals']['raise'] ) ) {
			$figures = $this->plugin->make( Counter::class )->restore( $plan['changes']['referrals']['add'], $plan['changes']['referrals']['raise'] );
		}

		if ( null !== $failed ) {
			$this->write_snapshot( $snapshot );
			$this->forget_point( $point );

			return new \WP_Error(
				'estasoft_nd_import_failed',
				sprintf(
					/* translators: %s: the reason a setting was refused. */
					__( 'Nothing was imported. %s', 'estasoft-news-distribution' ),
					$failed->get_error_message()
				),
				array( 'status' => 422 )
			);
		}

		$this->flush_caches();

		$version = (string) ( $file['plugin_version'] ?? '' );
		$version = '' === $version ? '?' : $version;

		$this->plugin->audit()->record(
			Audit::IMPORT,
			0,
			sprintf(
				/* translators: 1: number of settings changed, 2: plugin version that made the file. */
				_n( 'Imported %1$d setting from a file made by News Distribution %2$s.', 'Imported %1$d settings from a file made by News Distribution %2$s.', $plan['counts'][ self::RESULT_CHANGES ], 'estasoft-news-distribution' ),
				$plan['counts'][ self::RESULT_CHANGES ],
				$version
			)
		);

		if ( $figures > 0 ) {
			$this->plugin->audit()->record(
				Audit::IMPORT,
				0,
				sprintf(
					/* translators: %s: how many figures of visits by source were added or raised. */
					_n( 'Added or raised %s visit count by source from the file.', 'Added or raised %s visit counts by source from the file.', $figures, 'estasoft-news-distribution' ),
					number_format_i18n( $figures )
				)
			);
		}

		return array(
			'applied'       => $plan['counts'][ self::RESULT_CHANGES ],
			'skipped'       => $plan['counts'][ self::RESULT_SKIPPED ],
			'restore_point' => $point,
		);
	}

	/**
	 * Keeps an uploaded file for the next step of a form that works without a script.
	 *
	 * @param string $json The file's text.
	 * @return void
	 */
	public function stash( string $json ): void {
		set_transient( self::STASH . get_current_user_id(), $json, 15 * MINUTE_IN_SECONDS );
	}

	/**
	 * The file kept by `stash()`, if it is still there.
	 *
	 * @return string
	 */
	public function stashed(): string {
		$json = get_transient( self::STASH . get_current_user_id() );

		return is_string( $json ) ? $json : '';
	}

	/**
	 * Drops the file kept by `stash()`.
	 *
	 * @return void
	 */
	public function unstash(): void {
		delete_transient( self::STASH . get_current_user_id() );
	}

	/**
	 * The restore points, newest first, with the ones that are too old or too many left out.
	 *
	 * `version`, `from` and `how` are set for a point made before an update (see the class description) and empty for the others.
	 *
	 * @return array<int, array{id: string, created: int, reason: string, section: string, user_id: int, count: int, version: string, from: string, how: string}>
	 */
	public function points(): array {
		$out = array();

		foreach ( self::read_points() as $point ) {
			$out[] = array(
				'id'      => (string) $point['id'],
				'created' => (int) $point['created'],
				'reason'  => (string) $point['reason'],
				'section' => (string) ( $point['section'] ?? '' ),
				'user_id' => (int) ( $point['user_id'] ?? 0 ),
				'count'   => (int) ( $point['count'] ?? 0 ),
				'version' => (string) ( $point['version'] ?? '' ),
				'from'    => (string) ( $point['from'] ?? '' ),
				'how'     => (string) ( $point['how'] ?? '' ),
			);
		}

		return $out;
	}

	/**
	 * The words for why a restore point was made, as the screen prints them.
	 *
	 * @param string $reason  import, reset, restore or update.
	 * @param string $section The section name, for a reset.
	 * @param string $version The version an update was going to, for an update. Empty when it was not known.
	 * @param string $how     How an update was noticed: `install` or `load`.
	 * @return string
	 */
	public function reason_text( string $reason, string $section = '', string $version = '', string $how = '' ): string {
		if ( self::REASON_UPDATE === $reason ) {
			if ( '' === $version ) {
				return __( 'Before an update', 'estasoft-news-distribution' );
			}

			if ( self::HOW_LOAD === $how ) {
				return sprintf(
					/* translators: %s: a plugin version number, such as "1.1.0". */
					__( 'Before the update to %s', 'estasoft-news-distribution' ),
					$version
				);
			}

			return sprintf(
				/* translators: %s: a plugin version number, such as "1.1.0". */
				__( 'Before updating to %s', 'estasoft-news-distribution' ),
				$version
			);
		}

		if ( 'reset' === $reason ) {
			$object = $this->plugin->settings()->section_object( $section );

			return sprintf(
				/* translators: %s: the name of a settings section, such as "Image credits". */
				__( 'Before resetting %s', 'estasoft-news-distribution' ),
				null === $object ? $section : $object->title()
			);
		}

		if ( 'restore' === $reason ) {
			return __( 'Before restoring an earlier point', 'estasoft-news-distribution' );
		}

		return __( 'Before importing a file', 'estasoft-news-distribution' );
	}

	/**
	 * Puts a restore point back. The current settings are saved as a new restore point first.
	 *
	 * @param string $id The restore point's ID.
	 * @return array{restore_point: string}|\WP_Error
	 */
	public function restore( string $id ) {
		$found = null;

		foreach ( self::read_points() as $point ) {
			if ( (string) $point['id'] === $id ) {
				$found = $point;
				break;
			}
		}

		if ( null === $found ) {
			return new \WP_Error(
				'estasoft_nd_unknown_restore_point',
				__( 'That restore point is gone. Restore points are kept for the last 10, for 90 days.', 'estasoft-news-distribution' ),
				array( 'status' => 404 )
			);
		}

		$new = self::create_point( 'restore', '', get_current_user_id() );

		$this->write_snapshot( (array) $found['data'] );
		$this->flush_caches();

		$this->plugin->audit()->record(
			Audit::RESTORE,
			0,
			sprintf(
				/* translators: %s: a date and time. */
				__( 'Restored the settings saved on %s.', 'estasoft-news-distribution' ),
				Clock::datetime( (int) $found['created'] )
			)
		);

		return array( 'restore_point' => $new );
	}

	/**
	 * Puts one section back to what a new install has. A restore point is saved first.
	 *
	 * @param string $name Section name.
	 * @return array{restore_point: string}|\WP_Error
	 */
	public function reset( string $name ) {
		if ( ! isset( $this->sections()[ $name ] ) ) {
			return new \WP_Error(
				'estasoft_nd_unknown_section',
				__( 'That settings section cannot be reset. Connections and keys are never reset.', 'estasoft-news-distribution' ),
				array( 'status' => 404 )
			);
		}

		$point = self::create_point( 'reset', $name, get_current_user_id() );

		$this->plugin->settings()->reset_section( $name );
		$this->flush_caches();

		$this->plugin->audit()->record(
			Audit::SETTINGS,
			0,
			sprintf(
				/* translators: %s: the name of a settings section. */
				__( 'Reset %s to what a new install has.', 'estasoft-news-distribution' ),
				$this->sections()[ $name ]->title()
			)
		);

		return array( 'restore_point' => $point );
	}

	/**
	 * What the delete choice would remove, in numbers.
	 *
	 * @return array{settings: int, logs: int, notes: int, actions: int, actions_more: bool, article_ids: int}
	 */
	public function inventory(): array {
		global $wpdb;

		$logs = 0;
		$more = 0;

		foreach ( array( 'delivery_log', 'fetch_log' ) as $name ) {
			$logs += $this->rows( $name );
		}

		foreach ( array( 'fixes', 'audit', 'ai_log' ) as $name ) {
			$more += $this->rows( $name );
		}

		$notes = $more + count( array_filter( $this->plugin->applications()->all(), 'is_array' ) ) + count( self::read_points() );

		$actions      = 0;
		$actions_more = false;

		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$ids          = as_get_scheduled_actions(
				array(
					'group'    => \Estasoft\NewsDistribution\Core\Queue::GROUP,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => 200,
				),
				'ids'
			);
			$actions      = is_array( $ids ) ? count( $ids ) : 0;
			$actions_more = $actions >= 200;
		}

		$article_ids = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", '_estasoft_nd_id' ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return array(
			'settings'     => self::count_settings( self::snapshot() ),
			'logs'         => $logs,
			'notes'        => $notes,
			'actions'      => $actions,
			'actions_more' => $actions_more,
			'article_ids'  => $article_ids,
		);
	}

	/**
	 * Rows in one of the plugin's tables.
	 *
	 * @param string $name Short table name.
	 * @return int
	 */
	private function rows( string $name ): int {
		global $wpdb;

		if ( ! in_array( $name, \Estasoft\NewsDistribution\Core\Db\Schema::names(), true ) ) {
			return 0;
		}

		$table = \Estasoft\NewsDistribution\Core\Db\Schema::table( $name );

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is built from the prefix and a fixed list.
	}

	/**
	 * Works out what a file would do, for both the preview and the apply.
	 *
	 * @param array<string, mixed> $file The file.
	 * @return array{rows: array<int, array<string, string>>, counts: array<string, int>, read: int, changes: array{settings: array<string, array<string, mixed>>, channels: array<string, array<string, mixed>>, notes: array<string, array<string, mixed>>, referrals: array{add: array<int, array<int, mixed>>, raise: array<int, array<int, mixed>>}}}
	 */
	private function plan( array $file ): array {
		$rows    = array();
		$read    = 0;
		$changes = array(
			'settings'  => array(),
			'channels'  => array(),
			'notes'     => array(),
			'referrals' => array(
				'add'   => array(),
				'raise' => array(),
			),
		);

		// Sections.
		$known = $this->sections();

		foreach ( (array) ( $file['settings'] ?? array() ) as $name => $values ) {
			$name = (string) $name;

			if ( ! is_array( $values ) ) {
				continue;
			}

			$section = $known[ $name ] ?? null;

			foreach ( $values as $field => $value ) {
				++$read;
				$field = (string) $field;
				$path  = $name . '.' . $field;

				if ( null === $section ) {
					$rows[] = $this->skipped_row( $path, $name, $path, $value, __( 'This version does not know it.', 'estasoft-news-distribution' ) );
					continue;
				}

				$fields = $this->portable_fields( $section );

				if ( ! isset( $fields[ $field ] ) ) {
					$rows[] = $this->skipped_row( $path, $section->title(), $field, $value, __( 'This version does not know it.', 'estasoft-news-distribution' ) );
					continue;
				}

				$spec    = $fields[ $field ];
				$current = $this->plugin->settings()->section( $name );
				$clean   = Validator::clean( array( $field => $spec ), array( $field => $value ), $current );
				$label   = (string) ( $spec['row_label'] ?? $spec['label'] ?? $field );

				if ( array() !== $clean['errors'] && $this->equal( $current[ $field ] ?? null, $value ) ) {
					// The site already holds exactly this, even if the field cannot take it as a new value (an empty default language).
					$rows[] = $this->same_row( $path, $section->title(), $label, $spec, $value );
					continue;
				}

				if ( array() !== $clean['errors'] ) {
					$rows[] = array(
						'path'    => $path,
						'section' => $section->title(),
						'label'   => $label,
						'now'     => $this->describe( $spec, $current[ $field ] ?? null ),
						'file'    => $this->describe( $spec, $value ),
						'result'  => self::RESULT_SKIPPED,
						'reason'  => (string) reset( $clean['errors'] ),
					);
					continue;
				}

				$new  = $clean['values'][ $field ];
				$same = $this->equal( $current[ $field ] ?? null, $new );

				$rows[] = array(
					'path'    => $path,
					'section' => $section->title(),
					'label'   => $label,
					'now'     => $this->describe( $spec, $current[ $field ] ?? null ),
					'file'    => $this->describe( $spec, $new ),
					'result'  => $same ? self::RESULT_SAME : self::RESULT_CHANGES,
					'reason'  => '',
				);

				if ( ! $same ) {
					$changes['settings'][ $name ][ $field ] = $new;
				}
			}
		}

		// A section can refuse a combination its fields allow one by one.
		foreach ( $changes['settings'] as $name => $values ) {
			$errors = $known[ $name ]->cross_check( array_merge( $this->plugin->settings()->section( $name ), $values ) );

			if ( array() === $errors ) {
				continue;
			}

			$why = (string) reset( $errors );

			foreach ( $rows as $index => $row ) {
				if ( 0 === strpos( $row['path'], $name . '.' ) && self::RESULT_CHANGES === $row['result'] ) {
					$rows[ $index ]['result'] = self::RESULT_SKIPPED;
					$rows[ $index ]['reason'] = $why;
				}
			}

			unset( $changes['settings'][ $name ] );
		}

		$this->plan_channels( $file, $rows, $changes, $read );
		$this->plan_notes( $file, $rows, $changes, $read );
		$this->plan_referrals( $file, $rows, $changes, $read );
		$this->add_kept_rows( $rows );

		$counts = array(
			self::RESULT_CHANGES => 0,
			self::RESULT_SAME    => 0,
			self::RESULT_SKIPPED => 0,
			self::RESULT_KEPT    => 0,
		);

		foreach ( $rows as $row ) {
			++$counts[ $row['result'] ];
		}

		$order = array_flip( array( self::RESULT_CHANGES, self::RESULT_KEPT, self::RESULT_SKIPPED, self::RESULT_SAME ) );

		usort(
			$rows,
			static function ( array $a, array $b ) use ( $order ): int {
				return $order[ $a['result'] ] <=> $order[ $b['result'] ];
			}
		);

		return array(
			'rows'    => array_slice( $rows, 0, 500 ),
			'counts'  => $counts,
			'read'    => $read,
			'changes' => $changes,
		);
	}

	/**
	 * Adds the channel rows of the plan.
	 *
	 * @param array<string, mixed>              $file    The file.
	 * @param array<int, array<string, string>> $rows    Rows so far.
	 * @param array<string, mixed>              $changes Changes so far.
	 * @param int                               $read    Settings read so far.
	 * @return void
	 */
	private function plan_channels( array $file, array &$rows, array &$changes, int &$read ): void {
		$by_key = array();

		foreach ( $this->plugin->registry()->all() as $channel ) {
			$by_key[ ChannelSettings::key( $channel->id() ) ] = $channel;
		}

		foreach ( (array) ( $file['channels'] ?? array() ) as $key => $values ) {
			$key = (string) $key;

			if ( ! is_array( $values ) ) {
				continue;
			}

			$channel = $by_key[ $key ] ?? null;

			foreach ( $values as $field => $value ) {
				++$read;
				$field = (string) $field;

				if ( null === $channel ) {
					$rows[] = $this->skipped_row( 'channels.' . $key . '.' . $field, __( 'Channels', 'estasoft-news-distribution' ), $key . ' / ' . $field, $value, __( 'This version does not know that channel.', 'estasoft-news-distribution' ) );
					continue;
				}

				$specs   = $channel->fields();
				$current = $this->plugin->channel_settings()->get(
					$channel->id(),
					array_filter(
						$specs,
						static function ( array $spec ): bool {
							return 'secret' !== ( $spec['type'] ?? '' );
						}
					)
				);
				$path    = 'channels.' . $key . '.' . $field;

				if ( 'enabled' === $field ) {
					$spec  = array(
						'type'  => 'bool',
						'label' => $channel->label(),
					);
					$label = $channel->label();
				} elseif ( isset( $specs[ $field ] ) && 'secret' !== ( $specs[ $field ]['type'] ?? '' ) ) {
					$spec  = $specs[ $field ];
					$label = $channel->label() . ', ' . (string) ( $spec['row_label'] ?? $spec['label'] ?? $field );
				} else {
					$rows[] = $this->skipped_row( $path, $channel->label(), $channel->label() . ', ' . $field, $value, __( 'This version does not know it.', 'estasoft-news-distribution' ) );
					continue;
				}

				$clean = Validator::clean( array( $field => $spec ), array( $field => $value ), $current );

				if ( array() !== $clean['errors'] && $this->equal( $current[ $field ] ?? null, $value ) ) {
					$rows[] = $this->same_row( $path, $channel->label(), $label, $spec, $value );
					continue;
				}

				if ( array() !== $clean['errors'] ) {
					$rows[] = array(
						'path'    => $path,
						'section' => $channel->label(),
						'label'   => $label,
						'now'     => $this->describe( $spec, $current[ $field ] ?? null ),
						'file'    => $this->describe( $spec, $value ),
						'result'  => self::RESULT_SKIPPED,
						'reason'  => (string) reset( $clean['errors'] ),
					);
					continue;
				}

				$new  = $clean['values'][ $field ];
				$same = $this->equal( $current[ $field ] ?? null, $new );

				$rows[] = array(
					'path'    => $path,
					'section' => $channel->label(),
					'label'   => $label,
					'now'     => $this->describe( $spec, $current[ $field ] ?? null ),
					'file'    => $this->describe( $spec, $new ),
					'result'  => $same ? self::RESULT_SAME : self::RESULT_CHANGES,
					'reason'  => '',
				);

				if ( ! $same ) {
					$changes['channels'][ $channel->id() ][ $field ] = $new;
				}
			}
		}
	}

	/**
	 * Adds the application tracker rows of the plan.
	 *
	 * @param array<string, mixed>              $file    The file.
	 * @param array<int, array<string, string>> $rows    Rows so far.
	 * @param array<string, mixed>              $changes Changes so far.
	 * @param int                               $read    Settings read so far.
	 * @return void
	 */
	private function plan_notes( array $file, array &$rows, array &$changes, int &$read ): void {
		$platforms = Applications::platforms();
		$labels    = array(
			'status'      => __( 'Status', 'estasoft-news-distribution' ),
			'applied_on'  => __( 'Applied on', 'estasoft-news-distribution' ),
			'contact'     => __( 'Contact', 'estasoft-news-distribution' ),
			'reminder_on' => __( 'Remind me on', 'estasoft-news-distribution' ),
			'note'        => __( 'Note', 'estasoft-news-distribution' ),
		);

		foreach ( (array) ( $file['notes'] ?? array() ) as $platform => $values ) {
			$platform = (string) $platform;

			if ( ! is_array( $values ) ) {
				continue;
			}

			foreach ( $values as $field => $value ) {
				++$read;
				$field = (string) $field;
				$path  = 'notes.' . $platform . '.' . $field;
				$name  = $platforms[ $platform ] ?? $platform;

				if ( ! isset( $platforms[ $platform ] ) || ! isset( $labels[ $field ] ) || ! is_scalar( $value ) ) {
					$rows[] = $this->skipped_row( $path, __( 'Notes', 'estasoft-news-distribution' ), $name . ', ' . $field, $value, __( 'This version does not know it.', 'estasoft-news-distribution' ) );
					continue;
				}

				$current = $this->plugin->applications()->get( $platform );
				$probe   = new Applications( $this->plugin->applications()->all() );
				$result  = $probe->save( $platform, array( $field => $value ) );
				$label   = $name . ', ' . $labels[ $field ];

				if ( is_wp_error( $result ) ) {
					$rows[] = array(
						'path'    => $path,
						'section' => __( 'Notes', 'estasoft-news-distribution' ),
						'label'   => $label,
						'now'     => (string) $current[ $field ],
						'file'    => (string) $value,
						'result'  => self::RESULT_SKIPPED,
						'reason'  => $result->get_error_message(),
					);
					continue;
				}

				$new  = (string) $result[ $field ];
				$same = (string) $current[ $field ] === $new;

				$rows[] = array(
					'path'    => $path,
					'section' => __( 'Notes', 'estasoft-news-distribution' ),
					'label'   => $label,
					'now'     => '' === (string) $current[ $field ] ? __( 'None', 'estasoft-news-distribution' ) : (string) $current[ $field ],
					'file'    => '' === $new ? __( 'None', 'estasoft-news-distribution' ) : $new,
					'result'  => $same ? self::RESULT_SAME : self::RESULT_CHANGES,
					'reason'  => '',
				);

				if ( ! $same ) {
					$changes['notes'][ $platform ][ $field ] = $new;
				}
			}
		}
	}

	/**
	 * Adds the visit counts rows of the plan.
	 *
	 * One row says what would be added or raised, or that nothing would be; a second says what is left out and why.
	 * A file from another site is left out whole: the figures name articles by number.
	 *
	 * @param array<string, mixed>              $file    The file.
	 * @param array<int, array<string, string>> $rows    Rows so far.
	 * @param array<string, mixed>              $changes Changes so far.
	 * @param int                               $read    Settings read so far.
	 * @return void
	 */
	private function plan_referrals( array $file, array &$rows, array &$changes, int &$read ): void {
		if ( ! isset( $file['referrals'] ) || ! is_array( $file['referrals'] ) ) {
			return;
		}

		$counts  = $this->plugin->make( Counter::class );
		$figures = isset( $file['referrals']['rows'] ) && is_array( $file['referrals']['rows'] ) ? $file['referrals']['rows'] : array();
		$section = __( 'Visit counts', 'estasoft-news-distribution' );
		$label   = __( 'Visit counts by source', 'estasoft-news-distribution' );
		$here    = $counts->rows();
		$now     = sprintf(
			/* translators: %s: a number of figures. */
			_n( '%s figure', '%s figures', $here, 'estasoft-news-distribution' ),
			number_format_i18n( $here )
		);
		$in_file = sprintf(
			/* translators: %s: a number of figures. */
			_n( '%s figure', '%s figures', count( $figures ), 'estasoft-news-distribution' ),
			number_format_i18n( count( $figures ) )
		);

		$read += count( $figures );

		if ( array() === $figures ) {
			return;
		}

		if ( ! self::is_this_site( $file['referrals']['site'] ?? null ) ) {
			$rows[] = array(
				'path'    => 'referrals',
				'section' => $section,
				'label'   => $label,
				'now'     => $now,
				'file'    => $in_file,
				'result'  => self::RESULT_SKIPPED,
				'reason'  => __( 'These counts belong to another site. They name articles by number, so they are only read on the site they came from.', 'estasoft-news-distribution' ),
			);

			return;
		}

		// A figure is one text, "2026-10-21,1234,google_news,12". Anything else is handed over as it is and refused there.
		$plan    = $counts->plan_restore(
			array_map(
				static function ( $figure ) {
					return is_string( $figure ) ? explode( ',', $figure ) : $figure;
				},
				$figures
			)
		);
		$changed = count( $plan['add'] ) + count( $plan['raise'] );

		$changes['referrals'] = array(
			'add'   => $plan['add'],
			'raise' => $plan['raise'],
		);

		if ( $changed > 0 ) {
			$rows[] = array(
				'path'    => 'referrals',
				'section' => $section,
				'label'   => $label,
				'now'     => $now,
				'file'    => sprintf(
					/* translators: %s: a number of figures. */
					_n( '%s figure added or raised', '%s figures added or raised', $changed, 'estasoft-news-distribution' ),
					number_format_i18n( $changed )
				),
				'result'  => self::RESULT_CHANGES,
				'reason'  => '',
			);
		} elseif ( $plan['same'] > 0 ) {
			$rows[] = array(
				'path'    => 'referrals',
				'section' => $section,
				'label'   => $label,
				'now'     => $now,
				'file'    => $now,
				'result'  => self::RESULT_SAME,
				'reason'  => '',
			);
		}

		$left_out = array_sum( $plan['skipped'] );

		if ( $left_out > 0 ) {
			$why = array();

			foreach ( array(
				'old'     => __( 'older than the counts are kept, or dated in the future', 'estasoft-news-distribution' ),
				'article' => __( 'for an article this site does not have', 'estasoft-news-distribution' ),
				'source'  => __( 'for a source this version does not know', 'estasoft-news-distribution' ),
				'visits'  => __( 'with a number of visits that is not possible', 'estasoft-news-distribution' ),
				'shape'   => __( 'that could not be read', 'estasoft-news-distribution' ),
			) as $key => $words ) {
				if ( $plan['skipped'][ $key ] > 0 ) {
					$why[] = number_format_i18n( $plan['skipped'][ $key ] ) . ' ' . $words;
				}
			}

			$rows[] = array(
				'path'    => 'referrals.skipped',
				'section' => $section,
				'label'   => __( 'Visit counts left out', 'estasoft-news-distribution' ),
				'now'     => __( 'None', 'estasoft-news-distribution' ),
				'file'    => sprintf(
					/* translators: %s: a number of figures. */
					_n( '%s figure', '%s figures', $left_out, 'estasoft-news-distribution' ),
					number_format_i18n( $left_out )
				),
				'result'  => self::RESULT_SKIPPED,
				'reason'  => implode( '; ', $why ) . '.',
			);
		}
	}

	/**
	 * Adds one "Kept as it is" row for the secrets saved here, because a file never carries one.
	 *
	 * One row for all of them: the answer is the same for each, and a site with several outlets would
	 * otherwise list a dozen identical lines.
	 *
	 * @param array<int, array<string, string>> $rows Rows so far.
	 * @return void
	 */
	private function add_kept_rows( array &$rows ): void {
		$secrets = $this->plugin->secrets();
		$count   = 0;

		foreach ( $this->plugin->settings()->sections() as $name => $section ) {
			foreach ( $section->fields() as $field => $spec ) {
				if ( 'secret' === ( $spec['type'] ?? '' ) && $secrets->has( $name . '_' . $field ) ) {
					++$count;
				}
			}
		}

		foreach ( $this->plugin->registry()->all() as $channel ) {
			foreach ( $channel->fields() as $field => $spec ) {
				if ( 'secret' === ( $spec['type'] ?? '' ) && $secrets->has( ChannelSettings::secret_key( $channel->id(), (string) $field ) ) ) {
					++$count;
				}
			}
		}

		if ( $count > 0 ) {
			$rows[] = $this->kept_row( 'secret.all', __( 'Keys and tokens', 'estasoft-news-distribution' ), $count );
		}
	}

	/**
	 * The row for the saved secrets.
	 *
	 * @param string $path    Row key.
	 * @param string $section Where it belongs.
	 * @param int    $count   How many are saved.
	 * @return array<string, string>
	 */
	private function kept_row( string $path, string $section, int $count ): array {
		return array(
			'path'    => $path,
			'section' => $section,
			'label'   => __( 'Saved keys, tokens and passwords', 'estasoft-news-distribution' ),
			'now'     => sprintf(
				/* translators: %d: how many keys, tokens and passwords are saved. */
				_n( '%d saved and hidden', '%d saved and hidden', $count, 'estasoft-news-distribution' ),
				$count
			),
			'file'    => __( 'Never in a file', 'estasoft-news-distribution' ),
			'result'  => self::RESULT_KEPT,
			'reason'  => '',
		);
	}

	/**
	 * A row for a setting the file and the site agree on.
	 *
	 * @param string               $path    Row key.
	 * @param string               $section Where it belongs.
	 * @param string               $label   Its name.
	 * @param array<string, mixed> $spec    The field's spec.
	 * @param mixed                $value   The value both hold.
	 * @return array<string, string>
	 */
	private function same_row( string $path, string $section, string $label, array $spec, $value ): array {
		return array(
			'path'    => $path,
			'section' => $section,
			'label'   => $label,
			'now'     => $this->describe( $spec, $value ),
			'file'    => $this->describe( $spec, $value ),
			'result'  => self::RESULT_SAME,
			'reason'  => '',
		);
	}

	/**
	 * A row for something this version cannot take.
	 *
	 * @param string $path    Row key.
	 * @param string $section Where it would belong.
	 * @param string $label   Its name in the file.
	 * @param mixed  $value   Its value in the file.
	 * @param string $reason  Why it is skipped.
	 * @return array<string, string>
	 */
	private function skipped_row( string $path, string $section, string $label, $value, string $reason ): array {
		return array(
			'path'    => $path,
			'section' => $section,
			'label'   => $label,
			'now'     => __( 'None', 'estasoft-news-distribution' ),
			'file'    => $this->describe( array(), $value ),
			'result'  => self::RESULT_SKIPPED,
			'reason'  => $reason,
		);
	}

	/**
	 * Whether two values are the same setting.
	 *
	 * @param mixed $a One value.
	 * @param mixed $b Another.
	 * @return bool
	 */
	private function equal( $a, $b ): bool {
		return wp_json_encode( $a ) === wp_json_encode( $b );
	}

	/**
	 * A value as a person reads it: "On", "20 characters", "English, Arabic".
	 *
	 * @param array<string, mixed> $spec  The field's spec, or an empty array when it is not known.
	 * @param mixed                $value The value.
	 * @return string
	 */
	public function describe( array $spec, $value ): string {
		$type = (string) ( $spec['type'] ?? '' );

		if ( null === $value || '' === $value || array() === $value ) {
			return 'bool' === $type ? __( 'Off', 'estasoft-news-distribution' ) : __( 'None', 'estasoft-news-distribution' );
		}

		if ( 'bool' === $type || is_bool( $value ) ) {
			return $value ? __( 'On', 'estasoft-news-distribution' ) : __( 'Off', 'estasoft-news-distribution' );
		}

		if ( 'int' === $type || is_int( $value ) ) {
			$text = number_format_i18n( (int) $value );

			return isset( $spec['unit'] ) && '' !== (string) $spec['unit'] ? $text . ' ' . (string) $spec['unit'] : $text;
		}

		if ( 'enum' === $type ) {
			return $this->option_label( $spec, (string) $value );
		}

		if ( is_array( $value ) ) {
			$item  = (array) ( $spec['item'] ?? array() );
			$parts = array();
			$keyed = 'list' !== $type && array_keys( $value ) !== range( 0, count( $value ) - 1 );

			if ( 'list' === $type || ! $keyed ) {
				foreach ( $value as $one ) {
					$parts[] = is_scalar( $one ) ? $this->option_label( $item, (string) $one ) : '…';
				}

				$shown = array_slice( $parts, 0, 3 );

				return implode( ', ', $shown ) . ( count( $parts ) > 3 ? sprintf(
					/* translators: %d: number of items not shown. */
					__( ' and %d more', 'estasoft-news-distribution' ),
					count( $parts ) - 3
				) : '' );
			}

			return sprintf(
				/* translators: %d: number of entries. */
				_n( '%d entry', '%d entries', count( $value ), 'estasoft-news-distribution' ),
				count( $value )
			);
		}

		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $value ) ) );

		return mb_strlen( $text ) > 60 ? mb_substr( $text, 0, 60 ) . '…' : $text;
	}

	/**
	 * The label of one choice of an enum or list field.
	 *
	 * @param array<string, mixed> $spec  The spec that holds `options`.
	 * @param string               $value The choice.
	 * @return string
	 */
	private function option_label( array $spec, string $value ): string {
		$options = $spec['options'] ?? array();
		$options = is_callable( $options ) ? (array) call_user_func( $options ) : (array) $options;

		if ( isset( $options[ $value ] ) ) {
			$label = $options[ $value ];

			return is_array( $label ) ? (string) ( $label['label'] ?? $value ) : (string) $label;
		}

		return $value;
	}

	/**
	 * A copy of the three options that hold settings.
	 *
	 * @return array{settings: array<string, mixed>, channels: array<string, mixed>, applications: array<string, mixed>}
	 */
	private static function snapshot(): array {
		$read = static function ( string $option ): array {
			$value = get_option( $option, array() );

			return is_array( $value ) ? $value : array();
		};

		return array(
			'settings'     => $read( Settings::OPTION ),
			'channels'     => $read( ChannelSettings::OPTION ),
			'applications' => $read( Applications::OPTION ),
		);
	}

	/**
	 * Writes a copy back. The connections and the delete choice stay as they are now.
	 *
	 * @param array<string, mixed> $data A copy made by `snapshot()`.
	 * @return void
	 */
	private function write_snapshot( array $data ): void {
		$current  = get_option( Settings::OPTION, array() );
		$current  = is_array( $current ) ? $current : array();
		$settings = (array) ( $data['settings'] ?? array() );

		foreach ( self::EXCLUDED as $name ) {
			if ( isset( $current[ $name ] ) ) {
				$settings[ $name ] = $current[ $name ];
			} else {
				unset( $settings[ $name ] );
			}
		}

		update_option( Settings::OPTION, $settings, false );
		update_option( ChannelSettings::OPTION, (array) ( $data['channels'] ?? array() ), false );
		update_option( Applications::OPTION, (array) ( $data['applications'] ?? array() ), false );

		$this->flush_caches();

		/** This action is documented in src/Core/Settings.php. */
		do_action( 'estasoft_nd_output_changed', 'restore' );
	}

	/**
	 * Forgets what this request read, so the next read goes to the options.
	 *
	 * @return void
	 */
	private function flush_caches(): void {
		$this->plugin->settings()->flush();
		$this->plugin->channel_settings()->flush();
		$this->plugin->applications()->flush();
	}

	/**
	 * How many settings a copy holds: one for each saved value.
	 *
	 * @param array<string, mixed> $data A copy made by `snapshot()`.
	 * @return int
	 */
	private static function count_settings( array $data ): int {
		$count = 0;

		foreach ( array_merge( (array) $data['settings'], (array) $data['channels'] ) as $values ) {
			$count += is_array( $values ) ? count( $values ) : 1;
		}

		return $count;
	}

	/**
	 * The stored restore points that are still kept, newest first.
	 *
	 * Two points made in the same second stay in the order they were saved, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function read_points(): array {
		$stored = get_option( self::OPTION, array() );
		$limit  = Clock::now() - self::MAX_DAYS * DAY_IN_SECONDS;
		$out    = array();
		$place  = 0;

		foreach ( is_array( $stored ) ? $stored : array() as $point ) {
			if ( is_array( $point ) && isset( $point['id'], $point['created'], $point['data'] ) && (int) $point['created'] >= $limit ) {
				$out[] = array(
					'place' => $place,
					'point' => $point,
				);
			}

			++$place;
		}

		usort(
			$out,
			static function ( array $a, array $b ): int {
				return array( (int) $b['point']['created'], $a['place'] ) <=> array( (int) $a['point']['created'], $b['place'] );
			}
		);

		return self::trim( array_column( $out, 'point' ) );
	}

	/**
	 * Keeps the newest ten points. When there are more, the oldest go first, except the newest point
	 * made before an update, which is never the one dropped.
	 *
	 * @param array<int, array<string, mixed>> $points Points, newest first.
	 * @return array<int, array<string, mixed>>
	 */
	private static function trim( array $points ): array {
		$points = array_values( $points );
		$excess = count( $points ) - self::MAX_POINTS;

		if ( $excess <= 0 ) {
			return $points;
		}

		$kept = null;

		foreach ( $points as $index => $point ) {
			if ( self::REASON_UPDATE === ( $point['reason'] ?? '' ) ) {
				$kept = $index;
				break;
			}
		}

		for ( $index = count( $points ) - 1; $index >= 0 && $excess > 0; $index-- ) {
			if ( $index === $kept ) {
				continue;
			}

			unset( $points[ $index ] );
			--$excess;
		}

		return array_values( $points );
	}

	/**
	 * Saves the current settings as a restore point.
	 *
	 * This does not use the running plugin, only the options, so it can run while the plugin is still
	 * starting and while it is being activated.
	 *
	 * @param string               $reason  import, reset, restore or update.
	 * @param string               $section The section, for a reset.
	 * @param int                  $user_id Who did it, or 0 when nobody did.
	 * @param array<string, mixed> $extra   More facts to keep with the point (`version`, `from`, `how` for an update).
	 * @return string The restore point's ID.
	 */
	private static function create_point( string $reason, string $section = '', int $user_id = 0, array $extra = array() ): string {
		$data = self::snapshot();
		$id   = substr( md5( uniqid( $reason, true ) ), 0, 12 );
		$list = self::read_points();

		array_unshift(
			$list,
			array_merge(
				$extra,
				array(
					'id'      => $id,
					'created' => Clock::now(),
					'reason'  => $reason,
					'section' => $section,
					'user_id' => $user_id,
					'count'   => self::count_settings( $data ),
					'data'    => $data,
				)
			)
		);

		update_option( self::OPTION, self::trim( $list ), false );

		return $id;
	}

	/**
	 * Saves a restore point because the plugin is being updated, or was.
	 *
	 * Called by `UpdatePoints`; see there for when. It never uses the running plugin and never
	 * holds a secret.
	 *
	 * @param string $version The version the plugin is going to, or an empty string when WordPress did not say.
	 * @param string $from    The version the settings were saved by.
	 * @param string $how     `install` (WordPress is replacing the files) or `load` (found at load time).
	 * @param int    $user_id Who started the update, or 0.
	 * @return string The restore point's ID.
	 */
	public static function save_update_point( string $version, string $from, string $how, int $user_id = 0 ): string {
		return self::create_point(
			self::REASON_UPDATE,
			'',
			$user_id,
			array(
				'version' => $version,
				'from'    => $from,
				'how'     => self::HOW_LOAD === $how ? self::HOW_LOAD : self::HOW_INSTALL,
			)
		);
	}

	/**
	 * Whether a point made before an update to this version, from that version, is already kept.
	 *
	 * @param string $version The version the plugin went to.
	 * @param string $from    The version it came from.
	 * @return bool
	 */
	public static function has_update_point( string $version, string $from ): bool {
		foreach ( self::read_points() as $point ) {
			if ( self::REASON_UPDATE === ( $point['reason'] ?? '' ) && (string) ( $point['version'] ?? '' ) === $version && (string) ( $point['from'] ?? '' ) === $from ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Gives the newest update point that does not know its version the version it turned out to go to.
	 *
	 * WordPress does not always know the new version when it starts an update. When the plugin finds the
	 * new version at its next load, it names that point, instead of saving a second copy of the same settings.
	 * Only a point made in the last hour is named: an older one belongs to an update that is long over, and
	 * giving it a new version would make a point from months ago look like the one just taken.
	 *
	 * @param string $from    The version the point came from.
	 * @param string $version The version the plugin went to.
	 * @return bool Whether a point was named.
	 */
	public static function name_update_point( string $from, string $version ): bool {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			return false;
		}

		$newest   = null;
		$earliest = Clock::now() - HOUR_IN_SECONDS;

		foreach ( $stored as $index => $point ) {
			if ( ! is_array( $point ) || self::REASON_UPDATE !== ( $point['reason'] ?? '' ) || '' !== (string) ( $point['version'] ?? '' ) || (string) ( $point['from'] ?? '' ) !== $from ) {
				continue;
			}

			if ( (int) ( $point['created'] ?? 0 ) < $earliest ) {
				continue;
			}

			if ( null === $newest || (int) $point['created'] > (int) $stored[ $newest ]['created'] ) {
				$newest = $index;
			}
		}

		if ( null === $newest ) {
			return false;
		}

		$stored[ $newest ]['version'] = $version;

		update_option( self::OPTION, $stored, false );

		return true;
	}

	/**
	 * Drops one restore point, for an import that was rolled back.
	 *
	 * @param string $id The restore point's ID.
	 * @return void
	 */
	private function forget_point( string $id ): void {
		$list = array_values(
			array_filter(
				self::read_points(),
				static function ( array $point ) use ( $id ): bool {
					return (string) $point['id'] !== $id;
				}
			)
		);

		update_option( self::OPTION, $list, false );
	}

	/**
	 * An error for a file that cannot be used.
	 *
	 * @param string $message What is wrong.
	 * @return \WP_Error
	 */
	private function bad( string $message ): \WP_Error {
		return new \WP_Error( 'estasoft_nd_bad_settings_file', $message, array( 'status' => 400 ) );
	}
}
