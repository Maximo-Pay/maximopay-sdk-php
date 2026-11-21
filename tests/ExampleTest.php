<?php

declare(strict_types=1);

namespace MaximoPay\Tests;

use PHPUnit\Framework\TestCase;

final class ExampleTest extends TestCase
{
    public function testOExemploTemMenosDe20Linhas(): void
    {
        $lines = array_filter(file(__DIR__ . '/../examples/charge-and-webhook.php', FILE_IGNORE_NEW_LINES), static fn (string $l) => trim($l) !== '');
        self::assertLessThan(20, count($lines));
        $code = (string) file_get_contents(__DIR__ . '/../examples/charge-and-webhook.php');
        self::assertStringContainsString('Webhook::constructEvent', $code);
        self::assertStringContainsString('charges->create', $code);
    }
}
