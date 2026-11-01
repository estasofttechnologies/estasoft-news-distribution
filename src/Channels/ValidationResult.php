<?php
/**
 * Outcome of checking one article against a channel.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels;

use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Errors block the article from the channel. Warnings are risks. Infos are tips.
 * They map to the severities `block`, `warn` and `info` in the article check.
 *
 * Every finding has a stable code such as `image.missing`. The codes are the
 * contract between the checks, the REST API, the digest email and fix assignments.
 */
final class ValidationResult {

	/**
	 * @var array<int, array{code: string, message: string, severity: string}>
	 */
	private array $issues = array();

	/**
	 * Adds a finding that makes the channel leave the article out.
	 *
	 * @param string $code    Finding code.
	 * @param string $message One line a person can act on.
	 * @return self
	 */
	public function add_error( string $code, string $message ): self {
		return $this->add( Status::BLOCK, $code, $message );
	}

	/**
	 * Adds a finding the channel accepts, with a risk.
	 *
	 * @param string $code    Finding code.
	 * @param string $message One line a person can act on.
	 * @return self
	 */
	public function add_warning( string $code, string $message ): self {
		return $this->add( Status::WARN, $code, $message );
	}

	/**
	 * Adds a tip.
	 *
	 * @param string $code    Finding code.
	 * @param string $message One line a person can act on.
	 * @return self
	 */
	public function add_info( string $code, string $message ): self {
		return $this->add( Status::INFO, $code, $message );
	}

	/**
	 * Adds a finding at a given severity.
	 *
	 * @param string $severity block, warn or info.
	 * @param string $code     Finding code.
	 * @param string $message  One line a person can act on.
	 * @return self
	 */
	public function add( string $severity, string $code, string $message ): self {
		$this->issues[] = array(
			'code'     => $code,
			'message'  => $message,
			'severity' => $severity,
		);

		return $this;
	}

	/**
	 * Every finding, most severe first.
	 *
	 * @return array<int, array{code: string, message: string, severity: string}>
	 */
	public function findings(): array {
		$order  = array(
			Status::BLOCK => 0,
			Status::WARN  => 1,
			Status::INFO  => 2,
		);
		$issues = $this->issues;

		usort(
			$issues,
			static function ( array $a, array $b ) use ( $order ): int {
				return $order[ $a['severity'] ] <=> $order[ $b['severity'] ];
			}
		);

		return $issues;
	}

	/**
	 * Findings that block.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	public function errors(): array {
		return $this->of( Status::BLOCK );
	}

	/**
	 * Findings that warn.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	public function warnings(): array {
		return $this->of( Status::WARN );
	}

	/**
	 * Findings that are tips.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	public function infos(): array {
		return $this->of( Status::INFO );
	}

	/**
	 * True when nothing blocks the article.
	 *
	 * @return bool
	 */
	public function is_valid(): bool {
		return array() === $this->errors();
	}

	/**
	 * True when the result carries the given finding code at any severity.
	 *
	 * @param string $code Finding code.
	 * @return bool
	 */
	public function has( string $code ): bool {
		foreach ( $this->issues as $issue ) {
			if ( $code === $issue['code'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Findings of one severity, without the severity key.
	 *
	 * @param string $severity block, warn or info.
	 * @return array<int, array{code: string, message: string}>
	 */
	private function of( string $severity ): array {
		$out = array();

		foreach ( $this->issues as $issue ) {
			if ( $severity === $issue['severity'] ) {
				$out[] = array(
					'code'    => $issue['code'],
					'message' => $issue['message'],
				);
			}
		}

		return $out;
	}
}
