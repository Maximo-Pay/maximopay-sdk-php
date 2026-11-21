<?php

declare(strict_types=1);

namespace MaximoPay\Exception;

/** O webhook não veio do Máximo Pay, foi alterado ou é antigo demais. Responda 400. */
class SignatureVerificationException extends \RuntimeException
{
}
