<?php

namespace Fdmind\StatamicLibvips\Exceptions;

use RuntimeException;
use Throwable;

class VipsException extends RuntimeException
{
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
