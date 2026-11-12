<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Video and Slides Messages
    |--------------------------------------------------------------------------
    |
    | Sentences the video and slides jobs save in media_assets (error and meta) as a StoredMessage
    | and the interface shows later, in the language of the visitor.
    |
    */

    'errors' => [
        'not_installed' => 'The tools for the video and the slides are not installed on the server (composer media:install).',
        'slides_failed' => 'The slides could not be generated. The details are in the application log.',
        'video_failed' => 'The video could not be rendered. The details are in the application log.',
        'generic' => 'The file could not be generated. Try again later.',
    ],

    'capture' => [
        'unreachable' => 'GitHub could not be reached when the video was made.',
        'timeout' => 'The GitHub page loaded too slowly when the video was made.',
        'http_status' => 'GitHub did not show the repository page (it may no longer be public).',
        'unexpected_page' => 'The GitHub page did not look like a repository page, so it was not used.',
        'browser_failed' => 'Chrome did not start on the server, so the GitHub page could not be photographed.',
        'disabled' => 'Photographing the GitHub page is turned off on this server.',
        'failed' => 'The GitHub page could not be photographed.',
    ],

    'script' => [
        'no_readme' => 'The repository has no README or description to write the texts from.',
        'ai_off' => 'AI generation is turned off, so the texts come straight from the description, the README and the numbers.',
        'ai_unusable' => 'The AI answer could not be used, so the texts come straight from the description, the README and the numbers.',
        'start_text_missing' => 'The text is missing in one of the languages or is longer than :limit characters.',
        'command_missing' => 'The command is missing or too long for the screen.',
        'command_not_in_readme' => 'The command does not appear exactly in the README.',
        'scene_missing' => 'The scene is missing from the answer.',
        'language_text_missing' => 'The :language text is missing or is longer than :limit characters.',
        'quote_missing' => 'The source quote does not appear in the texts that were sent.',
        'invented_number' => 'The text says the number :number, which does not appear in the source quote.',
    ],

];
