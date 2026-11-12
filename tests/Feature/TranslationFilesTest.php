<?php

namespace Tests\Feature;

use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The translations of every supported language stay in step: each language has its lang/ folder with
 * the same files and the same keys as lang/en, and its front-end dictionary (resources/js/lang/{code}.ts).
 * That the front-end dictionaries have the same keys is checked by TypeScript (`npx tsc --noEmit`):
 * every dictionary is typed against the English one, so a missing or extra key does not compile.
 */
class TranslationFilesTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function otherLocales(): array
    {
        // A data provider runs before the application boots, so the list is read from the config file itself.
        $locales = array_keys((require __DIR__.'/../../config/app.php')['supported_locales']);

        return collect($locales)
            ->reject(fn (string $locale): bool => $locale === 'en')
            ->mapWithKeys(fn (string $locale): array => [$locale => [$locale]])
            ->all();
    }

    #[DataProvider('otherLocales')]
    public function test_each_language_has_the_same_files_and_keys_as_english(string $locale): void
    {
        $this->assertSame($this->files('en'), $this->files($locale), "lang/{$locale} must have the same files as lang/en.");

        foreach ($this->files('en') as $file) {
            $this->assertSame(
                $this->keys("en/{$file}"),
                $this->keys("{$locale}/{$file}"),
                "lang/{$locale}/{$file} must have the same keys as lang/en/{$file}.",
            );
        }
    }

    public function test_every_supported_language_has_its_lang_folder_and_front_end_dictionary(): void
    {
        foreach (array_keys(config('app.supported_locales')) as $locale) {
            $this->assertDirectoryExists(lang_path($locale));
            $this->assertFileExists(resource_path("js/lang/{$locale}.ts"));
        }
    }

    /**
     * The PHP files of one language folder, sorted.
     *
     * @return list<string>
     */
    private function files(string $locale): array
    {
        $files = array_map('basename', glob(lang_path("{$locale}/*.php")));
        sort($files);

        return $files;
    }

    /**
     * Every key of a translation file in dot notation ("flash.added"), sorted.
     *
     * @return list<string>
     */
    private function keys(string $file): array
    {
        $keys = array_keys(Arr::dot(require lang_path($file)));
        sort($keys);

        return $keys;
    }
}
