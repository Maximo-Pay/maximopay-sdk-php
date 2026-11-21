<?php

declare(strict_types=1);

namespace MaximoPay\Tests;

use MaximoPay\Client;
use MaximoPay\Exception\ConnectionException;
use MaximoPay\Exception\MaximoPayException;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private const BASE = 'https://api.exemplo.com.br/v1';

    /** @var list<float> */
    private array $sleeps = [];

    private function client(FakeTransport $transport, string $secret = 'sec'): Client
    {
        return new Client('app', $secret, self::BASE . '/', [
            'transport' => $transport,
            'sleep' => function (float $s): void {
                $this->sleeps[] = $s;
            },
        ]);
    }

    private static function charge(): array
    {
        return ['amount' => 1500, 'paymentMethod' => 'PIX', 'customer' => ['name' => 'João', 'document' => '12345678909']];
    }

    public function testTrocaAChaveUmaVezEReaproveitaOToken(): void
    {
        $t = (new FakeTransport())->push(200, ['id' => 'c1'])->push(200, ['id' => 'c2']);
        $mp = $this->client($t);
        $mp->charges->retrieve('c1');
        $mp->charges->retrieve('c2');
        self::assertSame(1, $t->tokens);
        self::assertSame('Bearer tok1', $t->requests[1]['headers']['Authorization']);
        self::assertSame(self::BASE . '/charges/c2', $t->requests[1]['url']);
    }

    public function testPedeOutroTokenQuandoFaltamMenosDe60Segundos(): void
    {
        $t = (new FakeTransport(30))->push(200, [])->push(200, []);
        $mp = $this->client($t);
        $mp->charges->retrieve('a');
        $mp->charges->retrieve('b');
        self::assertSame(2, $t->tokens);
    }

    public function testUm401RenovaOTokenERepeteUmaVez(): void
    {
        $t = (new FakeTransport())->push(401, ['errorCode' => 'TOKEN_REVOKED', 'message' => 'x'])->push(200, ['id' => 'c1']);
        self::assertSame(['id' => 'c1'], $this->client($t)->charges->retrieve('c1'));
        self::assertSame(2, $t->tokens);
        self::assertSame('Bearer tok2', $t->requests[1]['headers']['Authorization']);
    }

    public function testDois401SeguidosViramErro(): void
    {
        $t = (new FakeTransport())->push(401, ['errorCode' => 'TOKEN_REVOKED', 'message' => 'x'])->push(401, ['errorCode' => 'TOKEN_REVOKED', 'message' => 'x']);
        try {
            $this->client($t)->charges->retrieve('c1');
            self::fail('esperava erro');
        } catch (MaximoPayException $e) {
            self::assertSame(401, $e->statusCode);
            self::assertSame('TOKEN_REVOKED', $e->errorCode);
        }
        self::assertCount(2, $t->requests);
    }

    public function testCriaCobrancaComBrlPorPadraoEGeraIdempotencyKey(): void
    {
        $t = (new FakeTransport())->push(201, ['id' => 'c1', 'status' => 'PENDING']);
        $charge = $this->client($t)->charges->create(self::charge());
        self::assertSame('c1', $charge['id']);
        $req = $t->requests[0];
        self::assertSame('POST', $req['method']);
        self::assertSame('BRL', json_decode((string) $req['body'], true)['currencyCode']);
        self::assertSame('João', json_decode((string) $req['body'], true)['customer']['name']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $req['headers']['Idempotency-Key']);
        self::assertSame('application/json', $req['headers']['Content-Type']);
    }

    public function testUsaAChaveDeIdempotenciaInformada(): void
    {
        $t = (new FakeTransport())->push(201, [])->push(200, [])->push(200, []);
        $mp = $this->client($t);
        $mp->charges->create(self::charge(), 'pedido-123');
        $mp->charges->cancel('c1', 'cancel-1');
        $mp->charges->refund('c1');
        self::assertSame('pedido-123', $t->requests[0]['headers']['Idempotency-Key']);
        self::assertSame('cancel-1', $t->requests[1]['headers']['Idempotency-Key']);
        self::assertNull($t->requests[1]['body']);
        self::assertSame('{}', $t->requests[2]['body']);
        self::assertArrayHasKey('Idempotency-Key', $t->requests[2]['headers']);
    }

    public function testRepete5xx429E409EmAndamentoSempreComAMesmaChave(): void
    {
        foreach ([[503, 'PSP_UNAVAILABLE'], [429, 'TOO_MANY_REQUESTS'], [409, 'IDEMPOTENCY_IN_PROGRESS']] as [$status, $code]) {
            $t = (new FakeTransport())->push($status, ['errorCode' => $code, 'message' => 'x'])->push(201, ['id' => 'c1']);
            self::assertSame('c1', $this->client($t)->charges->create(self::charge())['id']);
            self::assertCount(2, $t->requests);
            self::assertSame($t->requests[0]['headers']['Idempotency-Key'], $t->requests[1]['headers']['Idempotency-Key']);
        }
    }

    public function testRespeitaRetryAfter(): void
    {
        $t = (new FakeTransport())->push(429, ['errorCode' => 'X', 'message' => 'x'], ['retry-after' => '2'])->push(200, []);
        $this->client($t)->charges->retrieve('c1');
        self::assertSame([2.0], $this->sleeps);
    }

    public function testRepeteFalhaDeRedeComAMesmaChaveEDesisteDepoisDeMaxRetries(): void
    {
        $t = (new FakeTransport())->fail()->fail()->fail();
        try {
            $this->client($t)->withdrawals->create(['amount' => 100, 'withdrawalMethod' => 'PIX']);
            self::fail('esperava erro');
        } catch (ConnectionException) {
        }
        self::assertCount(3, $t->requests);
        self::assertCount(1, array_unique(array_map(static fn ($r) => $r['headers']['Idempotency-Key'], $t->requests)));
        self::assertCount(2, $this->sleeps);
    }

    public function testNaoRepeteErroDeNegocioEExpoeOEnvelope(): void
    {
        $t = (new FakeTransport())->push(422, [
            'statusCode' => 422,
            'errorCode' => 'KYC_LIMIT_EXCEEDED',
            'message' => 'Limite diário',
            'details' => ['available' => '0'],
        ], ['x-correlation-id' => 'corr-1']);
        try {
            $this->client($t)->charges->create(self::charge());
            self::fail('esperava erro');
        } catch (MaximoPayException $e) {
            self::assertSame(422, $e->statusCode);
            self::assertSame('KYC_LIMIT_EXCEEDED', $e->errorCode);
            self::assertSame('Limite diário', $e->getMessage());
            self::assertSame(['available' => '0'], $e->details);
            self::assertSame('corr-1', $e->correlationId);
        }
        self::assertCount(1, $t->requests);
    }

    public function testPostSemIdempotenciaNaoERepetido(): void
    {
        $t = (new FakeTransport())->push(503, ['errorCode' => 'SERVICE_UNAVAILABLE', 'message' => 'x']);
        $this->expectException(MaximoPayException::class);
        try {
            $this->client($t)->webhookEndpoints->create(['url' => 'https://loja.com.br/wh', 'secret' => str_repeat('s', 32), 'events' => ['*']]);
        } finally {
            self::assertCount(1, $t->requests);
            self::assertArrayNotHasKey('Idempotency-Key', $t->requests[0]['headers']);
        }
    }

    public function testWebhookSemEventsERecusadoAntesDeChamarAApi(): void
    {
        $t = new FakeTransport();
        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->client($t)->webhookEndpoints->create(['url' => 'https://loja.com.br/wh', 'secret' => str_repeat('s', 32), 'events' => []]);
        } finally {
            self::assertSame(0, $t->tokens);
        }
    }

    public function testListaComFiltrosNaQueryEOmiteOsVazios(): void
    {
        $t = (new FakeTransport())->push(200, ['data' => []])->push(200, ['data' => []]);
        $mp = $this->client($t);
        $mp->charges->list(['status' => 'PAID', 'page' => 2, 'limit' => null]);
        $mp->webhookEndpoints->deliveries();
        self::assertSame(self::BASE . '/charges?status=PAID&page=2', $t->requests[0]['url']);
        self::assertSame(self::BASE . '/merchants/webhooks/history', $t->requests[1]['url']);
    }

    public function testIdsVaoCodificadosNaUrl(): void
    {
        $t = (new FakeTransport())->push(200, []);
        $this->client($t)->webhookEndpoints->update('a/b', ['isActive' => true]);
        self::assertSame('PUT', $t->requests[0]['method']);
        self::assertSame(self::BASE . '/merchants/webhooks/a%2Fb', $t->requests[0]['url']);
    }

    public function testCredenciaisEBaseUrlObrigatorios(): void
    {
        foreach ([['', 's', self::BASE], ['a', '', self::BASE], ['a', 's', '']] as [$id, $secret, $base]) {
            try {
                new Client($id, $secret, $base, ['transport' => new FakeTransport()]);
                self::fail('esperava erro');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testCredencialErradaNaoERepetida(): void
    {
        $t = new FakeTransport();
        try {
            $this->client($t, 'wrong')->charges->retrieve('c1');
            self::fail('esperava erro');
        } catch (MaximoPayException $e) {
            self::assertSame('INVALID_CREDENTIALS', $e->errorCode);
        }
        self::assertSame(1, $t->tokens);
        self::assertCount(0, $t->requests);
    }
}
