<?php

declare(strict_types=1);

namespace MaximoPay;

use MaximoPay\Http\CurlTransport;
use MaximoPay\Http\HttpClient;
use MaximoPay\Http\Transport;
use MaximoPay\Resource\Charges;
use MaximoPay\Resource\Sandbox;
use MaximoPay\Resource\WebhookEndpoints;
use MaximoPay\Resource\Withdrawals;

/**
 * Cliente da API do Máximo Pay com chave de API: troca appId/appSecret por
 * token (e renova), manda Idempotency-Key e repete o que é seguro repetir.
 */
final class Client
{
    public readonly Charges $charges;
    public readonly Withdrawals $withdrawals;
    public readonly WebhookEndpoints $webhookEndpoints;
    /** Simula o adquirente; só funciona com a URL e uma chave do sandbox. */
    public readonly Sandbox $sandbox;

    /**
     * @param string $baseUrl a URL da API do seu operador, com /v1 (ex. https://api.seudominio.com.br/v1)
     * @param array{maxRetries?: int, timeout?: float, transport?: Transport, sleep?: callable(float): void} $options
     */
    public function __construct(string $appId, string $appSecret, string $baseUrl, array $options = [])
    {
        $http = new HttpClient(
            $appId,
            $appSecret,
            $baseUrl,
            $options['transport'] ?? new CurlTransport(),
            $options['maxRetries'] ?? 2,
            (float) ($options['timeout'] ?? 30.0),
            $options['sleep'] ?? null,
        );
        $this->charges = new Charges($http);
        $this->withdrawals = new Withdrawals($http);
        $this->webhookEndpoints = new WebhookEndpoints($http);
        $this->sandbox = new Sandbox($http);
    }
}
