<?php

namespace App\Incremental;

use App\Diagrams\CodeOverview;
use App\Enums\MediaKind;
use App\Enums\MediaStatus;
use App\Enums\ProcessingMode;
use App\Enums\ProcessingRunStatus;
use App\Models\Document;
use App\Models\MediaAsset;
use App\Models\Repository;

/**
 * Which artifacts a processed repository has and which ones are missing, read from its rows only
 * (a follow-up of Faza 9). Repositories processed by an older version of the pipeline are „ready” but
 * have only the code structure; this class is how the update triggers notice them and complete them
 * with a full processing (UpdateRepository, repositories:check-updates, repositories:process --all-incomplete).
 *
 * What is expected, and why something is not:
 *
 * - structure (files and modules): always, every processing analyzes the clone. Modules only when the
 *   analysis found files, because a module is a group of files;
 * - history (commits): always, CloneRepository refuses a repository without commits;
 * - documentation (the „Despre proiect” page): always, the plan has the overview and the README even
 *   for a repository without code (they are written from the README and the manifests);
 * - diagrams: only when the repository has an application module (code that is not tests, configuration
 *   or documentation, CodeOverview::applicationModules()), because the layers view is drawn from exactly
 *   those modules. A repository with only a README has nothing to draw;
 * - assistant (the code chunks): only when the repository has a source file (a language of
 *   CodeOverview::CODE_LANGUAGES); without one there is no code to search in;
 * - video and slides: reported, but they never make a repository incomplete. They are made by their own
 *   job on the "media" queue, with their own actions, and a full processing would not bring back a video
 *   whose render failed.
 *
 * "Current" means made for the repository's current commit (last_sha). Every run that ends well brings
 * all the pipeline artifacts to its commit (a documentation page whose input did not change is kept, and
 * is still right), so they are current when the newest such run reached last_sha; after a failed run the
 * clone may already be at a newer commit. A video or the slides are current when made from last_sha.
 */
final class RepositoryCompleteness
{
    public const STRUCTURE = 'structure';

    public const HISTORY = 'history';

    public const DOCUMENTATION = 'documentation';

    public const DIAGRAMS = 'diagrams';

    public const ASSISTANT = 'assistant';

    public const VIDEO = 'video';

    public const SLIDES = 'slides';

    /**
     * The artifact exists.
     */
    public const READY = 'ready';

    /**
     * The artifact is expected and does not exist.
     */
    public const MISSING = 'missing';

    /**
     * The artifact cannot exist for this repository (no source code, no application module).
     */
    public const NOT_APPLICABLE = 'not_applicable';

    /**
     * A video or the slides are in the queue or being rendered.
     */
    public const WORKING = 'working';

    /**
     * A video or the slides stopped with an error (the reason is in media_assets.error).
     */
    public const FAILED = 'failed';

    /**
     * The artifacts the processing pipeline makes, in its order, with their Romanian names for messages.
     * Only these make a repository incomplete.
     *
     * @var array<string, string>
     */
    public const PIPELINE_ARTIFACTS = [
        self::STRUCTURE => 'structura codului',
        self::HISTORY => 'istoricul',
        self::DOCUMENTATION => 'documentația',
        self::DIAGRAMS => 'diagramele',
        self::ASSISTANT => 'indexul asistentului',
    ];

    /**
     * @param  array<string, array{state: string, is_current: bool}>  $artifacts
     */
    private function __construct(
        private Repository $repository,
        private array $artifacts,
    ) {}

    /**
     * Look at the rows of a repository. About ten small queries, whatever the size of the repository.
     */
    public static function of(Repository $repository): self
    {
        $hasFiles = $repository->files()->exists();
        $hasSourceFiles = $repository->files()->whereIn('language', CodeOverview::CODE_LANGUAGES)->exists();
        $hasApplicationModules = $hasSourceFiles && app(CodeOverview::class)->applicationModules($repository)[0]->isNotEmpty();

        $processedSha = $repository->processingRuns()
            ->whereIn('status', [ProcessingRunStatus::Succeeded, ProcessingRunStatus::Skipped])
            ->whereNotNull('to_sha')
            ->latest('id')
            ->value('to_sha');
        $isProcessedAtCurrentCommit = $processedSha !== null && $processedSha === $repository->last_sha;

        // [expected, present] for each artifact of the pipeline.
        $pipeline = [
            self::STRUCTURE => [true, $repository->analyzed_at !== null && (! $hasFiles || $repository->modules()->exists())],
            self::HISTORY => [true, $repository->commits()->exists()],
            self::DOCUMENTATION => [true, Document::whereBelongsTo($repository)->where('slug', Document::OVERVIEW)->exists()],
            self::DIAGRAMS => [$hasApplicationModules, $repository->diagrams()->exists()],
            self::ASSISTANT => [$hasSourceFiles, $repository->codeChunks()->exists()],
        ];

        $artifacts = [];

        foreach ($pipeline as $artifact => [$isExpected, $isPresent]) {
            $artifacts[$artifact] = [
                'state' => match (true) {
                    $isPresent => self::READY,
                    $isExpected => self::MISSING,
                    default => self::NOT_APPLICABLE,
                },
                'is_current' => $isPresent && $isProcessedAtCurrentCommit,
            ];
        }

        $media = $repository->mediaAssets()->get()->keyBy(fn (MediaAsset $asset): string => $asset->kind->value);

        foreach ([self::VIDEO => MediaKind::Video, self::SLIDES => MediaKind::Slides] as $artifact => $kind) {
            $artifacts[$artifact] = self::mediaState($media->get($kind->value), $repository->last_sha);
        }

        return new self($repository, $artifacts);
    }

    /**
     * The state of the video or of the slides, from their row in media_assets (none = never started).
     *
     * @return array{state: string, is_current: bool}
     */
    private static function mediaState(?MediaAsset $asset, ?string $currentSha): array
    {
        return [
            'state' => match (true) {
                $asset === null => self::MISSING,
                $asset->status === MediaStatus::Ready => self::READY,
                $asset->status->isWorking() => self::WORKING,
                default => self::FAILED,
            },
            'is_current' => $asset?->status === MediaStatus::Ready && $asset->source_sha !== null && $asset->source_sha === $currentSha,
        ];
    }

    /**
     * The state of one artifact: READY, MISSING, NOT_APPLICABLE, or for the media also WORKING and FAILED.
     */
    public function state(string $artifact): string
    {
        return $this->artifacts[$artifact]['state'];
    }

    /**
     * Whether the artifact exists and was made for the repository's current commit.
     */
    public function isCurrent(string $artifact): bool
    {
        return $this->artifacts[$artifact]['is_current'];
    }

    /**
     * The artifacts of the pipeline that are expected and missing, in the order of the pipeline.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        return array_values(array_filter(
            array_keys(self::PIPELINE_ARTIFACTS),
            fn (string $artifact): bool => $this->state($artifact) === self::MISSING,
        ));
    }

    /**
     * Whether an artifact the pipeline makes is missing: then the repository needs a full processing.
     */
    public function isIncomplete(): bool
    {
        return $this->missing() !== [];
    }

    /**
     * The missing artifacts as Romanian words for a sentence: „documentația, diagramele și istoricul”.
     */
    public function missingText(): string
    {
        $names = array_map(fn (string $artifact): string => self::PIPELINE_ARTIFACTS[$artifact], $this->missing());
        $last = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names).' și '.$last;
    }

    /**
     * Whether the scheduled check may start a full processing by itself, at a commit it already processed.
     *
     * Only once per commit: if a full run already succeeded at the current commit and something is still
     * missing, running it again would give the same result, so the repository is left as it is (the page
     * still says what is missing, and „Regenerează tot” is still there). Without this rule a repository
     * with an artifact that cannot be made would be processed again at every check, forever.
     */
    public function healsAutomatically(): bool
    {
        return $this->isIncomplete() && ! $this->repository->processingRuns()
            ->where('mode', ProcessingMode::Full)
            ->where('status', ProcessingRunStatus::Succeeded)
            ->where('to_sha', $this->repository->last_sha)
            ->exists();
    }

    /**
     * The report for the pages (the Completeness type in TypeScript). "heals_automatically" also needs the
     * scheduled check to be turned on (UPDATES_CHECK_CRON), because the page promises that it will happen.
     *
     * @return array{is_incomplete: bool, missing: list<string>, missing_text: string, heals_automatically: bool, artifacts: array<string, array{state: string, is_current: bool}>}
     */
    public function toArray(): array
    {
        return [
            'is_incomplete' => $this->isIncomplete(),
            'missing' => $this->missing(),
            'missing_text' => $this->missingText(),
            'heals_automatically' => filled(config('gitchronicle.incremental.check_cron')) && $this->healsAutomatically(),
            'artifacts' => $this->artifacts,
        ];
    }
}
