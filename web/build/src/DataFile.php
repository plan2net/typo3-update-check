<?php

declare(strict_types=1);

namespace Typo3UpdateCheckWeb\Build;

/**
 * Single definition of the on-disk JSON format for every generated data file — build, stamp,
 * and any future writer must produce byte-identical formatting for identical content.
 */
final class DataFile
{
    public const ENCODE_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * @param array<string,mixed> $data
     */
    public static function write(string $path, array $data): void
    {
        $written = @file_put_contents($path, json_encode($data, self::ENCODE_FLAGS) . "\n");
        if ($written === false) {
            throw new \RuntimeException("Could not write {$path}");
        }
    }
}
