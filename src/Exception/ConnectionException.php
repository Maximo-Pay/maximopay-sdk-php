<?php

declare(strict_types=1);

namespace MaximoPay\Exception;

/** A requisição não chegou a ter resposta (rede, DNS, tempo esgotado). */
class ConnectionException extends \RuntimeException
{
}
