<?php

declare(strict_types=1);

namespace MaximoPay\Http;

final class Response
{
    /**
     * @param array<string, string> $headers nomes em minúsculas
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
