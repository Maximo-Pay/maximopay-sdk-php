<?php

declare(strict_types=1);

namespace MaximoPay\Http;

use MaximoPay\Exception\ConnectionException;

/** Envia uma requisição HTTP. Troque para usar o seu cliente (Guzzle, Symfony…). */
interface Transport
{
    /**
     * @param array<string, string> $headers
     * @throws ConnectionException quando não houver resposta
     */
    public function send(string $method, string $url, array $headers, ?string $body, float $timeoutSeconds): Response;
}
