<?php
declare(strict_types=1);

namespace Tainacan\AI\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects user-visible processing notices (truncation, filtering, limits).
 *
 * @phpstan-type ProcessingWarning array{
 *     code: string,
 *     severity: 'info'|'warning',
 *     message: string,
 *     details?: array<string, int|string|bool>
 * }
 */
final class ProcessingWarnings {

	/** @var list<ProcessingWarning> */
	private array $warnings = array();

	/**
	 * @param array<string, int|string|bool> $details
	 */
	public function add( string $code, string $severity, string $message, array $details = array() ): void {
		foreach ( $this->warnings as $existing ) {
			if ( $existing['code'] === $code ) {
				return;
			}
		}

		$warning = array(
			'code'     => $code,
			'severity' => $severity === 'info' ? 'info' : 'warning',
			'message'  => $message,
		);

		if ( $details !== array() ) {
			$warning['details'] = $details;
		}

		$this->warnings[] = $warning;
	}

	public function merge( self $other ): void {
		foreach ( $other->to_list() as $warning ) {
			$this->add(
				$warning['code'],
				$warning['severity'],
				$warning['message'],
				$warning['details'] ?? array()
			);
		}
	}

	public function is_empty(): bool {
		return $this->warnings === array();
	}

	/**
	 * @return list<ProcessingWarning>
	 */
	public function to_list(): array {
		return $this->warnings;
	}

	/**
	 * @return array{warnings: list<ProcessingWarning>}|null
	 */
	public function to_payload(): ?array {
		if ( $this->is_empty() ) {
			return null;
		}

		return array(
			'warnings' => $this->warnings,
		);
	}
}
