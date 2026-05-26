<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtReverseConnect;

use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Exception\EncodingException;
use PhpOpcua\Client\ExtReverseConnect\Exception\ReverseHelloParseException;

/**
 * Decoder for OPC UA ReverseHello (`RHE`) frames (Part 6 §7.1.2.3).
 *
 * Pure binary parser — no I/O. The listener is responsible for reading the
 * frame from the wire and feeding the bytes to {@see parse()}.
 *
 * Wire layout:
 *
 *     +--------+--------+----------------+-------------+--------------+
 *     | "RHE"  |  "F"   |   MessageSize  |  ServerUri  |  EndpointUrl |
 *     | 3 byte | 1 byte |  4 byte UInt32 |  OPC UA Str |  OPC UA Str  |
 *     +--------+--------+----------------+-------------+--------------+
 *
 * Where each OPC UA String is encoded as an Int32 length prefix (LE) followed
 * by the UTF-8 bytes; length `-1` means "null string" and is normalised to
 * the empty string by this parser.
 *
 * @see https://reference.opcfoundation.org/Core/Part6/v105/docs/7.1.2.3
 */
final class ReverseHelloParser
{
    /**
     * Minimum frame size: 8-byte header + 2 length-prefixed empty strings.
     */
    public const MIN_FRAME_SIZE = 16;

    /**
     * Default upper bound on a single RHE frame. Kept conservative — the
     * payload only carries two URI/URL strings and can be safely capped well
     * below the UA-TCP receive buffer.
     */
    public const DEFAULT_MAX_FRAME_SIZE = 65535;

    /**
     * Decode a ReverseHello frame.
     *
     * @param string $frame The full RHE frame, header included.
     * @param int $maxFrameSize Hard upper bound. A frame whose declared
     *                          `MessageSize` exceeds this value is rejected
     *                          before any further parsing.
     * @return ReverseHelloMessage
     *
     * @throws ReverseHelloParseException If any structural rule is violated.
     */
    public static function parse(string $frame, int $maxFrameSize = self::DEFAULT_MAX_FRAME_SIZE): ReverseHelloMessage
    {
        $length = strlen($frame);

        if ($length < self::MIN_FRAME_SIZE) {
            throw new ReverseHelloParseException(
                "ReverseHello frame too short: got {$length} bytes, expected at least " . self::MIN_FRAME_SIZE,
            );
        }

        $messageType = substr($frame, 0, 3);
        if ($messageType !== 'RHE') {
            throw new ReverseHelloParseException(
                sprintf('Expected MessageType "RHE", got %s', self::quoteAscii($messageType)),
            );
        }

        $chunkType = $frame[3];
        if ($chunkType !== 'F') {
            throw new ReverseHelloParseException(
                sprintf('Expected ChunkType "F", got %s', self::quoteAscii($chunkType)),
            );
        }

        $unpacked = unpack('V', substr($frame, 4, 4));
        $messageSize = $unpacked[1];

        if ($messageSize < self::MIN_FRAME_SIZE) {
            throw new ReverseHelloParseException(
                "Declared MessageSize {$messageSize} is below the minimum frame size " . self::MIN_FRAME_SIZE,
            );
        }

        if ($messageSize > $maxFrameSize) {
            throw new ReverseHelloParseException(
                "Declared MessageSize {$messageSize} exceeds the configured maximum {$maxFrameSize}",
            );
        }

        if ($messageSize !== $length) {
            throw new ReverseHelloParseException(
                "Declared MessageSize {$messageSize} does not match received length {$length}",
            );
        }

        $payload = substr($frame, 8);
        $decoder = new BinaryDecoder($payload);

        try {
            $serverUri = $decoder->readString() ?? '';
            $endpointUrl = $decoder->readString() ?? '';
        } catch (EncodingException $e) {
            throw new ReverseHelloParseException(
                'Malformed OPC UA String in ReverseHello payload: ' . $e->getMessage(),
                previous: $e,
            );
        }

        if ($decoder->getRemainingLength() !== 0) {
            throw new ReverseHelloParseException(
                sprintf(
                    'Trailing %d byte(s) after EndpointUrl in ReverseHello frame',
                    $decoder->getRemainingLength(),
                ),
            );
        }

        return new ReverseHelloMessage($serverUri, $endpointUrl);
    }

    /**
     * Render the given bytes as a quoted ASCII-safe representation for error
     * messages, so binary garbage in MessageType/ChunkType doesn't corrupt
     * the message log.
     */
    private static function quoteAscii(string $bytes): string
    {
        $escaped = '';
        $length = strlen($bytes);
        for ($i = 0; $i < $length; $i++) {
            $byte = ord($bytes[$i]);
            if ($byte >= 0x20 && $byte < 0x7F && $bytes[$i] !== '"' && $bytes[$i] !== '\\') {
                $escaped .= $bytes[$i];

                continue;
            }
            $escaped .= sprintf('\\x%02X', $byte);
        }

        return '"' . $escaped . '"';
    }
}
