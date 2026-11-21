<?php

declare(strict_types=1);

namespace MaximoPay\Resource\Sandbox;

use MaximoPay\Http\HttpClient;

final class SandboxWithdrawals
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * Conclui o saque (sozinho ele conclui em ~5 s).
     *
     * @return array<string, mixed> o saque
     */
    public function complete(string $id): array
    {
        return $this->http->request('POST', self::path($id, 'complete'));
    }

    /**
     * Falha o saque; o valor volta para a carteira.
     *
     * @return array<string, mixed> o saque
     */
    public function fail(string $id, ?string $reason = null): array
    {
        return $this->http->request('POST', self::path($id, 'fail'), body: $reason === null ? [] : ['reason' => $reason]);
    }

    /**
     * Devolve um saque já concluído.
     *
     * @return array<string, mixed> o saque
     */
    public function return(string $id): array
    {
        return $this->http->request('POST', self::path($id, 'return'));
    }

    private static function path(string $id, string $action): string
    {
        return '/sandbox/withdrawals/' . rawurlencode($id) . '/' . $action;
    }
}
