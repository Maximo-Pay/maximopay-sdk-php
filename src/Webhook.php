<?php

declare(strict_types=1);

namespace MaximoPay;

use MaximoPay\Exception\SignatureVerificationException;

/** Verificação dos webhooks recebidos (não chama a API). */
final class Webhook
{
    /** Idade máxima de uma entrega aceita (a mesma do servidor). */
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    /**
     * Confere `X-Signature: t=<unix s>,v1=<hex HMAC-SHA256(segredo, "<t>.<corpo cru>")>`.
     * Aceita se algum `v1` bater (troca de segredo) e `t` estiver dentro da tolerância.
     *
     * @param string $rawBody o corpo cru, ex. file_get_contents('php://input')
     * @param int|null $now agora em segundos unix (testes)
     */
    public static function verifySignature(
        string $rawBody,
        ?string $signatureHeader,
        string $secret,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        ?int $now = null,
    ): bool {
        if ($signatureHeader === null || $signatureHeader === '' || $secret === '') {
            return false;
        }
        $timestamp = null;
        $candidates = [];
        foreach (explode(',', $signatureHeader) as $part) {
            $at = strpos($part, '=');
            if ($at === false || $at === 0) {
                continue;
            }
            $key = trim(substr($part, 0, $at));
            $value = trim(substr($part, $at + 1));
            if ($key === 't' && $timestamp === null) {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $candidates[] = strtolower($value);
            }
        }
        if ($timestamp === null || preg_match('/^\d{1,15}$/', $timestamp) !== 1) {
            return false;
        }
        if (abs(($now ?? time()) - (int) $timestamp) > $toleranceSeconds) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        foreach ($candidates as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Verifica a assinatura e devolve o evento. Lança
     * SignatureVerificationException quando a entrega não deve ser processada
     * (responda 400).
     *
     * @param array<string, string|list<string>> $headers cabeçalhos em qualquer
     *        caixa (getallheaders(), PSR-7 getHeaders()) ou o próprio $_SERVER
     */
    public static function constructEvent(
        string $rawBody,
        array $headers,
        string $secret,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        ?int $now = null,
    ): WebhookEvent {
        if (!self::verifySignature($rawBody, self::header($headers, 'X-Signature'), $secret, $toleranceSeconds, $now)) {
            throw new SignatureVerificationException('Assinatura X-Signature inválida ou expirada');
        }
        try {
            $data = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new SignatureVerificationException('Corpo do webhook não é JSON');
        }
        return new WebhookEvent(
            self::header($headers, 'X-Delivery-Id') ?? '',
            self::header($headers, 'X-Event') ?? '',
            is_array($data) ? $data : [],
        );
    }

    /** @param array<string, mixed> $headers */
    private static function header(array $headers, string $name): ?string
    {
        $wanted = [strtolower($name), strtolower('HTTP_' . str_replace('-', '_', $name))];
        foreach ($headers as $key => $value) {
            if (!in_array(strtolower((string) $key), $wanted, true)) {
                continue;
            }
            if (is_array($value)) {
                $value = $value[0] ?? null;
            }
            return is_string($value) ? $value : null;
        }
        return null;
    }
}
