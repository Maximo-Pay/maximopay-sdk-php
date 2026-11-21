<?php

declare(strict_types=1);

namespace MaximoPay;

/** Uma entrega de webhook já verificada. */
final class WebhookEvent
{
    /**
     * @param string $id X-Delivery-Id: o mesmo em cada nova tentativa, use para ignorar repetidas
     * @param string $type X-Event, ex. charge.paid
     * @param array<string, mixed> $data o corpo do evento
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly array $data,
    ) {
    }
}
