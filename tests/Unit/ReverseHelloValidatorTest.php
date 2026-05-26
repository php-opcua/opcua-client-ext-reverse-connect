<?php

declare(strict_types=1);

use PhpOpcua\Client\ExtReverseConnect\Exception\ReverseConnectRejectedException;
use PhpOpcua\Client\ExtReverseConnect\ReverseHelloMessage;
use PhpOpcua\Client\ExtReverseConnect\ReverseHelloValidator;

describe('ReverseHelloValidator', function () {

    it('accepts a message whose ServerUri is in the whitelist and endpoint is opc.tcp', function () {
        $validator = new ReverseHelloValidator(['urn:trusted:server']);
        $msg = new ReverseHelloMessage('urn:trusted:server', 'opc.tcp://host:4840');

        expect($validator->isAccepted($msg))->toBeTrue();
    });

    it('ensureAccepted is silent (no exception) on a valid message', function () {
        $validator = new ReverseHelloValidator(['urn:trusted:server']);
        $msg = new ReverseHelloMessage('urn:trusted:server', 'opc.tcp://host:4840');

        $validator->ensureAccepted($msg);
        expect(true)->toBeTrue();
    });

    it('rejects when ServerUri is empty', function () {
        $validator = new ReverseHelloValidator(['urn:trusted:server']);
        $msg = new ReverseHelloMessage('', 'opc.tcp://host:4840');

        expect(fn () => $validator->ensureAccepted($msg))
            ->toThrow(ReverseConnectRejectedException::class, 'ServerUri is empty');
    });

    it('rejects when ServerUri is not in the whitelist', function () {
        $validator = new ReverseHelloValidator(['urn:trusted:server']);
        $msg = new ReverseHelloMessage('urn:other:impostor', 'opc.tcp://host:4840');

        expect(fn () => $validator->ensureAccepted($msg))
            ->toThrow(ReverseConnectRejectedException::class, 'not in the configured whitelist');
    });

    it('rejects every message when the whitelist is empty (fail-secure)', function () {
        $validator = new ReverseHelloValidator([]);
        $msg = new ReverseHelloMessage('urn:anyone', 'opc.tcp://host:4840');

        expect($validator->isAccepted($msg))->toBeFalse();
    });

    it('uses case-sensitive matching on ServerUri (per RFC 3986)', function () {
        $validator = new ReverseHelloValidator(['urn:Trusted:Server']);
        $msg = new ReverseHelloMessage('urn:trusted:server', 'opc.tcp://host:4840');

        expect($validator->isAccepted($msg))->toBeFalse();
    });

    it('rejects when EndpointUrl is empty', function () {
        $validator = new ReverseHelloValidator(['urn:trusted:server']);
        $msg = new ReverseHelloMessage('urn:trusted:server', '');

        expect(fn () => $validator->ensureAccepted($msg))
            ->toThrow(ReverseConnectRejectedException::class, 'EndpointUrl is empty');
    });

    it('rejects when EndpointUrl does not use opc.tcp scheme', function () {
        $validator = new ReverseHelloValidator(['urn:trusted:server']);
        $msg = new ReverseHelloMessage('urn:trusted:server', 'https://host:4840');

        expect(fn () => $validator->ensureAccepted($msg))
            ->toThrow(ReverseConnectRejectedException::class, 'does not use the opc.tcp scheme');
    });

    it('exposes the rejected message on the exception', function () {
        $validator = new ReverseHelloValidator(['urn:trusted:server']);
        $msg = new ReverseHelloMessage('urn:impostor', 'opc.tcp://host:4840');

        try {
            $validator->ensureAccepted($msg);
            $this->fail('Expected ReverseConnectRejectedException');
        } catch (ReverseConnectRejectedException $e) {
            expect($e->rejectedMessage)->toBe($msg);
        }
    });

    it('preserves the whitelist when read back', function () {
        $validator = new ReverseHelloValidator(['urn:a', 'urn:b', 'urn:c']);

        expect($validator->getAllowedServerUris())->toBe(['urn:a', 'urn:b', 'urn:c']);
    });

    it('accepts iterable input (Generator) for the whitelist', function () {
        $gen = (function () {
            yield 'urn:trusted:server';
        })();
        $validator = new ReverseHelloValidator($gen);
        $msg = new ReverseHelloMessage('urn:trusted:server', 'opc.tcp://host:4840');

        expect($validator->isAccepted($msg))->toBeTrue();
    });

    it('treats two whitelisted entries independently', function () {
        $validator = new ReverseHelloValidator(['urn:a', 'urn:b']);

        expect($validator->isAccepted(new ReverseHelloMessage('urn:a', 'opc.tcp://h:1')))->toBeTrue();
        expect($validator->isAccepted(new ReverseHelloMessage('urn:b', 'opc.tcp://h:1')))->toBeTrue();
        expect($validator->isAccepted(new ReverseHelloMessage('urn:c', 'opc.tcp://h:1')))->toBeFalse();
    });
});
