<?php

declare(strict_types=1);

use PhpOpcua\Client\ExtReverseConnect\Exception\ReverseHelloParseException;
use PhpOpcua\Client\ExtReverseConnect\ReverseHelloMessage;
use PhpOpcua\Client\ExtReverseConnect\ReverseHelloParser;

/**
 * Build a syntactically valid RHE frame for the given URIs.
 */
function rcFrame(string $serverUri, string $endpointUrl, ?int $overrideSize = null): string
{
    $payload = pack('V', strlen($serverUri)) . $serverUri
        . pack('V', strlen($endpointUrl)) . $endpointUrl;
    $size = $overrideSize ?? (8 + strlen($payload));

    return 'RHE' . 'F' . pack('V', $size) . $payload;
}

describe('ReverseHelloParser', function () {

    it('parses a valid frame with non-empty ServerUri and EndpointUrl', function () {
        $frame = rcFrame('urn:test-server:opcua', 'opc.tcp://192.168.1.10:4840');

        $msg = ReverseHelloParser::parse($frame);

        expect($msg)->toBeInstanceOf(ReverseHelloMessage::class);
        expect($msg->serverUri)->toBe('urn:test-server:opcua');
        expect($msg->endpointUrl)->toBe('opc.tcp://192.168.1.10:4840');
    });

    it('parses a frame with empty ServerUri and EndpointUrl strings', function () {
        $frame = rcFrame('', '');

        $msg = ReverseHelloParser::parse($frame);

        expect($msg->serverUri)->toBe('');
        expect($msg->endpointUrl)->toBe('');
    });

    it('normalises OPC UA null strings (length = -1) to empty strings', function () {
        $payload = pack('l', -1) . pack('l', -1);
        $frame = 'RHE' . 'F' . pack('V', 8 + strlen($payload)) . $payload;

        $msg = ReverseHelloParser::parse($frame);

        expect($msg->serverUri)->toBe('');
        expect($msg->endpointUrl)->toBe('');
    });

    it('rejects a frame shorter than MIN_FRAME_SIZE', function () {
        expect(fn () => ReverseHelloParser::parse('RHEF'))
            ->toThrow(ReverseHelloParseException::class, 'too short');
    });

    it('rejects a frame whose MessageType is not "RHE"', function () {
        $frame = 'HEL' . 'F' . pack('V', 16) . pack('l', 0) . pack('l', 0);

        expect(fn () => ReverseHelloParser::parse($frame))
            ->toThrow(ReverseHelloParseException::class, 'Expected MessageType "RHE"');
    });

    it('escapes non-printable bytes in the MessageType error message', function () {
        $frame = "\x01\x02\x03" . 'F' . pack('V', 16) . pack('l', 0) . pack('l', 0);

        expect(fn () => ReverseHelloParser::parse($frame))
            ->toThrow(ReverseHelloParseException::class, '\\x01\\x02\\x03');
    });

    it('rejects a frame whose ChunkType is not "F"', function () {
        $frame = 'RHE' . 'C' . pack('V', 16) . pack('l', 0) . pack('l', 0);

        expect(fn () => ReverseHelloParser::parse($frame))
            ->toThrow(ReverseHelloParseException::class, 'Expected ChunkType "F"');
    });

    it('rejects a frame whose declared MessageSize is below the minimum', function () {
        $frame = rcFrame('', '', overrideSize: 12);

        expect(fn () => ReverseHelloParser::parse($frame))
            ->toThrow(ReverseHelloParseException::class, 'below the minimum');
    });

    it('rejects a frame whose declared MessageSize exceeds the configured maximum', function () {
        $frame = rcFrame('a', 'b');

        expect(fn () => ReverseHelloParser::parse($frame, maxFrameSize: 8))
            ->toThrow(ReverseHelloParseException::class, 'exceeds the configured maximum');
    });

    it('rejects a frame whose MessageSize does not match the received length', function () {
        $frame = rcFrame('urn:x', 'opc.tcp://h:1', overrideSize: 999);

        expect(fn () => ReverseHelloParser::parse($frame))
            ->toThrow(ReverseHelloParseException::class, 'does not match received length');
    });

    it('rejects a frame with a String length pointing past the end of the payload', function () {
        $payload = pack('V', 99) . 'urn:x' . pack('V', 0);
        $frame = 'RHE' . 'F' . pack('V', 8 + strlen($payload)) . $payload;

        expect(fn () => ReverseHelloParser::parse($frame))
            ->toThrow(ReverseHelloParseException::class, 'Malformed OPC UA String');
    });

    it('rejects a frame with trailing bytes after the EndpointUrl', function () {
        $payload = pack('V', 0) . pack('V', 0) . 'TRAILING';
        $frame = 'RHE' . 'F' . pack('V', 8 + strlen($payload)) . $payload;

        expect(fn () => ReverseHelloParser::parse($frame))
            ->toThrow(ReverseHelloParseException::class, 'Trailing 8 byte');
    });

    it('preserves UTF-8 multi-byte sequences in the URIs', function () {
        $serverUri = 'urn:test:fé€';
        $endpointUrl = 'opc.tcp://hôst.example:4840/path-è';
        $frame = rcFrame($serverUri, $endpointUrl);

        $msg = ReverseHelloParser::parse($frame);

        expect($msg->serverUri)->toBe($serverUri);
        expect($msg->endpointUrl)->toBe($endpointUrl);
    });
});
