<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Advisory;

enum AdvisoryStatus
{
    case Available;
    case Unavailable;
    case NotAttempted;
}
