<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtReverseConnect\Event;

use PhpOpcua\Client\ExtReverseConnect\ReverseHelloMessage;

/**
 * Dispatched immediately after a ReverseHello frame has been successfully
 * decoded, *before* the whitelist validator inspects it. Listeners attached
 * to this event observe every parseable RHE, including those that will be
 * rejected.
 */
final readonly class ReverseHelloReceived
{
    public function __construct(
        public ReverseHelloMessage $message,
    ) {
    }
}
