<?php

namespace Tests\Unit\Consulting;

use App\Consulting\Runtime\VersionConstraint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A version range is read as the lowest version it allows.
 */
class VersionConstraintTest extends TestCase
{
    /**
     * @return array<string, array{string, string|null, bool}>
     */
    public static function constraints(): array
    {
        return [
            'caret' => ['^8.2', '8.2', true],
            'tilde with patch' => ['~8.2.1', '8.2.1', true],
            'at least' => ['>=18', '18', true],
            'at least with a space' => ['>= 7.4', '7.4', true],
            'wildcard' => ['8.1.*', '8.1.0', true],
            'x range' => ['18.x', '18.0', true],
            'several alternatives take the lowest' => ['^8.0 || ^7.4', '7.4', true],
            'composer pipe' => ['^7.4|^8.0', '7.4', true],
            'python range' => ['>=3.8,<4', '3.8', true],
            'ruby pessimistic' => ['~> 3.1', '3.1', true],
            'upper bound only says nothing' => ['<9', null, false],
            'exact version' => ['20.11.0', '20.11.0', false],
            'exact with a v' => ['v20.11.0', '20.11.0', false],
            'exact major' => ['20', '20', false],
            'not a version' => ['lts/*', null, false],
            'any version' => ['*', null, false],
        ];
    }

    #[DataProvider('constraints')]
    public function test_it_returns_the_lowest_allowed_version(string $constraint, ?string $version, bool $isRange): void
    {
        $lowest = VersionConstraint::lowest($constraint);

        $this->assertSame($version, $lowest['version'] ?? null);

        if ($version !== null) {
            $this->assertSame($isRange, $lowest['is_range']);
        }
    }
}
