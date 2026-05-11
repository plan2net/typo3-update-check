<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Release;

final class ApiFailureException extends \RuntimeException
{
    public function __construct(public readonly ApiFailure $failure)
    {
        parent::__construct($failure->detail);
    }
}
