<?php

declare(strict_types=1);

namespace Typo3UpdateCheckWeb\Build\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Typo3UpdateCheckWeb\Build\AdvisoryDescription;

final class AdvisoryDescriptionTest extends TestCase
{
    /** @param callable(string):?string $fetch */
    private function descriptions(callable $fetch): AdvisoryDescription
    {
        return new AdvisoryDescription($fetch(...));
    }

    #[Test]
    public function readsTheProblemDescriptionFromAnOfficialBulletin(): void
    {
        $html = '<ul><li><strong>Severity:</strong><span> Medium</span></li></ul>'
            . '<h3>Problem Description</h3><p>Forms using <code>FileUpload</code> stored files in'
            . ' 1:/user_upload/ &amp; served them directly.</p><p>Default forms are not affected.</p>'
            . '<h3>Solution</h3><p>Update to TYPO3 version 14.3.9 LTS.</p>';
        $descriptions = $this->descriptions(fn (string $url): string => $html);

        $description = $descriptions->for(['link' => 'https://news.typo3.com/security/advisory/typo3-core-sa-2026-024']);

        $this->assertSame(
            'Forms using FileUpload stored files in 1:/user_upload/ & served them directly. Default forms are not affected.',
            $description,
        );
    }

    #[Test]
    public function readsTheProblemSectionFromAGithubAdvisory(): void
    {
        $requested = null;
        $descriptions = $this->descriptions(function (string $url) use (&$requested): string {
            $requested = $url;

            return (string) json_encode(['description' => "### Problem\nBackend users could modify any redirect.\n\n### Solution\nUpdate.\n"]);
        });

        $description = $descriptions->for(['link' => 'https://github.com/advisories/GHSA-6c46-p6j5-3f49']);

        $this->assertSame('https://api.github.com/advisories/GHSA-6c46-p6j5-3f49', $requested);
        $this->assertSame('Backend users could modify any redirect.', $description);
    }

    #[Test]
    public function takesTheWholeGithubDescriptionWhenItHasNoProblemHeading(): void
    {
        $descriptions = $this->descriptions(fn (string $url): string => (string) json_encode(['description' => 'An open redirect in the login form.']));

        $description = $descriptions->for(['link' => 'https://github.com/advisories/GHSA-2fhw-2j7m-mr4m']);

        $this->assertSame('An open redirect in the login form.', $description);
    }

    #[Test]
    public function aLinkOutsideTheKnownSourcesIsNeverFetched(): void
    {
        $descriptions = $this->descriptions(function (string $url): string {
            $this->fail('only official TYPO3 bulletins and GitHub advisories may be fetched');
        });

        $this->assertNull($descriptions->for(['link' => 'https://example.com/advisory/1']));
    }

    #[Test]
    public function aFailedFetchYieldsNoDescription(): void
    {
        $descriptions = $this->descriptions(fn (string $url): ?string => null);

        $this->assertNull($descriptions->for(['link' => 'https://typo3.org/security/advisory/typo3-core-sa-2020-001']));
        $this->assertNull($descriptions->for(['link' => 'https://github.com/advisories/GHSA-6c46-p6j5-3f49']));
    }

    #[Test]
    public function aBulletinWithoutAProblemDescriptionYieldsNoDescription(): void
    {
        $descriptions = $this->descriptions(fn (string $url): string => '<h3>Solution</h3><p>Update.</p>');

        $this->assertNull($descriptions->for(['link' => 'https://typo3.org/security/advisory/typo3-core-sa-2020-001']));
    }
}
