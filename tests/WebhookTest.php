<?php

declare(strict_types=1);

namespace MaximoPay\Tests;

use MaximoPay\Exception\SignatureVerificationException;
use MaximoPay\Webhook;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function contract(): array
    {
        // Cópia dos vetores que o webhook-service testa (fintech-baas-api, sdks/contract).
        return json_decode((string) file_get_contents(__DIR__ . '/../contract/webhook-signature-vectors.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return iterable<string, array{string, string, int, bool}> */
    public static function vectors(): iterable
    {
        foreach (self::contract()['vectors'] as $v) {
            yield $v['name'] => [$v['header'], $v['body'], $v['now'], $v['expected']];
        }
    }

    #[DataProvider('vectors')]
    public function testVetorCompartilhado(string $header, string $body, int $now, bool $expected): void
    {
        self::assertSame($expected, Webhook::verifySignature($body, $header, self::contract()['secret'], now: $now));
    }

    public function testToleranciaIgualAoServidor(): void
    {
        self::assertSame(self::contract()['toleranceSeconds'], Webhook::DEFAULT_TOLERANCE_SECONDS);
    }

    public function testConstructEventComCabecalhosEmQualquerCaixa(): void
    {
        ['secret' => $secret, 'sign' => $sign] = self::contract();
        $event = Webhook::constructEvent($sign['body'], [
            'X-Signature' => $sign['header'],
            'x-event' => 'charge.paid',
            'X-DELIVERY-ID' => ['d1'],
        ], $secret, now: $sign['timestamp']);
        self::assertSame('d1', $event->id);
        self::assertSame('charge.paid', $event->type);
        self::assertSame(json_decode($sign['body'], true), $event->data);
        self::assertSame('pedido-123', $event->data['referenceId']);
    }

    public function testConstructEventAceitaServer(): void
    {
        ['secret' => $secret, 'sign' => $sign] = self::contract();
        $server = ['HTTP_X_SIGNATURE' => $sign['header'], 'HTTP_X_EVENT' => 'charge.paid', 'HTTP_X_DELIVERY_ID' => 'd2', 'REQUEST_METHOD' => 'POST'];
        self::assertSame('d2', Webhook::constructEvent($sign['body'], $server, $secret, now: $sign['timestamp'])->id);
    }

    public function testConstructEventRecusaAssinaturaErradaOuAusente(): void
    {
        ['secret' => $secret, 'sign' => $sign] = self::contract();
        $cases = [
            [$sign['body'] . ' ', ['x-signature' => $sign['header']], $secret],
            [$sign['body'], [], $secret],
            [$sign['body'], ['x-signature' => $sign['header']], ''],
        ];
        foreach ($cases as [$body, $headers, $s]) {
            try {
                Webhook::constructEvent($body, $headers, $s, now: $sign['timestamp']);
                self::fail('esperava erro');
            } catch (SignatureVerificationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testSemNowUsaORelogio(): void
    {
        ['secret' => $secret, 'sign' => $sign] = self::contract();
        self::assertFalse(Webhook::verifySignature($sign['body'], $sign['header'], $secret));
        $t = (string) time();
        $header = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $sign['body'], $secret);
        self::assertTrue(Webhook::verifySignature($sign['body'], $header, $secret));
    }
}
