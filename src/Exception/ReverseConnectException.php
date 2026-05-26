<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtReverseConnect\Exception;

use RuntimeException;

/**
 * Base exception class for all errors emitted by the reverse-connect listener.
 *
 * Catch this to handle any failure of the Reverse Connect flow (parse,
 * validation, timeout, …). Concrete subclasses identify the specific cause.
 */
class ReverseConnectException extends RuntimeException
{
}
