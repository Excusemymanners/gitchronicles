<?php

namespace Database\Factories;

use App\Enums\MediaKind;
use App\Enums\MediaStatus;
use App\Models\MediaAsset;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaAsset>
 */
class MediaAssetFactory extends Factory
{
    /**
     * Define the model's default state: a video still waiting in the queue.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'repository_id' => Repository::factory()->ready(),
            'kind' => MediaKind::Video,
            'status' => MediaStatus::Pending,
            'source_sha' => fake()->sha1(),
        ];
    }

    /**
     * Indicate that the video was made: its files are in the repository's media folder
     * (a test that serves them puts them on the fake disk).
     */
    public function readyVideo(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => MediaKind::Video,
            'status' => MediaStatus::Ready,
            'duration_ms' => 31_200,
            'size_bytes' => 1_048_576,
            'generated_at' => now(),
        ])->afterMaking(function (MediaAsset $asset): void {
            // The paths need the repository's id, known only after the factory resolved the repository.
            $directory = "media/{$asset->repository_id}";

            $asset->path = "{$directory}/video.mp4";
            $asset->meta = [
                'files' => ['mp4' => "{$directory}/video.mp4", 'poster' => "{$directory}/poster.jpg", 'vtt' => "{$directory}/captions.vtt"],
                'writer' => 'ai',
                'note' => null,
                'voice' => 'Piper · ro_RO-mihai-medium',
                'scenes' => [],
                ...($asset->meta ?? []),
            ];
        });
    }

    /**
     * Indicate that the slides were made: a PDF and a PPTX in the repository's media folder.
     */
    public function readySlides(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => MediaKind::Slides,
            'status' => MediaStatus::Ready,
            'size_bytes' => 524_288,
            'generated_at' => now(),
        ])->afterMaking(function (MediaAsset $asset): void {
            $directory = "media/{$asset->repository_id}";

            $asset->path = "{$directory}/slides.pdf";
            $asset->meta = [
                'files' => ['pdf' => "{$directory}/slides.pdf", 'pptx' => "{$directory}/slides.pptx"],
                'slides' => ['magazin', 'Ce face'],
                ...($asset->meta ?? []),
            ];
        });
    }

    /**
     * Indicate that the job stopped with an error.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => MediaStatus::Failed,
            'error' => 'Randarea video-ului a eșuat.',
        ]);
    }
}
