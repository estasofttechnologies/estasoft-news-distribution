<?php
/**
 * The pre-publish gate: what the editor panel shows, the check the server makes, and the record of "Publish anyway".
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

use Estasoft\NewsDistribution\Checks\ArticleChecker;
use Estasoft\NewsDistribution\Checks\CheckReport;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * The gate reads the settings in Settings, Access and gate: `access.gate_mode`
 * (off, warn or block), `access.gate_channels` (the channels that must pass),
 * `access.gate_override` (who may publish anyway) and `access.gate_reason_required`.
 *
 * Two parts use it.
 *
 * - The block editor panel (`assets/js/editor/gate.js`) shows what does not pass and, in block mode, holds the
 *   Publish button back. `evaluate()` is what it asks for.
 * - The server. In block mode `server_check()` is asked about every save that would make an article public:
 *   the REST API, the classic editor, Quick Edit, bulk edit and XML-RPC all pass through it
 *   (`Modules\GateServerModule` hooks it up). A person who may override sends the reason with the save
 *   (the `X-Estasoft-ND-Override` header, or the `estasoft_nd_override_reason` field of the classic editor) and
 *   `record_publish_override()` writes the audit row once, when the article really goes live.
 *
 * What the server does not stop: an article that is already public, a scheduled post when WordPress publishes it
 * on time (the check was made when it was scheduled), WP-CLI, an import, a save made by nobody signed in, and a
 * check that fails to run or takes longer than the time budget (the article goes out and the audit table says so).
 *
 * Get it with `Plugin::instance()->make( Gate::class )`.
 */
final class Gate {

	public const OFF   = 'off';
	public const WARN  = 'warn';
	public const BLOCK = 'block';

	/**
	 * Channel line states.
	 */
	public const PASS = 'pass';
	public const NA   = 'na';

	/**
	 * Fewest and most characters of the reason for publishing anyway.
	 */
	public const MIN_REASON = 10;
	public const MAX_REASON = 300;

	/**
	 * Where a person sends the reason with a save. The header value is the reason percent-encoded as UTF-8, so
	 * any script travels in a header; a plain ASCII reason works as it is. A single "-" means "no reason typed",
	 * for a site that does not ask for one. The classic editor posts the reason in the field instead.
	 */
	public const OVERRIDE_HEADER = 'X-Estasoft-ND-Override';
	public const OVERRIDE_FIELD  = 'estasoft_nd_override_reason';
	public const ANYWAY_FIELD    = 'estasoft_nd_publish_anyway';

	/**
	 * The code of the answer that refuses a publish.
	 */
	public const REFUSED = 'estasoft_nd_gate_blocked';

	/**
	 * Seconds the server's check may take. A check that took longer lets the article through.
	 */
	public const BUDGET = 2.0;

	/**
	 * Transient that holds, for one person, the articles the server kept unpublished.
	 */
	private const KEPT = 'estasoft_nd_gate_kept_';

	/**
	 * Minutes an audit row for the same article and person stands in for a second one.
	 */
	private const SAME_ROW_MINUTES = 10;

	private Plugin $plugin;

	/**
	 * What the save in progress was kept from doing, until the caller reads it.
	 *
	 * @var array{result: array<string, mixed>, post_id: int}|null
	 */
	private ?array $held = null;

	/**
	 * Creates the service.
	 *
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * The mode in force: off, warn or block.
	 *
	 * @return string
	 */
	public function mode(): string {
		$mode = (string) $this->plugin->settings()->get( 'access.gate_mode', self::OFF );

		return in_array( $mode, array( self::WARN, self::BLOCK ), true ) ? $mode : self::OFF;
	}

	/**
	 * Whether the server refuses a publish that fails the gate. True only in block mode.
	 *
	 * @return bool
	 */
	public function enforced(): bool {
		return self::BLOCK === $this->mode();
	}

	/**
	 * The channels that must pass, only the ones that are on.
	 *
	 * @return string[] Channel ids.
	 */
	public function required(): array {
		$chosen = (array) $this->plugin->settings()->get( 'access.gate_channels', array() );
		$out    = array();

		foreach ( $this->plugin->registry()->enabled() as $channel ) {
			if ( in_array( $channel->id(), $chosen, true ) ) {
				$out[] = $channel->id();
			}
		}

		return $out;
	}

	/**
	 * Whether a person has to type a reason to publish anyway.
	 *
	 * @return bool
	 */
	public function reason_required(): bool {
		return (bool) $this->plugin->settings()->get( 'access.gate_reason_required', true );
	}

	/**
	 * Whether a person may publish an article that fails the gate.
	 *
	 * @param int      $post_id Article ID, or 0 for an article that is not saved yet.
	 * @param int|null $user_id Person, or the signed-in user.
	 * @return bool
	 */
	public function can_override( int $post_id, ?int $user_id = null ): bool {
		$access = $this->plugin->access();

		if ( $post_id < 1 ) {
			return $access->can( Access::OVERRIDE, null, $user_id );
		}

		return $access->can( Access::OVERRIDE, $post_id, $user_id ) && $access->can_edit_article( $post_id, $user_id );
	}

	/**
	 * What the editor needs to know before it opens the panel.
	 *
	 * @param int $post_id Article ID.
	 * @return array<string, mixed>
	 */
	public function config( int $post_id ): array {
		return array(
			'mode'            => $this->mode(),
			'enforced'        => $this->enforced(),
			'reasonRequired'  => $this->reason_required(),
			'reasonMin'       => self::MIN_REASON,
			'reasonMax'       => self::MAX_REASON,
			'canOverride'     => $this->can_override( $post_id ),
			'hasRequirements' => array() !== $this->required(),
			'limits'          => $this->limits(),
			'overrideHeader'  => self::OVERRIDE_HEADER,
		);
	}

	/**
	 * The plain statement of what the gate does and does not stop, for the panel.
	 *
	 * @return string
	 */
	public function limits(): string {
		if ( $this->enforced() ) {
			return __( 'This is checked on the server too. The REST API, XML-RPC, the classic editor, Quick Edit and bulk edit refuse an article that fails unless the person publishes it anyway with a reason. It does not stop a scheduled post when WordPress publishes it on time, WP-CLI, an import, or an article that is already published.', 'estasoft-news-distribution' );
		}

		return __( 'Nothing is stopped in this mode. The panel only shows the problem, and the REST API, XML-RPC, WP-CLI, the classic editor and other editors are not held back either. Choose Block publishing in Settings, Access and gate to refuse an article that fails.', 'estasoft-news-distribution' );
	}

	/**
	 * How the article stands against the gate, from a fresh check or the stored one.
	 *
	 * @param int  $post_id Article ID.
	 * @param bool $run     Run the checks now, instead of reading the stored result.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function evaluate( int $post_id, bool $run = true ) {
		$post = $this->post( $post_id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$checker = $this->plugin->make( ArticleChecker::class );
		$report  = $run ? $checker->check( $post_id ) : $checker->stored( $post_id );
		$report  = null === $report ? $checker->check( $post_id ) : $report;

		if ( null === $report ) {
			return $this->fail( 'estasoft_nd_unknown_article', __( 'That article does not exist.', 'estasoft-news-distribution' ), 404 );
		}

		return $this->state( $post_id, $report );
	}

	/**
	 * Records that a person published an article the gate was holding back, with the reason they typed.
	 *
	 * This is the route the panel used before the server enforced the gate. The panel now sends the reason with
	 * the save instead, and the server writes the row when the article goes live.
	 *
	 * @param int      $post_id Article ID.
	 * @param string   $reason  Why.
	 * @param int|null $user_id Person, or the signed-in user.
	 * @return array{recorded: bool, reason: string, failing: string[]}|\WP_Error
	 */
	public function override( int $post_id, string $reason, ?int $user_id = null ) {
		$post = $this->post( $post_id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$user_id = null === $user_id ? get_current_user_id() : $user_id;

		if ( ! $this->can_override( $post_id, $user_id ) ) {
			return $this->fail( 'estasoft_nd_override_not_allowed', __( 'Your role cannot publish an article that fails the checks. Fix the problems, or ask someone who can.', 'estasoft-news-distribution' ), rest_authorization_required_code() );
		}

		if ( self::BLOCK !== $this->mode() ) {
			return $this->fail( 'estasoft_nd_gate_not_blocking', __( 'The checks are not blocking publishing on this site, so there is nothing to publish anyway.', 'estasoft-news-distribution' ), 409 );
		}

		$reason = $this->clean_reason( $reason );

		if ( $this->reason_required() && mb_strlen( $reason ) < self::MIN_REASON ) {
			return $this->fail( 'estasoft_nd_reason_needed', __( 'Write a reason to publish anyway.', 'estasoft-news-distribution' ), 422, array( 'reason' ) );
		}

		$state   = $this->evaluate( $post_id, false );
		$failing = is_wp_error( $state ) ? array() : (array) $state['failing'];

		$this->write_override_row( $post_id, $reason, $failing, $user_id );

		return array(
			'recorded' => true,
			'reason'   => $reason,
			'failing'  => $failing,
		);
	}

	/**
	 * What holds an article back, read from its stored check: null when nothing does.
	 *
	 * The classic editor's box uses it. It never runs the checks, so opening an edit screen costs nothing.
	 *
	 * @param int $post_id Article ID.
	 * @return array<string, mixed>|null The gate's reading (see evaluate()) with `findings`, the blocking ones.
	 */
	public function hold_for( int $post_id ): ?array {
		$report = $this->plugin->make( ArticleChecker::class )->stored( $post_id );

		if ( null === $report || ! $this->enforced() ) {
			return null;
		}

		$state = $this->state( $post_id, $report );

		if ( empty( $state['blocked'] ) ) {
			return null;
		}

		$state['findings'] = $this->blocking_findings( $report );

		return $state;
	}

	// ---------------------------------------------------------------------
	// The server.
	// ---------------------------------------------------------------------

	/**
	 * Which kind of request this is when the gate must stand aside: `import`, `cli`, `cron`, or an empty string
	 * for a person using the site.
	 *
	 * @return string
	 */
	public static function exempt_context(): string {
		$context = self::context_of( wp_doing_cron(), defined( 'WP_CLI' ) && WP_CLI, defined( 'WP_IMPORTING' ) && WP_IMPORTING );

		/**
		 * Changes the kind of request the gate thinks it is in (internal): `import`, `cli`, `cron` or an empty string.
		 *
		 * @param string $context What was found.
		 */
		return (string) apply_filters( 'estasoft_nd_internal_gate_context', $context );
	}

	/**
	 * The kind of request, from three facts. An import wins over WP-CLI, and WP-CLI over WP-Cron.
	 *
	 * @param bool $cron   WP-Cron is running.
	 * @param bool $cli    WP-CLI is running.
	 * @param bool $import An import is running.
	 * @return string `import`, `cli`, `cron` or an empty string.
	 */
	public static function context_of( bool $cron, bool $cli, bool $import ): string {
		if ( $import ) {
			return 'import';
		}

		if ( $cli ) {
			return 'cli';
		}

		return $cron ? 'cron' : '';
	}

	/**
	 * The status an article keeps when the gate holds its publish back: the one it had, or a draft.
	 *
	 * @param string $old_status The status in the database, or an empty string for a new article.
	 * @return string
	 */
	public function kept_status( string $old_status ): string {
		return in_array( $old_status, array( '', 'new', 'auto-draft', 'publish', 'future', 'inherit' ), true ) ? 'draft' : $old_status;
	}

	/**
	 * The one question every save that could make an article public asks.
	 *
	 * `$postarr` is what the save carries, not slashed: `ID` (zero or missing for a new article), `post_status`
	 * (the status it is about to get), `post_type`, `post_title`, `post_content`, `post_author`, `post_date_gmt`,
	 * `post_password`, and when the caller knows them `_thumbnail_id`, `post_category` and `tax_input`. Anything
	 * it leaves out is read from the saved article. The check runs on that data, not on the stored check, so a
	 * save that fixes the title and publishes in one request passes, and one that breaks it does not.
	 *
	 * The answer is always an array: `applies` (the gate looked at this save), `allowed`, `blocked` (a channel
	 * that must pass has a blocking finding), `override` (the save carries a valid reason), `problem` (empty,
	 * `blocked`, `reason_needed` or `not_allowed`), `skipped` (empty, `error` or `slow`), `failing` (channel
	 * names), `findings` (the blocking ones: `code`, `message`, `channels`) and `reason` (cleaned).
	 *
	 * @param array<string, mixed> $postarr    What the save carries.
	 * @param string               $old_status The status in the database; empty for a new article.
	 * @param string|null          $sent       The override header's value as sent, or null to read this request
	 *                                         (the header, then the classic editor's field).
	 * @return array<string, mixed>
	 */
	public function server_check( array $postarr, string $old_status, ?string $sent = null ): array {
		$result = array(
			'applies'  => false,
			'allowed'  => true,
			'blocked'  => false,
			'override' => false,
			'problem'  => '',
			'skipped'  => '',
			'failing'  => array(),
			'findings' => array(),
			'reason'   => '',
		);

		if ( ! $this->applies( $postarr, $old_status ) ) {
			return $result;
		}

		$result['applies'] = true;
		$post_id           = (int) ( $postarr['ID'] ?? 0 );
		$started           = microtime( true );

		try {
			$existing = $post_id > 0 ? get_post( $post_id ) : null;
			$article  = $this->article_for( $postarr, $existing instanceof \WP_Post ? $existing : null );
			$report   = $this->plugin->make( ArticleChecker::class )->build( $article );
			$state    = $this->state( $post_id, $report );
		} catch ( \Throwable $error ) {
			return $this->let_through( $result, 'error', $post_id, mb_substr( $error->getMessage(), 0, 200 ) );
		}

		$elapsed = microtime( true ) - $started;

		/**
		 * Changes how many seconds the server's check may take before it lets the article through (internal).
		 *
		 * @param float $seconds The time budget.
		 */
		if ( $elapsed > (float) apply_filters( 'estasoft_nd_internal_gate_budget', self::BUDGET ) ) {
			return $this->let_through( $result, 'slow', $post_id, (string) round( $elapsed, 1 ) );
		}

		if ( ! $state['blocked'] ) {
			return $result;
		}

		$result['blocked']  = true;
		$result['allowed']  = false;
		$result['failing']  = (array) $state['failing'];
		$result['findings'] = $this->blocking_findings( $report );

		$sent_reason = $this->read_override( $sent, $post_id );

		$result['reason'] = $sent_reason['text'];

		if ( ! $sent_reason['present'] ) {
			$result['problem'] = 'blocked';
		} elseif ( ! $this->can_override( $post_id ) ) {
			$result['problem'] = 'not_allowed';
		} elseif ( $this->reason_required() && mb_strlen( $sent_reason['text'] ) < self::MIN_REASON ) {
			$result['problem'] = 'reason_needed';
		} else {
			$result['override'] = true;
			$result['allowed']  = true;
		}

		return $result;
	}

	/**
	 * Notes that the save in progress was kept from going live, so the code that answers the caller can say so.
	 *
	 * Only XML-RPC reads this: its calls return a yes or an ID, so the answer has to be changed after the save.
	 *
	 * @param array<string, mixed> $result  The answer of `server_check()`.
	 * @param int                  $post_id The article, or 0 for one not saved yet.
	 * @return void
	 */
	public function note_held( array $result, int $post_id ): void {
		$this->held = array(
			'result'  => $result,
			'post_id' => $post_id,
		);
	}

	/**
	 * Forgets what `note_held()` kept, before a call starts.
	 *
	 * @return void
	 */
	public function forget_held(): void {
		$this->held = null;
	}

	/**
	 * What `note_held()` kept since the last `forget_held()`, and forgets it.
	 *
	 * @return array{result: array<string, mixed>, post_id: int}|null
	 */
	public function take_held(): ?array {
		$held       = $this->held;
		$this->held = null;

		return $held;
	}

	/**
	 * Writes the audit row for an article published past the gate, once.
	 *
	 * The row is the same one the panel's route writes. It is skipped when that route already wrote one for this
	 * article and person a moment ago, so one publish is one row whichever way the reason arrived.
	 *
	 * @param int      $post_id Article ID.
	 * @param string   $reason  The reason, cleaned.
	 * @param string[] $failing Names of the channels that did not pass.
	 * @param int      $user_id Person.
	 * @return bool Whether a row was written.
	 */
	public function record_publish_override( int $post_id, string $reason, array $failing, int $user_id ): bool {
		$limit = Clock::now() - ( self::SAME_ROW_MINUTES * MINUTE_IN_SECONDS );

		foreach ( $this->plugin->audit()->recent( 25 ) as $row ) {
			if ( Audit::GATE_OVERRIDE === $row['action'] && (int) $row['post_id'] === $post_id && (int) $row['user_id'] === $user_id && (int) $row['created_ts'] >= $limit ) {
				return false;
			}
		}

		$this->write_override_row( $post_id, $reason, $failing, $user_id );

		return true;
	}

	/**
	 * The refusal a REST request gets: 409, the findings and the way to override.
	 *
	 * @param array<string, mixed> $result What server_check() answered.
	 * @param int                  $post_id Article ID, or 0.
	 * @return \WP_Error
	 */
	public function refusal( array $result, int $post_id = 0 ): \WP_Error {
		$can = $this->can_override( $post_id );

		return new \WP_Error(
			self::REFUSED,
			$this->refusal_text( $result, $can ),
			array(
				'status'       => 409,
				'problem'      => (string) $result['problem'],
				'failing'      => array_values( (array) $result['failing'] ),
				'findings'     => array_values( (array) $result['findings'] ),
				'can_override' => $can,
				'override'     => array(
					'header'          => self::OVERRIDE_HEADER,
					'field'           => self::OVERRIDE_FIELD,
					'reason_required' => $this->reason_required(),
					'reason_min'      => self::MIN_REASON,
					'reason_max'      => self::MAX_REASON,
					'how'             => $can
						? __( 'Send the reason with the save in the X-Estasoft-ND-Override header, written as percent-encoded UTF-8. Your role may do this.', 'estasoft-news-distribution' )
						: __( 'Your role cannot publish an article that fails the checks. Ask an editor or administrator, or fix the problems.', 'estasoft-news-distribution' ),
				),
			)
		);
	}

	/**
	 * One plain sentence about a refused publish.
	 *
	 * @param array<string, mixed> $result       What server_check() answered.
	 * @param bool                 $can_override Whether this person may publish anyway.
	 * @return string
	 */
	public function refusal_text( array $result, bool $can_override ): string {
		$names = implode( ', ', (array) $result['failing'] );

		if ( 'reason_needed' === $result['problem'] ) {
			return __( 'Write a reason to publish anyway.', 'estasoft-news-distribution' );
		}

		if ( 'not_allowed' === $result['problem'] ) {
			return __( 'Your role cannot publish an article that fails the checks. Fix the problems, or ask someone who can.', 'estasoft-news-distribution' );
		}

		if ( $can_override ) {
			return sprintf(
				/* translators: %s: channel names such as "Google News, Apple News". */
				_n( 'Publishing is on hold: %s does not pass the checks you require. Fix the problem, or publish anyway with a reason.', 'Publishing is on hold: %s do not pass the checks you require. Fix the problems, or publish anyway with a reason.', count( (array) $result['failing'] ), 'estasoft-news-distribution' ),
				$names
			);
		}

		return sprintf(
			/* translators: %s: channel names such as "Google News, Apple News". */
			_n( 'Publishing is on hold: %s does not pass the checks you require. Fix the problem, or ask an editor or administrator.', 'Publishing is on hold: %s do not pass the checks you require. Fix the problems, or ask an editor or administrator.', count( (array) $result['failing'] ), 'estasoft-news-distribution' ),
			$names
		);
	}

	/**
	 * Remembers an article the server kept unpublished, for the notice on the person's next page.
	 *
	 * @param array<string, mixed> $item    `post_id`, `title`, `failing` (names), `messages` and `problem`.
	 * @param int|null             $user_id Person, or the signed-in user.
	 * @return void
	 */
	public function keep( array $item, ?int $user_id = null ): void {
		$user_id = null === $user_id ? get_current_user_id() : $user_id;

		if ( $user_id < 1 ) {
			return;
		}

		$list = get_transient( self::KEPT . $user_id );
		$list = is_array( $list ) ? $list : array();

		$list[] = array(
			'post_id'  => (int) ( $item['post_id'] ?? 0 ),
			'title'    => mb_substr( (string) ( $item['title'] ?? '' ), 0, 200 ),
			'failing'  => array_values( array_map( 'strval', (array) ( $item['failing'] ?? array() ) ) ),
			'messages' => array_values( array_map( 'strval', (array) ( $item['messages'] ?? array() ) ) ),
			'problem'  => (string) ( $item['problem'] ?? 'blocked' ),
		);

		set_transient( self::KEPT . $user_id, array_slice( $list, -50 ), 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * The articles kept unpublished since the person last looked, which are forgotten once read.
	 *
	 * @param int|null $user_id Person, or the signed-in user.
	 * @return array<int, array{post_id: int, title: string, failing: string[], messages: string[], problem: string}>
	 */
	public function take_kept( ?int $user_id = null ): array {
		$user_id = null === $user_id ? get_current_user_id() : $user_id;

		if ( $user_id < 1 ) {
			return array();
		}

		$list = get_transient( self::KEPT . $user_id );

		if ( false === $list ) {
			return array();
		}

		delete_transient( self::KEPT . $user_id );

		return is_array( $list ) ? $list : array();
	}

	// ---------------------------------------------------------------------
	// Internals.
	// ---------------------------------------------------------------------

	/**
	 * Whether the server's check looks at this save at all.
	 *
	 * @param array<string, mixed> $postarr    What the save carries.
	 * @param string               $old_status The status in the database.
	 * @return bool
	 */
	private function applies( array $postarr, string $old_status ): bool {
		if ( ! $this->enforced() || get_current_user_id() < 1 ) {
			return false;
		}

		if ( ! in_array( (string) ( $postarr['post_status'] ?? '' ), array( 'publish', 'future' ), true ) || in_array( $old_status, array( 'publish', 'future' ), true ) ) {
			return false;
		}

		$post_id = (int) ( $postarr['ID'] ?? 0 );
		$type    = (string) ( $postarr['post_type'] ?? '' );

		if ( '' === $type && $post_id > 0 ) {
			$type = (string) get_post_type( $post_id );
		}

		if ( ! in_array( $type, $this->plugin->settings()->post_types(), true ) || '' !== self::exempt_context() ) {
			return false;
		}

		/**
		 * Lets a site or the plugin add an exception to the server's gate (internal): return false to let this save through.
		 *
		 * @param bool                 $applies    True: the gate checks this save.
		 * @param array<string, mixed> $postarr    What the save carries.
		 * @param string               $old_status The status in the database.
		 */
		return (bool) apply_filters( 'estasoft_nd_internal_gate_applies', true, $postarr, $old_status );
	}

	/**
	 * The gate's reading of a check: channel lines, what fails, whether it blocks.
	 *
	 * @param int         $post_id Article ID, or 0 for one that is not saved yet.
	 * @param CheckReport $report  The check.
	 * @return array<string, mixed>
	 */
	private function state( int $post_id, CheckReport $report ): array {
		$required = $this->required();
		$found    = $report->channels();
		$lines    = array();
		$failing  = array();
		$covered  = array();

		foreach ( $required as $id ) {
			$channel = $this->plugin->registry()->get( $id );
			$label   = null === $channel ? $id : $channel->label();

			if ( ! isset( $found[ $id ] ) ) {
				// A channel the person switched off for this article is left out of the check, so it cannot fail the gate.
				$left_out = $post_id > 0 && $this->plugin->make( ArticleChoices::class )->is_left_out( $post_id, $id );

				$lines[] = array(
					'id'       => $id,
					'label'    => $label,
					'status'   => self::NA,
					'message'  => $left_out
						? __( 'does not apply to this article: the channel is switched off for it.', 'estasoft-news-distribution' )
						: __( 'does not apply to this article: the channel does not take its language.', 'estasoft-news-distribution' ),
					'findings' => array(),
				);
				continue;
			}

			$findings = array_values( (array) $found[ $id ]['findings'] );
			$blocks   = array_values(
				array_filter(
					$findings,
					static function ( array $finding ): bool {
						return Status::BLOCK === $finding['severity'];
					}
				)
			);
			$warns    = array_values(
				array_filter(
					$findings,
					static function ( array $finding ): bool {
						return Status::WARN === $finding['severity'];
					}
				)
			);

			foreach ( $findings as $finding ) {
				$covered[ (string) $finding['code'] ] = true;
			}

			if ( array() !== $blocks ) {
				$status    = Status::BLOCK;
				$message   = (string) $blocks[0]['message'];
				$failing[] = $label;
			} elseif ( array() !== $warns ) {
				$status  = Status::WARN;
				$message = (string) $warns[0]['message'];
			} else {
				$status  = self::PASS;
				$message = __( 'passes.', 'estasoft-news-distribution' );
			}

			$lines[] = array(
				'id'       => $id,
				'label'    => $label,
				'status'   => $status,
				'message'  => $message,
				'findings' => array_column( $findings, 'code' ),
			);
		}

		$others = array();

		foreach ( $report->findings() as $finding ) {
			if ( isset( $covered[ (string) $finding['code'] ] ) || Status::INFO === $finding['severity'] ) {
				continue;
			}

			$others[] = array(
				'code'     => (string) $finding['code'],
				'severity' => (string) $finding['severity'],
				'message'  => (string) $finding['message'],
				'channels' => $this->labels( (array) ( $finding['channels'] ?? array() ) ),
			);
		}

		$mode    = $this->mode();
		$blocked = self::BLOCK === $mode && array() !== $failing;

		return array(
			'post_id'      => $post_id,
			'mode'         => $mode,
			'checked'      => $report->checked(),
			'checked_iso'  => Clock::iso( $report->checked() ),
			'required'     => $required,
			'channels'     => $lines,
			'failing'      => $failing,
			'others'       => $others,
			'passes'       => array() === $failing,
			'blocked'      => $blocked,
			'can_override' => $blocked && $this->can_override( $post_id ),
			'enforced'     => $this->enforced(),
			'limits'       => $this->limits(),
		);
	}

	/**
	 * The blocking findings of the channels that must pass, each with the names of the channels it blocks.
	 *
	 * @param CheckReport $report The check.
	 * @return array<int, array{code: string, message: string, channels: string[]}>
	 */
	private function blocking_findings( CheckReport $report ): array {
		$found = $report->channels();
		$out   = array();

		foreach ( $this->required() as $id ) {
			if ( ! isset( $found[ $id ] ) ) {
				continue;
			}

			$channel = $this->plugin->registry()->get( $id );
			$label   = null === $channel ? $id : $channel->label();

			foreach ( (array) $found[ $id ]['findings'] as $finding ) {
				if ( Status::BLOCK !== $finding['severity'] ) {
					continue;
				}

				$key = (string) $finding['code'] . '|' . (string) $finding['message'];

				if ( ! isset( $out[ $key ] ) ) {
					$out[ $key ] = array(
						'code'     => (string) $finding['code'],
						'message'  => (string) $finding['message'],
						'channels' => array(),
					);
				}

				$out[ $key ]['channels'][] = $label;
			}
		}

		return array_values( $out );
	}

	/**
	 * The article as it will be once the save is done: the saved one with what the save changes.
	 *
	 * It reads the same fields as `ContentReader` does, so a channel judges the same thing it will later send.
	 * `GateServerTest` compares the two for an article nothing changes, so a field added to the reader and not
	 * here fails a test.
	 *
	 * @param array<string, mixed> $incoming What the save carries.
	 * @param \WP_Post|null        $existing The saved article, or null for a new one.
	 * @return Article
	 */
	public function article_for( array $incoming, ?\WP_Post $existing ): Article {
		$now_gmt = Clock::to_gmt();
		$base    = $existing instanceof \WP_Post
			? $existing->to_array()
			: array(
				'ID'                => 0,
				'post_author'       => get_current_user_id(),
				'post_title'        => '',
				'post_content'      => '',
				'post_excerpt'      => '',
				'post_status'       => 'draft',
				'post_type'         => 'post',
				'post_password'     => '',
				'post_name'         => '',
				'post_date'         => get_date_from_gmt( $now_gmt ),
				'post_date_gmt'     => $now_gmt,
				'post_modified'     => get_date_from_gmt( $now_gmt ),
				'post_modified_gmt' => $now_gmt,
			);

		foreach ( array( 'post_author', 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_type', 'post_password' ) as $field ) {
			if ( isset( $incoming[ $field ] ) ) {
				$base[ $field ] = $incoming[ $field ];
			}
		}

		$date_gmt = isset( $incoming['post_date_gmt'] ) ? (string) $incoming['post_date_gmt'] : '';

		if ( '' !== $date_gmt && '0000-00-00 00:00:00' !== $date_gmt ) {
			$base['post_date_gmt'] = $date_gmt;
			$base['post_date']     = isset( $incoming['post_date'] ) ? (string) $incoming['post_date'] : get_date_from_gmt( $date_gmt );
		} elseif ( '0000-00-00 00:00:00' === (string) ( $base['post_date_gmt'] ?? '' ) ) {
			$base['post_date_gmt'] = $now_gmt;
			$base['post_date']     = get_date_from_gmt( $now_gmt );
		}

		$base['post_modified_gmt'] = $now_gmt;
		$base['post_modified']     = get_date_from_gmt( $now_gmt );

		$post      = new \WP_Post( (object) $base );
		$author_id = (int) $post->post_author;
		$post_id   = (int) $post->ID;
		$thumb_id  = array_key_exists( '_thumbnail_id', $incoming ) ? max( 0, (int) $incoming['_thumbnail_id'] ) : (int) get_post_thumbnail_id( $post );
		$thumb     = $thumb_id ? wp_get_attachment_image_src( $thumb_id, 'full' ) : false;

		return new Article(
			array(
				'id'               => $post_id,
				// The filters below are the ones ContentReader applies; see the note above.
				'title'            => (string) apply_filters( 'estasoft_nd_internal_article_title', wp_strip_all_tags( (string) $post->post_title ), $post ),
				'content'          => (string) apply_filters( 'estasoft_nd_internal_article_content', (string) $post->post_content, $post ),
				'permalink'        => $post_id > 0 ? (string) get_permalink( $post ) : '',
				'language'         => $post_id > 0 ? $this->plugin->languages()->language_of_post( $post_id ) : $this->plugin->languages()->default_language(),
				'author_name'      => (string) get_the_author_meta( 'display_name', $author_id ),
				'author_url'       => (string) get_author_posts_url( $author_id ),
				'thumbnail_id'     => $thumb ? $thumb_id : 0,
				'thumbnail_url'    => $thumb ? (string) $thumb[0] : '',
				'thumbnail_width'  => $thumb ? (int) $thumb[1] : 0,
				'thumbnail_height' => $thumb ? (int) $thumb[2] : 0,
				'categories'       => $this->term_names( $post, 'category', $incoming ),
				'tags'             => $this->term_names( $post, 'post_tag', $incoming ),
				'published_ts'     => (int) strtotime( $post->post_date_gmt . ' UTC' ),
				'modified_ts'      => (int) strtotime( $post->post_modified_gmt . ' UTC' ),
				'post_type'        => (string) $post->post_type,
				'status'           => (string) $post->post_status,
				'author_id'        => $author_id,
				'protected'        => '' !== (string) $post->post_password,
				'summary'          => (string) apply_filters( 'estasoft_nd_internal_article_summary', '', $post ),
			)
		);
	}

	/**
	 * The names of an article's terms in one taxonomy: the ones the save asks for, else the saved ones.
	 *
	 * @param \WP_Post             $post     The article.
	 * @param string               $taxonomy `category` or `post_tag`.
	 * @param array<string, mixed> $incoming What the save carries.
	 * @return string[]
	 */
	private function term_names( \WP_Post $post, string $taxonomy, array $incoming ): array {
		$asked = null;

		if ( 'category' === $taxonomy && isset( $incoming['post_category'] ) && is_array( $incoming['post_category'] ) ) {
			$asked = $incoming['post_category'];
		} elseif ( isset( $incoming['tax_input'] ) && is_array( $incoming['tax_input'] ) && isset( $incoming['tax_input'][ $taxonomy ] ) ) {
			$asked = $incoming['tax_input'][ $taxonomy ];
			$asked = is_array( $asked ) ? $asked : explode( ',', (string) $asked );
		}

		if ( null === $asked ) {
			$terms = (int) $post->ID > 0 ? get_the_terms( $post, $taxonomy ) : array();

			return is_array( $terms ) ? array_values( array_map( static fn( $term ): string => (string) $term->name, $terms ) ) : array();
		}

		$names = array();

		foreach ( $asked as $term ) {
			$term = is_scalar( $term ) ? trim( (string) $term ) : '';

			if ( '' === $term || '0' === $term || '-1' === $term ) {
				continue;
			}

			if ( ctype_digit( $term ) ) {
				$found = get_term( (int) $term, $taxonomy );
				$term  = $found instanceof \WP_Term ? $found->name : '';
			}

			if ( '' !== $term ) {
				$names[] = $term;
			}
		}

		return $names;
	}

	/**
	 * What the request says about publishing anyway: whether it asked, and the reason, cleaned.
	 *
	 * @param string|null $sent    The override header as sent, or null to read this request.
	 * @param int         $post_id The article being saved, for the editor's own nonce.
	 * @return array{present: bool, text: string}
	 */
	private function read_override( ?string $sent, int $post_id ): array {
		$text    = '';
		$present = false;

		if ( null === $sent && isset( $_SERVER['HTTP_X_ESTASOFT_ND_OVERRIDE'] ) ) {
			// Still percent-encoded here: only control characters go, so the decoding below sees the reason as it was sent.
			$sent = (string) filter_var( wp_unslash( $_SERVER['HTTP_X_ESTASOFT_ND_OVERRIDE'] ), FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_LOW );
		}

		if ( null !== $sent && '' !== trim( $sent ) ) {
			$text    = trim( rawurldecode( $sent ) );
			$text    = '-' === $text ? '' : $text;
			$present = true;
		}

		// The classic editor posts the reason in its own form. It is read only from a form that carries the editor's nonce for this article.
		$from_editor = $post_id > 0 && isset( $_POST['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'update-post_' . $post_id );

		if ( ! $present && $from_editor && isset( $_POST[ self::OVERRIDE_FIELD ] ) ) {
			$text    = sanitize_textarea_field( wp_unslash( $_POST[ self::OVERRIDE_FIELD ] ) );
			$present = '' !== trim( $text );
		}

		if ( ! $present && $from_editor && ! empty( $_POST[ self::ANYWAY_FIELD ] ) ) {
			$present = true;
		}

		return array(
			'present' => $present,
			'text'    => $this->clean_reason( $text ),
		);
	}

	/**
	 * A typed reason with tags and control characters removed, at most MAX_REASON characters.
	 *
	 * @param string $reason What was typed.
	 * @return string
	 */
	private function clean_reason( string $reason ): string {
		return trim( mb_substr( sanitize_textarea_field( $reason ), 0, self::MAX_REASON ) );
	}

	/**
	 * Writes the row.
	 *
	 * @param int      $post_id Article ID.
	 * @param string   $reason  The reason, cleaned.
	 * @param string[] $failing Names of the channels that did not pass.
	 * @param int      $user_id Person.
	 * @return void
	 */
	private function write_override_row( int $post_id, string $reason, array $failing, int $user_id ): void {
		$this->plugin->audit()->record(
			Audit::GATE_OVERRIDE,
			$post_id,
			sprintf(
				/* translators: 1: channel names that failed such as "Google News", 2: the reason the person typed. */
				__( 'Published anyway. Failing: %1$s. Reason: %2$s', 'estasoft-news-distribution' ),
				array() === $failing ? __( 'nothing was listed', 'estasoft-news-distribution' ) : implode( ', ', $failing ),
				'' === $reason ? __( '(none given)', 'estasoft-news-distribution' ) : $reason
			),
			$user_id
		);
	}

	/**
	 * The save goes through unchecked, and the audit table says why.
	 *
	 * @param array<string, mixed> $result  The answer so far.
	 * @param string               $why     `error` or `slow`.
	 * @param int                  $post_id Article ID, or 0.
	 * @param string               $detail  The error's message, or the seconds the check took.
	 * @return array<string, mixed>
	 */
	private function let_through( array $result, string $why, int $post_id, string $detail ): array {
		$result['skipped'] = $why;

		$this->plugin->audit()->record(
			Audit::GATE_SKIPPED,
			$post_id,
			'slow' === $why
				? sprintf(
					/* translators: %s: seconds the check took, such as 3.4. */
					__( 'The pre-publish gate took %s seconds, which is too long, so it let the article through.', 'estasoft-news-distribution' ),
					$detail
				)
				: sprintf(
					/* translators: %s: the error the check ran into. */
					__( 'The pre-publish gate could not run its checks, so it let the article through. The error was: %s', 'estasoft-news-distribution' ),
					$detail
				)
		);

		return $result;
	}

	/**
	 * Channel names for channel ids. An empty list means every channel.
	 *
	 * @param string[] $ids Channel ids.
	 * @return string[]
	 */
	private function labels( array $ids ): array {
		$out = array();

		foreach ( $ids as $id ) {
			$channel = $this->plugin->registry()->get( (string) $id );
			$out[]   = null === $channel ? (string) $id : $channel->label();
		}

		return $out;
	}

	/**
	 * The article, if it exists and is of a type the plugin sends.
	 *
	 * @param int $post_id Article ID.
	 * @return \WP_Post|\WP_Error
	 */
	private function post( int $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, $this->plugin->settings()->post_types(), true ) ) {
			return $this->fail( 'estasoft_nd_unknown_article', __( 'That article does not exist.', 'estasoft-news-distribution' ), 404 );
		}

		return $post;
	}

	/**
	 * A plain-language error.
	 *
	 * @param string   $code    Stable code.
	 * @param string   $message Sentence the screen can show as written.
	 * @param int      $status  HTTP status.
	 * @param string[] $fields  Fields the message is about.
	 * @return \WP_Error
	 */
	private function fail( string $code, string $message, int $status, array $fields = array() ): \WP_Error {
		return new \WP_Error( $code, $message, array_merge( array( 'status' => $status ), array() === $fields ? array() : array( 'fields' => $fields ) ) );
	}
}
