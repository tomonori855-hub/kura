<?php

namespace Kura\Exceptions;

class CacheInconsistencyException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $table = '',
        public readonly int|string|null $recordId = null,
    ) {
        parent::__construct($message);
    }
}
