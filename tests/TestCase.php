<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    /**
     * Every test gets a temporary "local" disk (storage/framework/testing/disks/local).
     * Deleting a repository deletes its clone folder, so without this a test that deletes
     * repository 1 would delete the real clone of repository 1 of the development app.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        Storage::fake('local');
    }

    /**
     * Skip a test that runs the real code analyzer (Python + tree-sitter) when it is not installed.
     */
    protected function skipUnlessAnalyzerIsInstalled(): void
    {
        if (! is_file(config('services.analyzer.python'))) {
            $this->markTestSkipped('The code analyzer is not installed (analyzer/.venv is missing). Run: composer analyzer:install');
        }
    }
}
