<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtReverseConnect;

use PhpOpcua\Client\ExtReverseConnect\Exception\ReverseConnectRejectedException;

/**
 * Validates the contents of a decoded {@see ReverseHelloMessage} against a
 * static whitelist of trusted servers, before the listener hands the socket
 * to the UA-TCP pipeline.
 *
 * This is the security boundary of the Reverse Connect flow: anyone capable
 * of connecting to the listener port can present a ReverseHello, so the
 * client must refuse messages whose `ServerUri` is not explicitly trusted.
 * The validation is performed *before* the OPC UA secure-channel handshake
 * (which would also reject an impostor via certificate validation), giving
 * an extra defence-in-depth layer.
 *
 * Validation rules:
 *  - `ServerUri` must be a non-empty string contained in the whitelist
 *    (exact, case-sensitive match — URIs are case-sensitive per RFC 3986).
 *  - `EndpointUrl` must be non-empty and start with `opc.tcp://`; other
 *    schemes are not currently routable through `TcpTransport`.
 *
 * @see https://reference.opcfoundation.org/Core/Part6/v105/docs/7.1.2.3
 */
final class ReverseHelloValidator
{
    /**
     * @var list<string>
     */
    private readonly array $allowedServerUris;

    /**
     * @param iterable<string> $allowedServerUris Trusted application URIs.
     *                                            An empty whitelist rejects
     *                                            every incoming message
     *                                            (fail-secure default).
     */
    public function __construct(iterable $allowedServerUris)
    {
        $normalised = [];
        foreach ($allowedServerUris as $uri) {
            $normalised[] = $uri;
        }
        $this->allowedServerUris = $normalised;
    }

    /**
     * @return list<string>
     */
    public function getAllowedServerUris(): array
    {
        return $this->allowedServerUris;
    }

    /**
     * Throw if the message violates any rule.
     *
     * @throws ReverseConnectRejectedException
     */
    public function ensureAccepted(ReverseHelloMessage $message): void
    {
        if ($message->serverUri === '') {
            throw new ReverseConnectRejectedException(
                'ReverseHello rejected: ServerUri is empty',
                $message,
            );
        }

        if (! in_array($message->serverUri, $this->allowedServerUris, true)) {
            throw new ReverseConnectRejectedException(
                sprintf(
                    'ReverseHello rejected: ServerUri "%s" is not in the configured whitelist',
                    $message->serverUri,
                ),
                $message,
            );
        }

        if ($message->endpointUrl === '') {
            throw new ReverseConnectRejectedException(
                'ReverseHello rejected: EndpointUrl is empty',
                $message,
            );
        }

        if (! str_starts_with($message->endpointUrl, 'opc.tcp://')) {
            throw new ReverseConnectRejectedException(
                sprintf(
                    'ReverseHello rejected: EndpointUrl "%s" does not use the opc.tcp scheme',
                    $message->endpointUrl,
                ),
                $message,
            );
        }
    }

    /**
     * Non-throwing variant: returns the validation outcome as a bool.
     */
    public function isAccepted(ReverseHelloMessage $message): bool
    {
        try {
            $this->ensureAccepted($message);

            return true;
        } catch (ReverseConnectRejectedException) {
            return false;
        }
    }
}
