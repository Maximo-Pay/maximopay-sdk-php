<?php

declare(strict_types=1);

namespace MaximoPay\Resource;

use MaximoPay\Http\HttpClient;

final class Charges
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * Cria uma cobrança (valores em centavos). Pix: a resposta traz
     * `metadata.pixCopiaECola` e `checkoutUrl`.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function create(array $params, ?string $idempotencyKey = null): array
    {
        return $this->http->request('POST', '/charges', body: ['currencyCode' => 'BRL'] + $params, idempotent: true, idempotencyKey: $idempotencyKey);
    }

    /** @return array<string, mixed> */
    public function retrieve(string $id): array
    {
        return $this->http->request('GET', '/charges/' . rawurlencode($id));
    }

    /**
     * @param array{status?: string, page?: int, limit?: int} $params
     * @return array<string, mixed>
     */
    public function list(array $params = []): array
    {
        return $this->http->request('GET', '/charges', $params);
    }

    /**
     * Cancela uma cobrança ainda não paga.
     *
     * @return array<string, mixed>
     */
    public function cancel(string $id, ?string $idempotencyKey = null): array
    {
        return $this->http->request('POST', '/charges/' . rawurlencode($id) . '/cancel', idempotent: true, idempotencyKey: $idempotencyKey);
    }

    /**
     * Devolve tudo (sem `amount`) ou parte; várias parciais até o valor pago.
     *
     * @param array{amount?: int, reason?: string} $params
     * @return array<string, mixed>
     */
    public function refund(string $id, array $params = [], ?string $idempotencyKey = null): array
    {
        return $this->http->request('POST', '/charges/' . rawurlencode($id) . '/refund', body: $params, idempotent: true, idempotencyKey: $idempotencyKey);
    }
}
