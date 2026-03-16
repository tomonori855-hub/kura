<?php

namespace Kura\Loader;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Kura\Contracts\VersionResolverInterface;

/**
 * Resolves the active version string from a versions.csv file.
 *
 * versions.csv format:
 *   id,version,activated_at
 *   1,v1.0.0,2024-01-01 00:00:00
 *
 * Resolution rule: the latest version whose activated_at <= $now.
 */
final class CsvVersionResolver implements VersionResolverInterface
{
    public function __construct(
        private readonly string $versionsFilePath,
        private readonly ?DateTimeInterface $defaultNow = null,
    ) {}

    public function resolve(): ?string
    {
        return $this->resolveVersion();
    }

    public function resolveVersion(?DateTimeInterface $now = null): ?string
    {
        $now ??= $this->defaultNow ?? new DateTimeImmutable;

        if (! file_exists($this->versionsFilePath)) {
            return null;
        }

        $fp = fopen($this->versionsFilePath, 'r');
        if ($fp === false) {
            return null;
        }

        // Skip header row
        fgetcsv($fp, escape: '');

        $resolved = null;
        $resolvedAt = null;

        while (($row = fgetcsv($fp, escape: '')) !== false) {
            if (count($row) < 3) {
                continue;
            }

            $startAt = $row[2] !== null
                ? DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $row[2], new DateTimeZone('UTC'))
                : false;
            if ($startAt === false) {
                continue;
            }

            if ($startAt > $now) {
                continue;
            }

            if ($resolvedAt === null || $startAt > $resolvedAt) {
                $resolved = $row[1];
                $resolvedAt = $startAt;
            }
        }

        fclose($fp);

        return $resolved;
    }
}
