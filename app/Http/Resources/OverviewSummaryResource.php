<?php

namespace App\Http\Resources;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The „Despre proiect” section of the „Prezentare” tab (the OverviewSummary type in TypeScript): the first
 * sections of the generated overview page and the link to the whole documentation.
 *
 * @mixin Document
 */
class OverviewSummaryResource extends JsonResource
{
    /**
     * How many "## " sections of the overview are shown („Ce face proiectul” and „Tehnologii”).
     */
    public const SECTIONS = 2;

    /**
     * Transform the resource into an array.
     *
     * @return array{markdown: string, url: string, is_stale: bool}
     */
    public function toArray(Request $request): array
    {
        return [
            'markdown' => $this->firstSections(),
            'url' => route('repositories.docs', ['repository' => $this->repository_id], absolute: false),
            'is_stale' => $this->is_stale,
        ];
    }

    /**
     * The overview without its "# " title and cut before its third "## " section. The "## " headings
     * become "### ", because on the Prezentare tab they sit inside a section that has its own title.
     */
    private function firstSections(): string
    {
        $kept = [];
        $sections = 0;
        $inCodeBlock = false;

        foreach (preg_split('/\R/', $this->markdown) ?: [] as $line) {
            if (str_starts_with($line, '```')) {
                $inCodeBlock = ! $inCodeBlock;
            }

            if (! $inCodeBlock && preg_match('/\A# /', $line) === 1) {
                continue;
            }

            if (! $inCodeBlock && preg_match('/\A## /', $line) === 1) {
                if (++$sections > self::SECTIONS) {
                    break;
                }

                $line = '#'.$line;
            }

            $kept[] = $line;
        }

        return trim(implode("\n", $kept));
    }
}
