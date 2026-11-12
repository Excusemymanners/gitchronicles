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

    'invalid_url' => 'Introdu un link de GitHub de forma https://github.com/proprietar/nume.',

    'flash' => [
        'already_added' => 'Repository-ul era deja adăugat.',
        'log_in_to_add' => 'Intră în cont ca să adăugăm :repository.',
        'added' => 'Repository adăugat.',
        'added_pending' => 'Am adăugat :repository.',
        'retry_started' => 'Am pornit din nou procesarea.',
        'checking_updates' => 'Verificăm dacă sunt commit-uri noi pe GitHub.',
        'deleted' => 'Repository șters.',
        'regenerate_started' => 'Am pornit procesarea completă: toate capitolele se fac din nou.',
        'media_started' => 'Am pornit generarea video-ului și a slide-urilor.',
        'consulting_started' => 'Am pornit analiza. Durează de obicei sub un minut.',
    ],

];
