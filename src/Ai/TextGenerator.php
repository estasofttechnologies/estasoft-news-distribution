<?php
/**
 * Something that turns a prompt into text.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * The one door to a provider. `WpTextGenerator` is the real one and uses the AI Client that
 * WordPress 7.0 ships. Tests replace it through the filter `estasoft_nd_internal_ai_generator`
 * so nothing leaves the machine.
 *
 * `Client::ask()` is the only caller. It has already checked that the assistant is on, that
 * consent is recorded and that no limit is reached.
 */
interface TextGenerator {

	/**
	 * Generates text.
	 *
	 * The request array has: `system` (string), `prompt` (string), `models` (string[], preferred
	 * model names, best first), `max_tokens` (int), `image` (a path to a local image file, or an
	 * empty string).
	 *
	 * @param array<string, mixed> $request The request.
	 * @return array{text: string, provider: string, model: string, tokens: int, tokens_in?: int, tokens_out?: int}|\WP_Error
	 */
	public function generate( array $request );
}
