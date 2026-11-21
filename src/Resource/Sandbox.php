<?php

declare(strict_types=1);

namespace MaximoPay\Resource;

use MaximoPay\Http\HttpClient;
use MaximoPay\Resource\Sandbox\SandboxCharges;
use MaximoPay\Resource\Sandbox\SandboxDisputes;
use MaximoPay\Resource\Sandbox\SandboxRefunds;
use MaximoPay\Resource\Sandbox\SandboxWithdrawals;

/**
 * Comandos que simulam o adquirente. Existem só no sandbox (na API real dão
 * 404) e respondem o objeto já atualizado. Não levam Idempotency-Key, então o
 * SDK nunca os repete: numa falha de rede, consulte o objeto antes de mandar
 * de novo (um segundo comando dá 409 `SANDBOX_INVALID_STATE`).
 */
final class Sandbox
{
    public readonly SandboxCharges $charges;
    public readonly SandboxDisputes $disputes;
    public readonly SandboxWithdrawals $withdrawals;
    public readonly SandboxRefunds $refunds;

    public function __construct(HttpClient $http)
    {
        $this->charges = new SandboxCharges($http);
        $this->disputes = new SandboxDisputes($http);
        $this->withdrawals = new SandboxWithdrawals($http);
        $this->refunds = new SandboxRefunds($http);
    }
}
