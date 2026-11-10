<?php

namespace Tests\Feature\Policies;

use App\Enums\RepositoryStatus;
use App\Models\Repository;
use App\Models\User;
use App\Policies\RepositoryPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RepositoryPolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{RepositoryStatus, bool}>
     */
    public static function retryByStatus(): array
    {
        return [
            'pending' => [RepositoryStatus::Pending, false],
            'cloning' => [RepositoryStatus::Cloning, false],
            'analyzing' => [RepositoryStatus::Analyzing, false],
            'documenting' => [RepositoryStatus::Documenting, false],
            'ready' => [RepositoryStatus::Ready, false],
            'updating' => [RepositoryStatus::Updating, false],
            'failed' => [RepositoryStatus::Failed, true],
        ];
    }

    #[DataProvider('retryByStatus')]
    public function test_retry_is_allowed_only_after_a_failure_for_any_user(RepositoryStatus $status, bool $allowed): void
    {
        $repository = Repository::factory()->create(['status' => $status]);
        $userWhoDidNotAddIt = User::factory()->create();

        $canRetry = (new RepositoryPolicy)->retry($userWhoDidNotAddIt, $repository);

        $this->assertSame($allowed, $canRetry);
    }
}
