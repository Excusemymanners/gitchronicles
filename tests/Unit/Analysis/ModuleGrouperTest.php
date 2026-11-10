<?php

namespace Tests\Unit\Analysis;

use App\Analysis\ModuleGrouper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ModuleGrouperTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function filesAndTheirModules(): array
    {
        return [
            'file in the root folder' => ['README.md', '/'],
            'file one level deep' => ['routes/web.php', 'routes'],
            'file two levels deep' => ['app/Models/User.php', 'app/Models'],
            'file four levels deep' => ['app/Http/Controllers/Auth/LoginController.php', 'app/Http'],
        ];
    }

    #[DataProvider('filesAndTheirModules')]
    public function test_a_file_belongs_to_its_folder_cut_to_two_levels(string $path, string $module): void
    {
        $modules = ModuleGrouper::group([$path]);

        $this->assertSame([$path => $module], $modules);
    }

    public function test_a_module_of_forty_files_stays_whole(): void
    {
        $paths = $this->files('app/Http/Controllers', 40);

        $modules = ModuleGrouper::group($paths);

        $this->assertSame(['app/Http'], array_values(array_unique($modules)));
    }

    public function test_a_module_with_more_than_forty_files_is_split_one_level_deeper(): void
    {
        $paths = [
            ...$this->files('app/Http/Controllers', 30),
            ...$this->files('app/Http/Middleware', 10),
            'app/Http/Kernel.php',
            'app/Models/User.php',
        ];

        $modules = ModuleGrouper::group($paths);

        $this->assertSame('app/Http/Controllers', $modules['app/Http/Controllers/File1.php']);
        $this->assertSame('app/Http/Middleware', $modules['app/Http/Middleware/File1.php']);
        $this->assertSame('app/Http', $modules['app/Http/Kernel.php']);
        $this->assertSame('app/Models', $modules['app/Models/User.php']);
    }

    public function test_a_module_is_split_only_once_even_when_a_part_is_still_too_big(): void
    {
        $paths = $this->files('src/Domain/Billing/Invoices', 45);

        $modules = ModuleGrouper::group($paths);

        $this->assertSame(['src/Domain/Billing'], array_values(array_unique($modules)));
    }

    public function test_the_root_module_keeps_all_root_files_even_when_there_are_many(): void
    {
        $paths = array_map(fn (int $number): string => "file{$number}.txt", range(1, 45));

        $modules = ModuleGrouper::group($paths);

        $this->assertSame(['/'], array_values(array_unique($modules)));
    }

    /**
     * The paths of $count files named File1.php, File2.php, ... in the given folder.
     *
     * @return list<string>
     */
    private function files(string $folder, int $count): array
    {
        return array_map(fn (int $number): string => "{$folder}/File{$number}.php", range(1, $count));
    }
}
