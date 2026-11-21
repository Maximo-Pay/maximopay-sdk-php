<?php

declare(strict_types=1);

namespace MaximoPay\Resource\Sandbox;

use MaximoPay\Http\HttpClient;

final class SandboxDisputes
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * Encerra a MED.
     *
     * @param 'LOST'|'WON' $result
     * @return array<string, mixed> a disputa
     */
    public function close(string $id, string $result): array
    {
        return $this->http->request('POST', '/sandbox/disputes/' . rawurlencode($id) . '/close', body: ['result' => $result]);
    }
}
