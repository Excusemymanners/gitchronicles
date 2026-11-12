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

    /*
    | Mesajele de eroare păstrate: se salvează în repositories.error ca App\Support\StoredMessage
    | și se afișează în limba vizitatorului.
    */

    'errors' => [
        'unexpected' => 'A apărut o eroare neașteptată în timpul procesării. Încearcă din nou.',
        'no_commits' => 'Repository-ul nu are încă niciun commit, deci nu avem ce documenta.',
        'not_public' => 'Repository-ul nu există pe GitHub sau este privat. GitChronicle poate clona doar repository-uri publice.',
        'clone_timeout' => 'Clonarea a durat mai mult de :minutes minute, așa că am oprit-o. Repository-ul este probabil prea mare.',
        'fetch_timeout' => 'Aducerea commit-urilor noi a durat mai mult de :minutes minute, așa că am oprit-o. Repository-ul este probabil prea mare.',
        'clone_failed' => 'Nu am putut clona repository-ul. Încearcă din nou peste câteva minute.',
        'fetch_failed' => 'Nu am putut aduce commit-urile noi de pe GitHub. Încearcă din nou peste câteva minute.',
        'history_failed' => 'Nu am putut citi istoricul git al repository-ului.',
        'analyzer_missing' => 'Analizorul de cod nu este instalat pe server, așa că nu am putut analiza codul.',
        'analysis_timeout' => 'Analiza codului a durat mai mult de :minutes minute, așa că am oprit-o. Repository-ul este probabil prea mare.',
        'analysis_failed' => 'Nu am putut analiza codul repository-ului. Încearcă din nou.',
    ],

];
