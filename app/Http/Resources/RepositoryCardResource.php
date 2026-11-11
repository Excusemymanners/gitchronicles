<?php

namespace App\Http\Resources;

use App\Enums\MediaKind;
use App\Enums\MediaStatus;
use App\Enums\ProcessingRunStatus;
use App\Models\Document;
use App\Models\Repository;
use App\Models\RepositoryFile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A repository as a card of the home page gallery (the RepositoryCard type in TypeScript):
 * everything RepositoryResource sends, plus "facts" — real numbers about what was processed
 * (files, modules, commits, main languages) and which chapters exist (documentation, diagrams,
 * assistant, video).
 *
 * The facts are aggregates, loaded for the whole list at once: withFacts() adds them to the
 * repositories query as sub-selects and cards() reads the languages of all repositories in one
 * more query. So the home page runs two queries, however many repositories it lists.
 *
 * @mixin Repository
 */
class RepositoryCardResource extends JsonResource
{
    /**
     * How many languages a card names; the rest are drawn together as „Altele”.
     */
    public const SHOWN_LANGUAGES = 3;

    /**
     * The lines of code of each language of this repository, the biggest first.
     *
     * @var Collection<int, object{language: string, line_count: int|string}>
     */
    private ?Collection $languageLines = null;

    /**
     * Add to a repositories query the aggregates a card shows: one sub-select each, no extra query.
     *
     * @param  Builder<Repository>  $query
     * @return Builder<Repository>
     */
    public static function withFacts(Builder $query): Builder
    {
        return $query
            ->withCount(['files', 'modules', 'commits', 'diagrams'])
            ->withSum('files as line_count', 'lines')
            ->withExists([
                'codeChunks as has_assistant',
                'mediaAssets as has_video' => fn (Builder $media) => $media
                    ->where('kind', MediaKind::Video)
                    ->where('status', MediaStatus::Ready),
            ])
            // When the last successful processing ended: the moment the repository was brought up to date.
            ->withMax(
                ['processingRuns as processed_at' => fn (Builder $runs) => $runs->where('status', ProcessingRunStatus::Succeeded)],
                'finished_at',
            )
            ->addSelect([
                'documents_count' => Document::query()
                    ->selectRaw('count(*)')
                    ->whereColumn('repository_id', 'repositories.id'),
            ]);
    }

    /**
     * Turn the repositories loaded through withFacts() into the cards of the home page.
     * The languages of all of them are read here, in a single grouped query.
     *
     * @param  EloquentCollection<int, Repository>  $repositories
     * @return list<array<string, mixed>>
     */
    public static function cards(EloquentCollection $repositories): array
    {
        if ($repositories->isEmpty()) {
            return [];
        }

        $languageLines = RepositoryFile::query()
            ->toBase()
            ->whereIn('repository_id', $repositories->modelKeys())
            ->whereNotNull('language')
            ->selectRaw('repository_id, language, sum(lines) as line_count')
            ->groupBy('repository_id', 'language')
            ->orderByDesc('line_count')
            ->orderBy('language')
            ->get()
            ->groupBy('repository_id');

        return $repositories
            ->map(fn (Repository $repository): array => static::make($repository)
                ->withLanguageLines($languageLines->get($repository->id, collect()))
                ->resolve())
            ->values()
            ->all();
    }

    /**
     * Give the card the lines of code of each language of its repository.
     *
     * @param  Collection<int, object{language: string, line_count: int|string}>  $languageLines
     */
    public function withLanguageLines(Collection $languageLines): static
    {
        $this->languageLines = $languageLines;

        return $this;
    }

    /**
     * Transform the resource into an array.
     *
     * A language's "share" is its part of all the lines of the repository, between 0 and 1.
     * Lines in files of an unknown language are in the total, so the shares may add up to less than 1.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $lineCount = (int) $this->line_count;

        return [
            ...RepositoryResource::make($this->resource)->toArray($request),
            'facts' => [
                'file_count' => (int) $this->files_count,
                'line_count' => $lineCount,
                'module_count' => (int) $this->modules_count,
                'commit_count' => (int) $this->commits_count,
                'document_count' => (int) $this->documents_count,
                'diagram_count' => (int) $this->diagrams_count,
                'has_assistant' => (bool) $this->has_assistant,
                'has_video' => (bool) $this->has_video,
                'languages' => ($this->languageLines ?? collect())
                    ->take(self::SHOWN_LANGUAGES)
                    ->map(fn (object $language): array => [
                        'name' => $language->language,
                        'share' => $lineCount > 0 ? round((int) $language->line_count / $lineCount, 4) : 0.0,
                    ])
                    ->values()
                    ->all(),
                'processed_at' => $this->processed_at === null
                    ? null
                    : Carbon::parse($this->processed_at)->toIso8601String(),
            ],
        ];
    }
}
