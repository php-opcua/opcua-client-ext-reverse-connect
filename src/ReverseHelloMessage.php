<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtReverseConnect;

/**
 * Decoded payload of an OPC UA ReverseHello (RHE) frame.
 *
 * The wire format is defined in OPC UA Part 6 §7.1.2.3: an 8-byte standard
 * header (MessageType `"RHE"`, ChunkType `"F"`, MessageSize as UInt32 LE)
 * followed by two OPC UA String fields — `ServerUri` (the application URI
 * announced by the server) and `EndpointUrl` (the endpoint to use in the
 * subsequent `CreateSession` request).
 *
 * Instances are immutable. Both fields are non-null after a successful
 * {@see ReverseHelloParser::parse()} — the parser converts the OPC UA
 * "null string" (length = -1) into the empty string before constructing the
 * DTO, since per Part 6 a Reverse Connect handshake without a usable
 * EndpointUrl is meaningless.
 *
 * @see https://reference.opcfoundation.org/Core/Part6/v105/docs/7.1.2.3
 */
final readonly class ReverseHelloMessage
{
    /**
     * @param string $serverUri Application URI of the announcing server.
     * @param string $endpointUrl Endpoint URL the client must use for the
     *                            subsequent UA-TCP handshake.
     */
    public function __construct(
        public string $serverUri,
        public string $endpointUrl,
    ) {
    }
}
