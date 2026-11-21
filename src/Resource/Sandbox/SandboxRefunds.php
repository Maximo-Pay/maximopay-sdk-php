<?php

declare(strict_types=1);

namespace MaximoPay\Resource\Sandbox;

use MaximoPay\Http\HttpClient;

final class SandboxRefunds
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * Conclui a devolução (sozinha ela conclui em ~5 s).
     *
     * @return array<string, mixed> a cobrança
     */
    public function complete(string $refundId): array
    {
        return $this->http->request('POST', '/sandbox/refunds/' . rawurlencode($refundId) . '/complete');
    }

    /**
     * Recusa a devolução.
     *
     * @return array<string, mixed> a cobrança
     */
    public function fail(string $refundId): array
    {
        return $this->http->request('POST', '/sandbox/refunds/' . rawurlencode($refundId) . '/fail');
    }
}
