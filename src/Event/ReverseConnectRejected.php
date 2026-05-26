<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtReverseConnect\Event;

use PhpOpcua\Client\ExtReverseConnect\ReverseHelloMessage;

/**
 * Dispatched when the validator refuses a syntactically valid ReverseHello.
 * Carries the rejection reason as a human-readable string so consumers can
 * log it without re-running the validator.
 */
final readonly class ReverseConnectRejected
{
    public function __construct(
        public ReverseHelloMessage $message,
        public string $reason,
    ) {
    }
}
