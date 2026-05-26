<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtReverseConnect\Exception;

/**
 * Raised when {@see \PhpOpcua\Client\ExtReverseConnect\ReverseConnectListener::accept()}
 * times out before any inbound TCP connection arrives.
 */
class ReverseConnectTimeoutException extends ReverseConnectException
{
}
