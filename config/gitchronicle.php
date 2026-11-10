<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI driver
    |--------------------------------------------------------------------------
    |
    | Who writes the AI texts (documentation, chat answers):
    |
    | - "claude-code": the Claude Code CLI on this computer, logged in with a Claude
    |   subscription (no API credits are spent);
    | - "claude": the Anthropic API through the official PHP SDK (needs ANTHROPIC_API_KEY);
    | - "none": no AI at all; every feature falls back to its offline mode.
    |
    | Tests always run with "none" (phpunit.xml).
    |
    */

    'ai_driver' => env('AI_DRIVER', 'none'),

    /*
    | The Claude Code executable used by the "claude-code" driver.
    */

    'claude_code_bin' => env('CLAUDE_CODE_BIN', 'claude'),

    /*
    |--------------------------------------------------------------------------
    | Claude model
    |--------------------------------------------------------------------------
    |
    | Used by both Claude drivers. "effort" sets how deeply the model thinks
    | (low, medium, high, xhigh, max); max_tokens caps one answer of the API driver.
    |
    */

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-5-5'),
        'effort' => env('ANTHROPIC_EFFORT', 'medium'),
        'max_tokens' => 16000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Generated documentation (Faza 6)
    |--------------------------------------------------------------------------
    |
    | module_context_characters: how much code (in characters) one module page may send
    | to the writer; the most important files go first and the omitted ones are listed.
    | max_module_pages: at most this many module pages per repository (the biggest modules);
    | DOCS_MAX_MODULE_PAGES lowers it locally, where each page is one Claude call on the subscription.
    |
    */

    'docs' => [
        'module_context_characters' => 60000,
        'max_module_pages' => (int) env('DOCS_MAX_MODULE_PAGES', 40),
    ],

];
