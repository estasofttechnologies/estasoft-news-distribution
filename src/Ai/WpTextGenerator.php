<?php
/**
 * Generates text through the AI Client in WordPress 7.0 and newer.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * Calls `wp_ai_client_prompt()` and `generate_text_result()`, which WordPress documents as the way
 * a plugin asks for text. The provider, the model and the key are WordPress's: this class never reads
 * a key and never names a provider unless the owner listed a preferred model.
 *
 * It is only ever called by `Client::ask()`.
 */
final class WpTextGenerator implements TextGenerator {

	/**
	 * @param array<string, mixed> $request See TextGenerator.
	 * @return array{text: string, provider: string, model: string, tokens: int, tokens_in: int, tokens_out: int}|\WP_Error
	 */
	public function generate( array $request ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new \WP_Error( 'estasoft_nd_ai_unsupported', __( 'AI features need WordPress 7.0 or newer.', 'estasoft-news-distribution' ), array( 'status' => 404 ) );
		}

		try {
			$builder = wp_ai_client_prompt( (string) $request['prompt'] );

			if ( '' !== (string) ( $request['system'] ?? '' ) ) {
				$builder = $builder->using_system_instruction( (string) $request['system'] );
			}

			$models = array_values( array_filter( array_map( 'strval', (array) ( $request['models'] ?? array() ) ) ) );

			if ( array() !== $models ) {
				$builder = $builder->using_model_preference( ...$models );
			}

			if ( (int) ( $request['max_tokens'] ?? 0 ) > 0 ) {
				$builder = $builder->using_max_tokens( (int) $request['max_tokens'] );
			}

			$image = (string) ( $request['image'] ?? '' );

			if ( '' !== $image && is_readable( $image ) ) {
				$builder = $builder->with_file( $image );
			}

			$result = $builder->generate_text_result();

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$usage = $result->getTokenUsage();

			return array(
				'text'       => (string) $result->toText(),
				'provider'   => (string) $result->getProviderMetadata()->getId(),
				'model'      => (string) $result->getModelMetadata()->getId(),
				'tokens'     => (int) $usage->getTotalTokens(),
				'tokens_in'  => (int) $usage->getPromptTokens(),
				'tokens_out' => (int) $usage->getCompletionTokens(),
			);
		} catch ( \Throwable $e ) {
			// The message can quote the provider, so it is not passed on.
			return new \WP_Error( 'prompt_builder_error', 'The AI Client could not finish the request.', array( 'status' => 500 ) );
		}
	}
}
