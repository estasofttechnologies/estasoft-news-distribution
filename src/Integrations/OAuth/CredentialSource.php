<?php
/**
 * Where a connection gets the bearer token it sends to the engine.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Integrations\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * A connection asks its source for the token to send, and does not care which of three
 * ways the person connected:
 *
 * - `token`: a token pasted in as it is (Yandex).
 * - `key`: a service account key file, traded for a short-lived token (Google).
 * - `oauth`: a sign-in with the person's own app, traded for a short-lived token (Google, Yandex).
 *
 * The source sends no request of its own. It uses the `Transport` of the connection that asked, so
 * the request, the limits and the wording of a failure stay in one place (`AbstractConnection`).
 */
interface CredentialSource {

	/**
	 * Which way: `token`, `key` or `oauth`.
	 *
	 * @return string
	 */
	public function kind(): string;

	/**
	 * Who the credential belongs to, when it says without asking anyone (a service account's email address). Empty otherwise.
	 *
	 * @return string
	 */
	public function account(): string;

	/**
	 * The token to send, or the reason there is none. May ask the provider for a fresh one.
	 *
	 * @param Transport $transport How to send a request, and how to word a failure.
	 * @return array{token: string, error: string} `error` is a sentence for a person, and is empty when there is a token.
	 */
	public function bearer( Transport $transport ): array;
}
