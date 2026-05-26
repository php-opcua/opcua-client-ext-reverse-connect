<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtReverseConnect\Exception;

use PhpOpcua\Client\ExtReverseConnect\ReverseHelloMessage;

/**
 * Raised when a syntactically valid ReverseHello frame is rejected by the
 * validator — typically because the announced `ServerUri` is not in the
 * configured whitelist or the `EndpointUrl` does not use a supported scheme.
 *
 * Carries the rejected message so an event listener / log line can include
 * the original payload without having to re-parse the wire bytes.
 */
class ReverseConnectRejectedException extends ReverseConnectException
{
    public function __construct(
        string $message,
        public readonly ReverseHelloMessage $rejectedMessage,
    ) {
        parent::__construct($message);
    }
}
