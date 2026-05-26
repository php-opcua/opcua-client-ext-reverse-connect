<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtReverseConnect\Exception;

/**
 * Raised when a ReverseHello (RHE) frame cannot be decoded.
 *
 * Causes include: wrong MessageType (not `"RHE"`), wrong ChunkType (not
 * `"F"`), MessageSize below the minimum header length or above the
 * configured maximum, truncated payload, malformed OPC UA String length
 * prefix.
 */
class ReverseHelloParseException extends ReverseConnectException
{
}
