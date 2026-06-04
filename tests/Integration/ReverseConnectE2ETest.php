<?php

declare(strict_types=1);

use PhpOpcua\Client\Client;
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Exception\ServiceException;
use PhpOpcua\Client\ExtReverseConnect\Event\ReverseConnectAccepted;
use PhpOpcua\Client\ExtReverseConnect\Event\ReverseHelloReceived;
use PhpOpcua\Client\ExtReverseConnect\Exception\ReverseConnectRejectedException;
use PhpOpcua\Client\ExtReverseConnect\Exception\ReverseConnectTimeoutException;
use PhpOpcua\Client\ExtReverseConnect\ReverseConnectClientFactory;
use PhpOpcua\Client\ExtReverseConnect\ReverseConnectListener;
use PhpOpcua\Client\ExtReverseConnect\ReverseHelloValidator;
use PhpOpcua\Client\ExtReverseConnect\Tests\Unit\Helpers\InMemoryEventDispatcher;
use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\ReferenceDescription;
use PhpOpcua\Client\Types\StatusCode;
use PhpOpcua\Client\Types\Variant;

/**
 * End-to-end Reverse Connect (Part 6 §7.1.2.3) tests against UA-.NETStandard
 * via the uanetstandard-test-suite container (opcua-no-security, port 4840).
 *
 * Each test:
 *   1. Opens a local listener on 0.0.0.0:0 (kernel-assigned port) so the
 *      server can reach it via host.docker.internal from inside its container.
 *   2. Connects normally to the server (no security).
 *   3. Calls TestServer/ReverseConnect/StartReverseConnect(host, port).
 *   4. Awaits the inbound RHE on the listener.
 *   5. Cleans up by calling StopReverseConnect on the trigger connection.
 *
 * UA-.NETStandard's ReverseConnectManager attempts the outbound connection
 * shortly after AddReverseConnection() returns; the accept() budget of 20s
 * is generous enough to absorb the timer tick on a slow CI host.
 */
const RC_ENDPOINT = 'opc.tcp://localhost:4840/UA/TestServer';

const RC_EXPECTED_SERVER_URI = 'urn:opcua:testserver:nodes';

/**
 * Connect to the trigger server, retrying through the server's boot window.
 *
 * A freshly started UA-.NETStandard server briefly answers with a top-level
 * ServiceFault BadServerHalted (0x800E0000) — or refuses the TCP connection —
 * until its `ServerInternal` reaches the Running state. The test-suite
 * healthcheck gates on a readiness marker, but we retry here as well so the
 * integration suite never flakes on the startup race.
 */
function rcConnectTriggerClient(): Client
{
    $deadline = microtime(true) + 15.0;
    $lastError = null;
    do {
        try {
            return (new ClientBuilder())
                ->setSecurityPolicy(SecurityPolicy::None)
                ->setSecurityMode(SecurityMode::None)
                ->connect(RC_ENDPOINT);
        } catch (ServiceException|ConnectionException $e) {
            $lastError = $e;
            usleep(250_000);
        }
    } while (microtime(true) < $deadline);

    throw $lastError;
}

function rcBrowseToNode(Client $client, array $path): NodeId
{
    $current = NodeId::numeric(0, 85);
    foreach ($path as $name) {
        $refs = $client->browse($current);
        $matched = null;
        foreach ($refs as $ref) {
            if ($ref->getBrowseName()->getName() === $name) {
                $matched = $ref->getNodeId();
                break;
            }
        }
        if ($matched === null) {
            throw new RuntimeException("Browse path step '{$name}' not found");
        }
        $current = $matched;
    }

    return $current;
}

function rcFindRef(array $refs, string $name): ?ReferenceDescription
{
    foreach ($refs as $ref) {
        if ($ref->getBrowseName()->getName() === $name) {
            return $ref;
        }
    }

    return null;
}

function rcCallStart(Client $trigger, string $host, int $port): void
{
    $folder = rcBrowseToNode($trigger, ['TestServer', 'ReverseConnect']);
    $refs = $trigger->browse($folder);
    $startRef = rcFindRef($refs, 'StartReverseConnect');
    if ($startRef === null) {
        throw new RuntimeException('StartReverseConnect method not found on the server — is uanetstandard-test-suite v1.4.0+ running?');
    }

    $result = $trigger->call(
        $folder,
        $startRef->getNodeId(),
        [
            new Variant(BuiltinType::String, $host),
            new Variant(BuiltinType::UInt16, $port),
        ],
    );

    if (! StatusCode::isGood($result->statusCode)) {
        throw new RuntimeException(
            sprintf('StartReverseConnect returned non-Good status: 0x%08X', $result->statusCode),
        );
    }
    if (count($result->outputArguments) > 0) {
        $inner = $result->outputArguments[0]->getValue();
        if (is_int($inner) && ! StatusCode::isGood($inner)) {
            throw new RuntimeException(
                sprintf('StartReverseConnect inner status non-Good: 0x%08X', $inner),
            );
        }
    }
}

function rcCallStop(Client $trigger, string $host, int $port): void
{
    try {
        $folder = rcBrowseToNode($trigger, ['TestServer', 'ReverseConnect']);
        $refs = $trigger->browse($folder);
        $stopRef = rcFindRef($refs, 'StopReverseConnect');
        if ($stopRef !== null) {
            $trigger->call(
                $folder,
                $stopRef->getNodeId(),
                [
                    new Variant(BuiltinType::String, $host),
                    new Variant(BuiltinType::UInt16, $port),
                ],
            );
        }
    } catch (Throwable) {
        // best-effort cleanup
    }
}

describe('Reverse Connect end-to-end against UA-.NETStandard', function () {

    it('accepts a ReverseHello from the server and reports the announced ServerUri', function () {
        $dispatcher = new InMemoryEventDispatcher();
        $validator = new ReverseHelloValidator([RC_EXPECTED_SERVER_URI]);
        $listener = new ReverseConnectListener('0.0.0.0', 0, $validator, dispatcher: $dispatcher);
        $listener->listen();
        [, $port] = explode(':', $listener->getBindAddress());
        $port = (int) $port;

        $trigger = null;
        $session = null;
        try {
            $trigger = rcConnectTriggerClient();
            rcCallStart($trigger, 'host.docker.internal', $port);

            $session = $listener->accept(timeoutSeconds: 20.0);

            expect($session->serverUri)->toBe(RC_EXPECTED_SERVER_URI);
            expect($session->endpointUrl)->toStartWith('opc.tcp://');
            expect(is_resource($session->socket))->toBeTrue();

            expect($dispatcher->hasEvent(ReverseHelloReceived::class))->toBeTrue();
            expect($dispatcher->hasEvent(ReverseConnectAccepted::class))->toBeTrue();
        } finally {
            if ($session !== null) {
                @fclose($session->socket);
            }
            if ($trigger !== null) {
                rcCallStop($trigger, 'host.docker.internal', $port);
                $trigger->disconnect();
            }
            $listener->close();
        }
    })->group('integration');

    it('rejects an RHE whose ServerUri is not in the whitelist', function () {
        $dispatcher = new InMemoryEventDispatcher();
        $validator = new ReverseHelloValidator(['urn:not:the:server']);
        $listener = new ReverseConnectListener('0.0.0.0', 0, $validator, dispatcher: $dispatcher);
        $listener->listen();
        [, $port] = explode(':', $listener->getBindAddress());
        $port = (int) $port;

        $trigger = null;
        try {
            $trigger = rcConnectTriggerClient();
            rcCallStart($trigger, 'host.docker.internal', $port);

            try {
                $listener->accept(timeoutSeconds: 20.0);
                $this->fail('Expected ReverseConnectRejectedException because the ServerUri is not in the whitelist');
            } catch (ReverseConnectRejectedException $e) {
                expect($e->rejectedMessage->serverUri)->toBe(RC_EXPECTED_SERVER_URI);
                expect($e->getMessage())->toContain('not in the configured whitelist');
            }
        } finally {
            if ($trigger !== null) {
                rcCallStop($trigger, 'host.docker.internal', $port);
                $trigger->disconnect();
            }
            $listener->close();
        }
    })->group('integration');

    it('times out cleanly when no StartReverseConnect is issued', function () {
        $listener = new ReverseConnectListener(
            '127.0.0.1',
            0,
            new ReverseHelloValidator([RC_EXPECTED_SERVER_URI]),
        );
        $listener->listen();

        try {
            expect(fn () => $listener->accept(timeoutSeconds: 0.4))
                ->toThrow(ReverseConnectTimeoutException::class);
        } finally {
            $listener->close();
        }
    })->group('integration');

    // Beyond this point the listener has to be reachable from inside the
    // server container. The test calls StartReverseConnect with the host's
    // docker-internal alias.
    //
    // We need the docker-compose stack to be running with
    //   extra_hosts: ["host.docker.internal:host-gateway"]
    // on the opcua-no-security service (added in uanetstandard-test-suite
    // v1.4.0).

    it('hands a fully connected Client to the caller via the factory', function () {
        $validator = new ReverseHelloValidator([RC_EXPECTED_SERVER_URI]);
        // Bind 0.0.0.0 (not loopback): in CI the server dials the listener via
        // host.docker.internal, which maps to the Docker bridge gateway IP — a
        // 127.0.0.1-bound listener is unreachable from inside the container.
        $listener = new ReverseConnectListener('0.0.0.0', 0, $validator);
        $listener->listen();
        [, $port] = explode(':', $listener->getBindAddress());
        $port = (int) $port;

        $trigger = null;
        $rcClient = null;
        try {
            $trigger = rcConnectTriggerClient();
            rcCallStart($trigger, 'host.docker.internal', $port);

            $session = $listener->accept(timeoutSeconds: 20.0);

            $rcClient = (new ReverseConnectClientFactory())->buildClient(
                $session,
                static function (ClientBuilder $b) {
                    $b->setSecurityPolicy(SecurityPolicy::None)
                        ->setSecurityMode(SecurityMode::None);
                },
            );

            expect($rcClient)->toBeInstanceOf(Client::class);
            expect($rcClient->isConnected())->toBeTrue();
        } finally {
            if ($rcClient !== null) {
                $rcClient->disconnect();
            }
            if ($trigger !== null) {
                rcCallStop($trigger, 'host.docker.internal', $port);
                $trigger->disconnect();
            }
            $listener->close();
        }
    })->group('integration');
});
