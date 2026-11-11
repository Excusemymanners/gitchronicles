<?php

namespace App\Models;

use Database\Factories\StarSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The stars and forks of a watched repository on one day, as GitHub reported them that day.
 *
 * captured_on is a plain "Y-m-d" string, not a date cast: a date cast would store "Y-m-d 00:00:00" on
 * SQLite and the comparisons with a day would no longer match. Comparing the strings works on every database.
 */
#[Fillable(['captured_on', 'stars', 'forks'])]
class StarSnapshot extends Model
{
    /** @use HasFactory<StarSnapshotFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stars' => 'integer',
            'forks' => 'integer',
        ];
    }

    /**
     * The repository whose stars were counted.
     *
     * @return BelongsTo<WatchedRepository, $this>
     */
    public function watchedRepository(): BelongsTo
    {
        return $this->belongsTo(WatchedRepository::class);
    }
}
