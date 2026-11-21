<?php

declare(strict_types=1);

namespace MaximoPay\Http;

use MaximoPay\Exception\ConnectionException;
use MaximoPay\Exception\MaximoPayException;

/** @internal Use MaximoPay\Client. */
final class HttpClient
{
    private const TOKEN_REFRESH_MARGIN_SECONDS = 60;
    private const MAX_BACKOFF_SECONDS = 8.0;

    private string $baseUrl;
    private ?string $accessToken = null;
    private float $expiresAt = 0.0;
    /** @var callable(float): void */
    private $sleep;

    /**
     * @param callable(float): void|null $sleep espera entre tentativas (testes)
     */
    public function __construct(
        private readonly string $appId,
        private readonly string $appSecret,
        string $baseUrl,
        private readonly Transport $transport,
        private readonly int $maxRetries = 2,
        private readonly float $timeoutSeconds = 30.0,
        ?callable $sleep = null,
    ) {
        if ($appId === '' || $appSecret === '') {
            throw new \InvalidArgumentException('MaximoPay: appId e appSecret são obrigatórios');
        }
        if ($baseUrl === '') {
            throw new \InvalidArgumentException('MaximoPay: baseUrl é obrigatório (ex. https://api.seudominio.com.br/v1)');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) ($seconds * 1_000_000));
        };
    }

    /**
     * @param array<string, scalar|null> $query
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    public function request(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        bool $idempotent = false,
        ?string $idempotencyKey = null,
    ): array {
        // A mesma chave em todas as tentativas: repetir com outra poderia
        // criar duas cobranças.
        $key = $idempotent ? ($idempotencyKey ?? self::uuid()) : null;
        // Sem chave, só repete o que não cria nada.
        $retryable = $method !== 'POST' || $key !== null;
        $refreshedAfter401 = false;
        $url = $this->url($path, $query);
        $payload = $body === null ? null : self::encode($body);

        for ($attempt = 0; ; $attempt++) {
            try {
                $token = $this->token();
                $headers = ['Accept' => 'application/json', 'Authorization' => "Bearer $token"];
                if ($payload !== null) {
                    $headers['Content-Type'] = 'application/json';
                }
                if ($key !== null) {
                    $headers['Idempotency-Key'] = $key;
                }
                $response = $this->transport->send($method, $url, $headers, $payload, $this->timeoutSeconds);
            } catch (ConnectionException $error) {
                if ($retryable && $attempt < $this->maxRetries) {
                    ($this->sleep)($this->backoff($attempt));
                    continue;
                }
                throw $error;
            }

            if ($response->status === 401 && !$refreshedAfter401) {
                // Token revogado ou expirado antes da hora: um novo, uma vez.
                $refreshedAfter401 = true;
                $this->accessToken = null;
                $attempt--;
                continue;
            }
            if ($response->status >= 200 && $response->status < 300) {
                $decoded = self::decode($response->body);
                return is_array($decoded) ? $decoded : [];
            }

            $error = self::toException($response);
            if ($retryable && $attempt < $this->maxRetries && self::shouldRetry($error)) {
                ($this->sleep)($this->retryAfter($response) ?? $this->backoff($attempt));
                continue;
            }
            throw $error;
        }
    }

    private function token(): string
    {
        if ($this->accessToken !== null && microtime(true) < $this->expiresAt - self::TOKEN_REFRESH_MARGIN_SECONDS) {
            return $this->accessToken;
        }
        $response = $this->transport->send(
            'POST',
            $this->baseUrl . '/auth/token',
            ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
            self::encode(['appId' => $this->appId, 'appSecret' => $this->appSecret]),
            $this->timeoutSeconds,
        );
        if ($response->status < 200 || $response->status >= 300) {
            throw self::toException($response);
        }
        $body = self::decode($response->body);
        if (!is_array($body) || !is_string($body['accessToken'] ?? null) || !is_numeric($body['expiresIn'] ?? null)) {
            throw new MaximoPayException($response->status, 'INVALID_TOKEN_RESPONSE', 'Resposta inesperada de POST /auth/token');
        }
        $this->accessToken = $body['accessToken'];
        $this->expiresAt = microtime(true) + (float) $body['expiresIn'];
        return $this->accessToken;
    }

    /** @param array<string, scalar|null> $query */
    private function url(string $path, array $query): string
    {
        $query = array_filter($query, static fn ($v) => $v !== null);
        $query = array_map(static fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : $v, $query);
        return $this->baseUrl . $path . ($query === [] ? '' : '?' . http_build_query($query));
    }

    private static function shouldRetry(MaximoPayException $error): bool
    {
        return $error->statusCode >= 500
            || $error->statusCode === 429
            || ($error->statusCode === 409 && $error->errorCode === 'IDEMPOTENCY_IN_PROGRESS');
    }

    private function backoff(int $attempt): float
    {
        $base = min(self::MAX_BACKOFF_SECONDS, 0.5 * (2 ** $attempt));
        return $base / 2 + (mt_rand() / mt_getrandmax()) * ($base / 2);
    }

    private function retryAfter(Response $response): ?float
    {
        $value = $response->header('retry-after');
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return max(0.0, min(self::MAX_BACKOFF_SECONDS, (float) $value));
        }
        $date = strtotime($value);
        return $date === false ? null : max(0.0, min(self::MAX_BACKOFF_SECONDS, $date - microtime(true)));
    }

    private static function toException(Response $response): MaximoPayException
    {
        $body = self::decode($response->body);
        $envelope = is_array($body) ? $body : [];
        $message = $envelope['message'] ?? null;
        return new MaximoPayException(
            $response->status,
            is_string($envelope['errorCode'] ?? null) ? $envelope['errorCode'] : 'HTTP_' . $response->status,
            is_string($message) ? $message : (is_string($body) && $body !== '' ? $body : 'HTTP ' . $response->status),
            is_array($envelope['details'] ?? null) ? $envelope['details'] : null,
            $response->header('x-correlation-id'),
        );
    }

    /** @param array<string, mixed> $value */
    private static function encode(array $value): string
    {
        // Objeto vazio vira {} (a API não aceita [] como corpo).
        return $value === [] ? '{}' : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function decode(string $text): mixed
    {
        if ($text === '') {
            return null;
        }
        try {
            return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $text;
        }
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
