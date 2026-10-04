<?php

namespace App\Support\StateMachine;

use Symfony\Component\HttpKernel\Exception\HttpException;

class InvalidTransition extends HttpException
{
    public function __construct(string $model, ?string $from, string $to)
    {
        parent::__construct(409, "Переход {$model}: «{$from}» → «{$to}» недопустим.");
    }
}
