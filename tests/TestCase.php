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
}
