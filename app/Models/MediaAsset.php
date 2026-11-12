<?php

namespace App\Models;

use App\Enums\MediaKind;
use App\Enums\MediaLanguage;
use App\Enums\MediaStatus;
use Database\Factories\MediaAssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

/**
 * The presentation video or the slides of a repository (bonus): one row per repository, kind and language —
 * the video exists once for every language of App\Enums\MediaLanguage, the slides once.
 *
 * The files live on the "local" disk (storage/app/private), in media/{repository_id}/: "path" is the main
 * file (video-en.mp4, slides.pdf) and meta["files"] lists every file by format ("mp4", "poster", "vtt",
 * "pdf", "pptx"), which is what the download route serves. They are deleted together with the repository.
 *
 * repository_id is not fillable: a row is found or made through the relationship, with
 * $repository->videoAsset($language) or $repository->slidesAsset().
 */
#[Fillable(['kind', 'language', 'status', 'path', 'source_sha', 'duration_ms', 'size_bytes', 'error', 'meta', 'generated_at'])]
class MediaAsset extends Model
{
    /** @use HasFactory<MediaAssetFactory> */
    use HasFactory;

    /**
     * The message for the page when a job fails without a better explanation (the details go to the log).
     */
    public const GENERIC_ERROR = 'Nu am putut genera fișierul. Încearcă din nou mai târziu.';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => MediaKind::class,
            'language' => MediaLanguage::class,
            'status' => MediaStatus::class,
            'meta' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    /**
     * The repository presented by this video or these slides.
     *
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /**
     * The path of one file of this asset on the "local" disk ("mp4", "poster", "vtt", "pdf", "pptx"),
     * or null when the asset is not ready or has no such file.
     */
    public function file(string $format): ?string
    {
        if ($this->status !== MediaStatus::Ready) {
            return null;
        }

        return $this->meta['files'][$format] ?? null;
    }

    /**
     * Mark the asset as failed, with a message for the page. A failure of a job must never break
     * anything else, so a database error here is only reported.
     */
    public function markFailed(string $message): void
    {
        try {
            $this->update(['status' => MediaStatus::Failed, 'error' => $message]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
