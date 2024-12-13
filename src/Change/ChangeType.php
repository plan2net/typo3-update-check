<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Change;

enum ChangeType: string
{
    case BREAKING = '!!!';
    case SECURITY = 'SECURITY';
    case REGULAR = 'regular';
}
