<?php

namespace App\Consulting;

use Illuminate\Support\Facades\File;

/**
 * When the community Semgrep rules were refreshed and from which revisions: the small file scanners/rules-version.json
 * that scanners/update-rules.sh writes next to the rules. Without it (the script never ran) the rules are only our own,
 * as they were installed, and the report says the date is unknown.
 */
final class RulesVersion
{
    /**
     * The date (ISO 8601, UTC) and the revisions of the last refresh, or null when it never ran or the file is unreadable.
     *
     * @return array{updated_at: string, revision: string, sources: int}|null
     */
    public static function current(): ?array
    {
        $path = (string) config('gitchronicle.consulting.rules_version_file');

        if (! is_file($path)) {
            return null;
        }

        $data = json_decode(File::get($path), true);

        if (! is_array($data) || ! is_string($data['updated_at'] ?? null)) {
            return null;
        }

        $sources = collect($data['sources'] ?? [])->filter(fn (mixed $source): bool => is_array($source) && is_string($source['name'] ?? null) && is_string($source['revision'] ?? null));

        return [
            'updated_at' => $data['updated_at'],
            'revision' => $sources->map(fn (array $source): string => $source['name'].' '.substr($source['revision'], 0, 7))->implode(', '),
            'sources' => $sources->count(),
        ];
    }
}
