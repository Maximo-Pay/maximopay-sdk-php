<?php // Cobrança Pix + webhook em menos de 20 linhas. Rode com: php -S 0.0.0.0:8000 charge-and-webhook.php
require __DIR__ . '/../vendor/autoload.php';

$mp = new MaximoPay\Client(getenv('MP_APP_ID'), getenv('MP_APP_SECRET'), getenv('MP_BASE_URL'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') { // o Máximo Pay entrega o webhook aqui
    try {
        $event = MaximoPay\Webhook::constructEvent(file_get_contents('php://input'), $_SERVER, getenv('MP_WEBHOOK_SECRET'));
        if ($event->type === 'charge.paid') error_log('Pedido pago: ' . $event->data['referenceId']);
    } catch (MaximoPay\Exception\SignatureVerificationException) { http_response_code(400); }
    exit;
}

$charge = $mp->charges->create([
    'amount' => 1500, 'paymentMethod' => 'PIX', 'referenceId' => 'pedido-123',
    'customer' => ['name' => 'João Silva', 'document' => '12345678909', 'email' => 'joao@email.com'],
]);
echo 'Pix copia e cola: ' . $charge['metadata']['pixCopiaECola'];
