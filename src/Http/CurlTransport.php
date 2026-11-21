<?php

declare(strict_types=1);

namespace MaximoPay\Http;

use MaximoPay\Exception\ConnectionException;

final class CurlTransport implements Transport
{
    public function send(string $method, string $url, array $headers, ?string $body, float $timeoutSeconds): Response
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new ConnectionException("Falha ao iniciar cURL para $url");
        }
        $responseHeaders = [];
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "$name: $value";
        }
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => (int) ($timeoutSeconds * 1000),
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $at = strpos($line, ':');
                if ($at !== false) {
                    $responseHeaders[strtolower(trim(substr($line, 0, $at)))] = trim(substr($line, $at + 1));
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        $result = curl_exec($handle);
        if ($result === false) {
            $error = curl_error($handle);
            curl_close($handle);
            throw new ConnectionException("Falha ao chamar $method $url: $error");
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        return new Response($status, $responseHeaders, (string) $result);
    }
}
