<?php

declare(strict_types=1);

namespace MaximoPay\Resource;

use MaximoPay\Http\HttpClient;

final class Withdrawals
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * Pede um saque. Com "exigir 2FA nos saques" ligado (padrão) a chave de API
     * recebe 403 `TOTP_REQUIRED`.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function create(array $params, ?string $idempotencyKey = null): array
    {
        return $this->http->request('POST', '/withdrawals', body: ['currencyCode' => 'BRL'] + $params, idempotent: true, idempotencyKey: $idempotencyKey);
    }

    /** @return array<string, mixed> */
    public function retrieve(string $id): array
    {
        return $this->http->request('GET', '/withdrawals/' . rawurlencode($id));
    }

    /**
     * @param array{page?: int, limit?: int} $params
     * @return array<string, mixed>
     */
    public function list(array $params = []): array
    {
        return $this->http->request('GET', '/withdrawals', $params);
    }
}
