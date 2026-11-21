<?php

declare(strict_types=1);

namespace MaximoPay\Tests;

use MaximoPay\Exception\ConnectionException;
use MaximoPay\Http\Response;
use MaximoPay\Http\Transport;

/** Transporte de teste: responde da fila e guarda cada requisição. */
final class FakeTransport implements Transport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $requests = [];
    /** @var list<Response|ConnectionException> */
    private array $queue = [];
    public int $tokens = 0;

    public function __construct(private readonly int $expiresIn = 3600)
    {
    }

    /** @param array<string, mixed>|string|null $body */
    public function push(int $status, array|string|null $body = null, array $headers = []): self
    {
        $text = is_array($body) ? json_encode($body) : ($body ?? '');
        $this->queue[] = new Response($status, $headers, (string) $text);
        return $this;
    }

    public function fail(string $message = 'ECONNRESET'): self
    {
        $this->queue[] = new ConnectionException($message);
        return $this;
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeoutSeconds): Response
    {
        if (str_ends_with($url, '/auth/token')) {
            $this->tokens++;
            $credentials = json_decode((string) $body, true);
            if (($credentials['appSecret'] ?? '') === 'wrong') {
                return new Response(401, [], json_encode(['statusCode' => 401, 'errorCode' => 'INVALID_CREDENTIALS', 'message' => 'Invalid credentials']));
            }
            return new Response(201, [], json_encode(['accessToken' => 'tok' . $this->tokens, 'expiresIn' => $this->expiresIn]));
        }
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $next = array_shift($this->queue) ?? throw new \LogicException("Sem resposta para $method $url");
        if ($next instanceof ConnectionException) {
            throw $next;
        }
        return $next;
    }
}
