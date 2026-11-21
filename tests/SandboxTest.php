<?php

declare(strict_types=1);

namespace MaximoPay\Tests;

use MaximoPay\Client;
use MaximoPay\Exception\MaximoPayException;
use MaximoPay\Http\Response;
use MaximoPay\Http\Transport;
use PHPUnit\Framework\TestCase;

final class SandboxTest extends TestCase
{
    private const BASE = 'https://api.exemplo.com.br/v1';

    private static function client(Transport $transport): Client
    {
        return new Client('pk_test_app', 'sk_test_sec', self::BASE, [
            'transport' => $transport,
            'sleep' => static function (float $s): void {
            },
        ]);
    }

    public function testCadaComandoChamaARotaCertaComOCorpoCerto(): void
    {
        $t = new FakeTransport();
        for ($i = 0; $i < 9; $i++) {
            $t->push(200, ['id' => 'x']);
        }
        $mp = self::client($t);
        $mp->sandbox->charges->pay('ch 1');
        $mp->sandbox->charges->dispute('ch_1', ['type' => 'REFUND_REQUEST', 'amount' => 500]);
        $mp->sandbox->charges->dispute('ch_1');
        $mp->sandbox->disputes->close('d_1', 'WON');
        $mp->sandbox->withdrawals->complete('w_1');
        $mp->sandbox->withdrawals->fail('w_1', 'conta encerrada');
        $mp->sandbox->withdrawals->return('w_1');
        $mp->sandbox->refunds->complete('r_1');
        $mp->sandbox->refunds->fail('r_1');

        $seen = array_map(static fn (array $r): array => [$r['method'], substr($r['url'], strlen(self::BASE)), $r['body']], $t->requests);
        self::assertSame([
            ['POST', '/sandbox/charges/ch%201/pay', null],
            ['POST', '/sandbox/charges/ch_1/dispute', '{"type":"REFUND_REQUEST","amount":"500"}'],
            ['POST', '/sandbox/charges/ch_1/dispute', '{}'],
            ['POST', '/sandbox/disputes/d_1/close', '{"result":"WON"}'],
            ['POST', '/sandbox/withdrawals/w_1/complete', null],
            ['POST', '/sandbox/withdrawals/w_1/fail', '{"reason":"conta encerrada"}'],
            ['POST', '/sandbox/withdrawals/w_1/return', null],
            ['POST', '/sandbox/refunds/r_1/complete', null],
            ['POST', '/sandbox/refunds/r_1/fail', null],
        ], $seen);
        foreach ($t->requests as $r) {
            self::assertArrayNotHasKey('Idempotency-Key', $r['headers']);
        }
    }

    public function testRespondeOObjetoAtualizadoComLivemode(): void
    {
        $t = (new FakeTransport())->push(200, ['id' => 'ch_1', 'status' => 'PAID', 'livemode' => false]);
        $charge = self::client($t)->sandbox->charges->pay('ch_1');
        self::assertSame('PAID', $charge['status']);
        self::assertFalse($charge['livemode']);
    }

    public function testUmComandoNuncaERepetido(): void
    {
        $t = (new FakeTransport())->push(503, ['errorCode' => 'SERVICE_UNAVAILABLE'])->push(200, []);
        try {
            self::client($t)->sandbox->charges->pay('ch_1');
            self::fail('devia lançar');
        } catch (MaximoPayException $e) {
            self::assertSame(503, $e->statusCode);
        }
        self::assertCount(1, $t->requests);
    }

    public function testEstadoInvalidoTrazDetailsStatus(): void
    {
        $t = (new FakeTransport())->push(409, ['statusCode' => 409, 'errorCode' => 'SANDBOX_INVALID_STATE', 'message' => 'x', 'details' => ['status' => 'PAID']]);
        try {
            self::client($t)->sandbox->charges->pay('ch_1');
            self::fail('devia lançar');
        } catch (MaximoPayException $e) {
            self::assertSame('SANDBOX_INVALID_STATE', $e->errorCode);
            self::assertSame(['status' => 'PAID'], $e->details);
        }
    }

    public function testChaveDeTesteNaApiRealTrazAUrlDoSandbox(): void
    {
        $transport = new class () implements Transport {
            public function send(string $method, string $url, array $headers, ?string $body, float $timeoutSeconds): Response
            {
                return new Response(401, [], (string) json_encode([
                    'statusCode' => 401,
                    'errorCode' => 'TEST_KEY_ON_LIVE',
                    'message' => 'x',
                    'details' => ['sandboxUrl' => 'https://sandbox-api.exemplo.com.br/v1'],
                ]));
            }
        };
        try {
            self::client($transport)->charges->retrieve('ch_1');
            self::fail('devia lançar');
        } catch (MaximoPayException $e) {
            self::assertSame('TEST_KEY_ON_LIVE', $e->errorCode);
            self::assertSame('https://sandbox-api.exemplo.com.br/v1', $e->details['sandboxUrl']);
        }
    }
}
