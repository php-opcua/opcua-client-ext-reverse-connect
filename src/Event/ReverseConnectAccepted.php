<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtReverseConnect\Event;

use PhpOpcua\Client\ExtReverseConnect\ReverseHelloMessage;

/**
 * Dispatched after the validator has accepted a ReverseHello and the
 * accepted socket has been packaged into a `ReverseConnectSession` ready to
 * hand to the UA-TCP pipeline.
 */
final readonly class ReverseConnectAccepted
{
    public function __construct(
        public ReverseHelloMessage $message,
    ) {
    }
}
