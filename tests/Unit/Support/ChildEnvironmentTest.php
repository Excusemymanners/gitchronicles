<?php

namespace Tests\Unit\Support;

use App\Support\ChildEnvironment;
use Tests\TestCase;

class ChildEnvironmentTest extends TestCase
{
    public function test_only_keeping_removes_every_variable_of_the_worker_except_the_basic_ones(): void
    {
        $_ENV['DB_PASSWORD'] = 'hunter2';

        try {
            $environment = ChildEnvironment::onlyKeeping();
        } finally {
            unset($_ENV['DB_PASSWORD']);
        }

        $this->assertFalse($environment['DB_PASSWORD']);
        $this->assertArrayNotHasKey('PATH', $environment);
    }

    public function test_without_secrets_removes_the_application_keys_but_keeps_home_and_the_login_of_a_tool(): void
    {
        $_ENV['APP_KEY'] = 'base64:x';
        $_ENV['AWS_SECRET_ACCESS_KEY'] = 'y';
        $_ENV['CLAUDE_CODE_OAUTH_TOKEN'] = 'z';

        try {
            $environment = ChildEnvironment::withoutSecrets();
        } finally {
            unset($_ENV['APP_KEY'], $_ENV['AWS_SECRET_ACCESS_KEY'], $_ENV['CLAUDE_CODE_OAUTH_TOKEN']);
        }

        $this->assertFalse($environment['APP_KEY']);
        $this->assertFalse($environment['AWS_SECRET_ACCESS_KEY']);
        $this->assertFalse($environment['ANTHROPIC_API_KEY']);
        $this->assertArrayNotHasKey('CLAUDE_CODE_OAUTH_TOKEN', $environment);
        $this->assertArrayNotHasKey('HOME', $environment);
    }
}
