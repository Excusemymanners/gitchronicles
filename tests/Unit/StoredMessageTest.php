<?php

namespace Tests\Unit;

use App\Support\StoredMessage;
use Tests\TestCase;

class StoredMessageTest extends TestCase
{
    public function test_a_key_and_its_values_are_stored_as_json(): void
    {
        $stored = StoredMessage::make('repositories.errors.clone_timeout', ['minutes' => 4]);

        $this->assertSame(
            ['key' => 'repositories.errors.clone_timeout', 'params' => ['minutes' => 4]],
            json_decode($stored, true),
        );
    }

    public function test_a_stored_message_is_rendered_in_the_language_of_the_request(): void
    {
        $stored = StoredMessage::make('repositories.errors.clone_timeout', ['minutes' => 4]);

        app()->setLocale('en');
        $this->assertSame('Cloning took more than 4 minutes, so it was stopped. The repository is probably too large.', StoredMessage::render($stored));

        app()->setLocale('ro');
        $this->assertSame('Clonarea a durat mai mult de 4 minute, așa că am oprit-o. Repository-ul este probabil prea mare.', StoredMessage::render($stored));
    }

    public function test_a_message_without_values_is_rendered(): void
    {
        app()->setLocale('en');

        $this->assertSame('The repository has no commits yet, so there is nothing to document.', StoredMessage::render(StoredMessage::make('repositories.errors.no_commits')));
    }

    public function test_a_plain_string_from_an_older_row_is_returned_unchanged(): void
    {
        app()->setLocale('en');

        $this->assertSame('Nu am putut clona repository-ul.', StoredMessage::render('Nu am putut clona repository-ul.'));
        $this->assertSame('{"unrelated":true}', StoredMessage::render('{"unrelated":true}'));
        $this->assertSame('42', StoredMessage::render('42'));
    }

    public function test_nothing_stays_nothing(): void
    {
        $this->assertNull(StoredMessage::render(null));
    }
}
