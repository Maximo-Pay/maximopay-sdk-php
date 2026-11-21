<?php

declare(strict_types=1);

namespace MaximoPay\Exception;

/** Erro devolvido pela API (`{ statusCode, errorCode, message, details }`). */
class MaximoPayException extends \RuntimeException
{
    /**
     * @param array<string, mixed>|null $details
     */
    public function __construct(
        public readonly int $statusCode,
        /** Código estável para tratar no seu código, ex. `KYC_LIMIT_EXCEEDED`. */
        public readonly string $errorCode,
        string $message,
        public readonly ?array $details = null,
        /** `x-correlation-id` da resposta: informe ao suporte. */
        public readonly ?string $correlationId = null,
    ) {
        parent::__construct($message, $statusCode);
    }
}
