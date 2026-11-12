<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Error Messages
    |--------------------------------------------------------------------------
    |
    | What App\Ai\AiException and its subclasses say when the text could not be
    | written (shown in the assistant's notice and kept in some stored reasons).
    |
    */

    'generic' => 'Claude couldn’t write the text right now. Try again later.',
    'limit' => 'The Claude subscription limit has been reached; try again later.',
    'too_slow' => 'Claude took too long to answer, so the request was stopped. Try again later.',
    'too_long' => 'Claude’s answer was too long and stopped halfway.',
    'refused' => 'Claude refused to write this text.',
    'budget_reached' => 'The daily AI budget was reached — these pages were written from templates; regenerate tomorrow or raise AI_DAILY_CALLS.',
    'disabled' => 'AI generation is turned off on this server.',

];
