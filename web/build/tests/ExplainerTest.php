<?php

declare(strict_types=1);

namespace Typo3UpdateCheckWeb\Build\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Typo3UpdateCheckWeb\Build\Explainer;

final class ExplainerTest extends TestCase
{
    #[Test]
    public function rejectsAPlainImpactLongerThanTwoSentences(): void
    {
        $this->assertNull(Explainer::validated(
            'Uploaded files can land in a public folder. Anyone with the address can open them. No check applies.',
            'Only matters for forms with a custom upload folder.',
        ));
    }

    #[Test]
    public function rejectsAnUrgencyLongerThanTwoSentences(): void
    {
        $this->assertNull(Explainer::validated(
            'Uploaded files can land in a public folder.',
            'Schedule this soon. It only matters for upload forms. Default forms are fine.',
        ));
    }

    #[Test]
    public function doesNotCountAbbreviationsAsSentenceEnds(): void
    {
        $explanation = Explainer::validated(
            'Hochgeladene Dateien, z. B. Bilder, können in einem öffentlichen Ordner landen. Jeder mit der Adresse kann sie öffnen.',
            'Only matters for forms with a custom folder, e.g. a protected storage. Default forms are not affected.',
        );

        $this->assertNotNull($explanation);
    }
}
