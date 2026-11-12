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

    // The language of the files whose language is unknown, in „Code structure” (CodeStructureResource).
    'unknown_language' => 'Other',

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

    /*
    | Stored failure messages: saved in repositories.error as App\Support\StoredMessage
    | and shown in the language of the visitor.
    */

    'errors' => [
        'unexpected' => 'An unexpected error happened while processing. Try again.',
        'no_commits' => 'The repository has no commits yet, so there is nothing to document.',
        'not_public' => 'The repository does not exist on GitHub or it is private. GitChronicle can only clone public repositories.',
        'clone_timeout' => 'Cloning took more than :minutes minutes, so it was stopped. The repository is probably too large.',
        'fetch_timeout' => 'Fetching the new commits took more than :minutes minutes, so it was stopped. The repository is probably too large.',
        'clone_failed' => 'The repository could not be cloned. Try again in a few minutes.',
        'fetch_failed' => 'The new commits could not be fetched from GitHub. Try again in a few minutes.',
        'history_failed' => 'The git history of the repository could not be read.',
        'analyzer_missing' => 'The code analyzer is not installed on the server, so the code could not be analyzed.',
        'analysis_timeout' => 'The code analysis took more than :minutes minutes, so it was stopped. The repository is probably too large.',
        'analysis_failed' => 'The code of the repository could not be analyzed. Try again.',
    ],

];
