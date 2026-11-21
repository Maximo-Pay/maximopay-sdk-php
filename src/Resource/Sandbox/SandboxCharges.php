<?php

declare(strict_types=1);

namespace MaximoPay\Resource\Sandbox;

use MaximoPay\Http\HttpClient;

final class SandboxCharges
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * Paga uma cobrança `PENDING` (dispara `charge.paid`).
     *
     * @return array<string, mixed> a cobrança
     */
    public function pay(string $id): array
    {
        return $this->http->request('POST', '/sandbox/charges/' . rawurlencode($id) . '/pay');
    }

    /**
     * Abre uma MED na cobrança paga; sem `amount` (centavos), no valor total.
     *
     * @param array{type?: 'FRAUD'|'REFUND_REQUEST', amount?: int|string} $params
     * @return array<string, mixed> a cobrança
     */
    public function dispute(string $id, array $params = []): array
    {
        if (isset($params['amount'])) {
            $params['amount'] = (string) $params['amount'];
        }
        return $this->http->request('POST', '/sandbox/charges/' . rawurlencode($id) . '/dispute', body: $params);
    }
}
