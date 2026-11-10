<?php

namespace App\Models;

use App\Enums\RepositoryStatus;
use Database\Factories\RepositoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A public GitHub repository: one record per repository (the URL is unique),
 * visible to everyone, with or without an account.
 *
 * `user_id` is the user who added it. It is not fillable, so a request can never
 * choose it: the code sets it through the relationship ($user->repositories()->create()).
 *
 * `last_sha` is the last processed commit: the clone job fills it (Faza 3)
 * and the incremental update starts from it (Faza 9).
 */
#[Fillable(['url', 'owner', 'name', 'status', 'last_sha'])]
class Repository extends Model
{
    /** @use HasFactory<RepositoryFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RepositoryStatus::class,
        ];
    }

    /**
     * The user who added the repository: null when that user deleted their account.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
