<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Repository Messages
    |--------------------------------------------------------------------------
    |
    | The messages of the add, retry, update and delete flows
    | (RepositoryController and StoreRepositoryRequest). "flash" messages are
    | shown as a toast after the redirect.
    |
    */

    'invalid_url' => 'Enter a GitHub link like https://github.com/owner/name.',

    'flash' => [
        'already_added' => 'This repository was already added.',
        'log_in_to_add' => 'Log in and we will add :repository.',
        'added' => 'Repository added.',
        'added_pending' => 'Added :repository.',
        'retry_started' => 'Processing started again.',
        'checking_updates' => 'Checking GitHub for new commits.',
        'deleted' => 'Repository deleted.',
        'regenerate_started' => 'Full regeneration started.',
        'media_started' => 'Video and slides generation started.',
        'consulting_started' => 'Analysis started. It usually takes under a minute.',
    ],

];
