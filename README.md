# maximopay/maximopay-php

SDK do Máximo Pay para PHP 8.1+ (só `ext-curl` e `ext-json`). Troca a chave de API por token e renova sozinho, manda `Idempotency-Key` em cobranças e saques, repete com a mesma chave o que é seguro repetir e verifica a assinatura dos webhooks.

Ainda não está publicado no Packagist. Para usar agora, aponte o Composer para esta pasta:

```json
{
  "repositories": [{ "type": "path", "url": "../maximopay-sdk-php" }],
  "require": { "maximopay/maximopay-php": "*" }
}
```

## Cobrança Pix + webhook

[`examples/charge-and-webhook.php`](examples/charge-and-webhook.php) faz os dois em 18 linhas: um GET cria a cobrança e um POST recebe o webhook.

```php
$mp = new MaximoPay\Client($appId, $appSecret, 'https://api.seudominio.com.br/v1');

$charge = $mp->charges->create([
    'amount' => 1500, // centavos: R$ 15,00
    'paymentMethod' => 'PIX',
    'referenceId' => 'pedido-123',
    'customer' => ['name' => 'João Silva', 'document' => '12345678909', 'email' => 'joao@email.com'],
]);
echo $charge['metadata']['pixCopiaECola']; // e $charge['checkoutUrl']
```

`baseUrl` é obrigatório: cada operador tem o seu domínio. `currencyCode` vai como `BRL` se você não mandar.

## Recursos

| Chamada | Rota |
|---|---|
| `$mp->charges->create($params, ?$idempotencyKey)` | `POST /charges` |
| `$mp->charges->retrieve($id)` | `GET /charges/:id` |
| `$mp->charges->list(['status' => 'PAID', 'page' => 1, 'limit' => 20])` | `GET /charges` |
| `$mp->charges->cancel($id, ?$idempotencyKey)` | `POST /charges/:id/cancel` |
| `$mp->charges->refund($id, ['amount' => 500], ?$idempotencyKey)` | `POST /charges/:id/refund` (sem `amount` devolve tudo) |
| `$mp->withdrawals->create($params, ?$idempotencyKey)` | `POST /withdrawals` |
| `$mp->withdrawals->retrieve($id)` / `list(['page' => 1])` | `GET /withdrawals[/:id]` |
| `$mp->webhookEndpoints->create(['url' => …, 'secret' => …, 'events' => ['charge.paid']])` | `POST /merchants/webhooks` |
| `$mp->webhookEndpoints->list()` / `retrieve($id)` / `update($id, ['isActive' => true])` / `delete($id)` | `/merchants/webhooks[/:id]` |
| `$mp->webhookEndpoints->deliveries()` / `delivery($id)` | histórico de entregas |
| `$mp->sandbox->charges->pay($id)` / `dispute($id, ['type' => 'FRAUD', 'amount' => 500])` | só no sandbox: paga a cobrança / abre uma MED |
| `$mp->sandbox->disputes->close($id, 'LOST' \| 'WON')` | só no sandbox: encerra a MED |
| `$mp->sandbox->withdrawals->complete($id)` / `fail($id, ?$reason)` / `return($id)` | só no sandbox: conclui, falha ou devolve o saque |
| `$mp->sandbox->refunds->complete($refundId)` / `fail($refundId)` | só no sandbox: conclui ou recusa a devolução |

As respostas vêm como arrays associativos, em camelCase, com dinheiro em string de centavos (`"1500"`).

`webhookEndpoints->create` pede `events` explícito (recusa a lista vazia antes de chamar a API). Use `['*']` para todos.

Saque por chave de API só funciona com "exigir 2FA nos saques" desligado no painel; ligado, a API responde 403 `TOTP_REQUIRED`.

## Sandbox

O sandbox é a mesma API num endereço separado, com chaves `pk_test_`/`sk_test_` criadas no painel do sandbox. Para testar, troque só a URL base e a chave; nenhum dinheiro de verdade se move.

```php
$mp = new MaximoPay\Client('pk_test_...', 'sk_test_...', 'https://sandbox-api.seudominio.com.br/v1');

$charge = $mp->charges->create(['amount' => 1500, 'paymentMethod' => 'PIX', 'customer' => $customer]);
$mp->sandbox->charges->pay($charge['id']); // → PAID, e o seu webhook recebe charge.paid
```

- Toda cobrança, saque e webhook traz `livemode`: `false` no sandbox. Confira no webhook se o evento é do ambiente que você espera.
- Chave de teste na API real: 401 `TEST_KEY_ON_LIVE`, com a URL do sandbox em `$e->details['sandboxUrl']`. Chave real no sandbox: 401 `LIVE_KEY_ON_SANDBOX`.
- Saques e devoluções concluem sozinhos em uns 5 s; `withdrawals->fail` / `refunds->fail` servem para testar a falha antes disso. Cobranças só são pagas pelo comando.
- Os comandos respondem o objeto atualizado. Eles não têm chave de idempotência, então o SDK nunca os repete; repetir um comando já aplicado dá 409 `SANDBOX_INVALID_STATE` (`details['status']`). Na API real eles dão 404.

## Idempotência e repetição

Cada `create`, `cancel` e `refund` leva um `Idempotency-Key`. Sem informar, o SDK gera um UUID e usa **o mesmo** em todas as tentativas daquela chamada. Para sobreviver a uma queda do seu próprio processo, passe uma chave sua (ex. o id do pedido) e repita com ela.

O SDK repete até 2 vezes (opção `maxRetries`), com espera crescente ou o `Retry-After` da resposta: falha de rede, 5xx, 429 e 409 `IDEMPOTENCY_IN_PROGRESS`. Um `POST` sem chave (cadastro de webhook) nunca é repetido. Um 401 pede outro token uma vez e repete.

## Erros

```php
try {
    $mp->charges->create($params);
} catch (MaximoPay\Exception\MaximoPayException $e) {
    $e->statusCode;    // 422
    $e->errorCode;     // 'KYC_LIMIT_EXCEEDED': use este no seu código, nunca a mensagem
    $e->details;       // ['available' => '…', 'resetsAt' => '…']
    $e->correlationId; // informe ao suporte
} catch (MaximoPay\Exception\ConnectionException $e) {
    // não houve resposta depois das tentativas: repita mais tarde com a mesma Idempotency-Key
}
```

## Webhooks

```php
try {
    $event = MaximoPay\Webhook::constructEvent(file_get_contents('php://input'), $_SERVER, $webhookSecret);
} catch (MaximoPay\Exception\SignatureVerificationException) {
    http_response_code(400);
    exit;
}
$event->type; // 'charge.paid'
$event->id;   // X-Delivery-Id: o mesmo em cada nova tentativa, use para ignorar repetidas
$event->data; // o corpo como array
```

Passe o corpo **cru** (`php://input`), nunca um `json_encode` do que você já decodificou. Os cabeçalhos podem vir de `$_SERVER`, `getallheaders()` ou `$request->getHeaders()` (PSR-7), em qualquer caixa. A entrega com mais de 5 minutos é recusada. `Webhook::verifySignature($rawBody, $header, $secret)` devolve só `true`/`false`.

Responda `2xx` rápido; sem isso a entrega é tentada de novo.

## Opções

```php
new MaximoPay\Client($appId, $appSecret, $baseUrl, [
    'maxRetries' => 2,   // tentativas extras
    'timeout' => 30.0,   // segundos por requisição
    'transport' => $meuTransporte, // MaximoPay\Http\Transport, ex. para testes
]);
```

## Desenvolvimento

```bash
composer install
composer test
```

Os testes rodam os vetores de assinatura de `contract/webhook-signature-vectors.json`, cópia do arquivo que a API testa (`sdks/contract` no fintech-baas-api). Se a assinatura mudar na API, atualize a cópia aqui.

## Licença

MIT. Veja [LICENSE](LICENSE).
