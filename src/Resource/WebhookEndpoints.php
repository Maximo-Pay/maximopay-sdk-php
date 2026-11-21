<?php

declare(strict_types=1);

namespace MaximoPay\Resource;

use MaximoPay\Http\HttpClient;

/** Cadastro dos endereços que recebem webhooks (`/merchants/webhooks`). */
final class WebhookEndpoints
{
    private const BASE = '/merchants/webhooks';

    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * @param array{url: string, secret: string, events: list<string>, description?: string} $params
     * @return array<string, mixed>
     */
    public function create(array $params): array
    {
        if (!isset($params['events']) || !is_array($params['events']) || $params['events'] === []) {
            // A API aceita sem eventos, mas uma config assim não recebe nada.
            throw new \InvalidArgumentException("MaximoPay: informe events (ou ['*'] para todos)");
        }
        return $this->http->request('POST', self::BASE, body: $params);
    }

    /**
     * @param array{page?: int, limit?: int} $params
     * @return array<string, mixed>
     */
    public function list(array $params = []): array
    {
        return $this->http->request('GET', self::BASE, $params);
    }

    /** @return array<string, mixed> */
    public function retrieve(string $id): array
    {
        return $this->http->request('GET', self::BASE . '/' . rawurlencode($id));
    }

    /**
     * `['isActive' => true]` reativa um endereço pausado por falhas seguidas.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function update(string $id, array $params): array
    {
        return $this->http->request('PUT', self::BASE . '/' . rawurlencode($id), body: $params);
    }

    /** @return array<string, mixed> */
    public function delete(string $id): array
    {
        return $this->http->request('DELETE', self::BASE . '/' . rawurlencode($id));
    }

    /**
     * Histórico de entregas do lojista.
     *
     * @return array<string, mixed>
     */
    public function deliveries(): array
    {
        return $this->http->request('GET', self::BASE . '/history');
    }

    /** @return array<string, mixed> */
    public function delivery(string $id): array
    {
        return $this->http->request('GET', self::BASE . '/delivery/' . rawurlencode($id));
    }
}
