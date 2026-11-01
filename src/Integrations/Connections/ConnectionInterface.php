<?php
/**
 * A search engine account News Distribution can read sitemap status from.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Integrations\Connections;

defined( 'ABSPATH' ) || exit;

/**
 * One class per engine, in this folder. It is found by folder and used by
 * `Core\Connections`. A connection contacts the engine only when a person
 * clicks Connect or Check now: both go through `Core\Connections`, which never
 * runs either on a schedule or on publish.
 *
 * Each engine is documented in docs/external-services/<id>.md.
 *
 * A row of `sync()` has this shape:
 *
 *     array(
 *       'label'  => string,   // "Search sitemap, English"
 *       'url'    => string,   // the sitemap address
 *       'says'   => string,   // the engine's own facts, in words
 *       'status' => string,   // a Core\Status code: HEALTHY, PROCESSING or ATTENTION
 *       'link'   => string,   // where to read the details at the engine, or ''
 *     )
 */
interface ConnectionInterface {

	/**
	 * Short id, such as "yandex". Also the name of the docs file's service.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * The engine's name as it writes it.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * The settings field of the Connections section that holds the credential. Always a secret field.
	 *
	 * @return string
	 */
	public function secret_field(): string;

	/**
	 * The plain fields of the Connections section this engine also needs.
	 *
	 * @return string[]
	 */
	public function plain_fields(): array;

	/**
	 * The channels whose sitemaps this engine is given, by channel id.
	 *
	 * @return string[]
	 */
	public function channel_ids(): array;

	/**
	 * Tests a credential, without saving it.
	 *
	 * @param string               $credential The token, key or key file the person gave.
	 * @param array<string, mixed> $settings   The plain fields (see `plain_fields()`), with defaults filled in.
	 * @return array{ok: bool, message: string, account: string, context: array<string, mixed>} `account` names who the credential belongs to, or is empty. `context` is kept with the state and given back to `sync()`.
	 */
	public function verify( string $credential, array $settings ): array;

	/**
	 * Submits the sitemaps the engine does not have yet, then reads what it shows for each.
	 *
	 * @param string                                        $credential The saved credential.
	 * @param array<string, mixed>                          $settings   The plain fields, with defaults filled in.
	 * @param array<string, mixed>                          $context    What `verify()` returned.
	 * @param array<int, array{label: string, url: string}> $sitemaps   The sitemaps to submit.
	 * @return array{ok: bool, message: string, rows: array<int, array{label: string, url: string, says: string, status: string, link: string}>}
	 */
	public function sync( string $credential, array $settings, array $context, array $sitemaps ): array;
}
