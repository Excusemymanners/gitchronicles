<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mesajele video-ului și ale slide-urilor
    |--------------------------------------------------------------------------
    |
    | Propozițiile pe care joburile de video și slide-uri le salvează în media_assets (error și meta)
    | ca StoredMessage și pe care interfața le arată mai târziu, în limba vizitatorului.
    |
    */

    'errors' => [
        'not_installed' => 'Uneltele pentru video și slide-uri nu sunt instalate pe server (composer media:install).',
        'slides_failed' => 'Generarea slide-urilor a eșuat. Detaliile sunt în jurnalul aplicației.',
        'video_failed' => 'Randarea video-ului a eșuat. Detaliile sunt în jurnalul aplicației.',
        'generic' => 'Nu am putut genera fișierul. Încearcă din nou mai târziu.',
    ],

    'capture' => [
        'unreachable' => 'GitHub nu a putut fi contactat când s-a generat video-ul.',
        'timeout' => 'Pagina de GitHub s-a încărcat prea încet când s-a generat video-ul.',
        'http_status' => 'GitHub nu a afișat pagina repository-ului (poate nu mai este public).',
        'unexpected_page' => 'Pagina de GitHub nu arăta ca pagina unui repository, așa că nu a fost folosită.',
        'browser_failed' => 'Chrome nu a pornit pe server, deci pagina de GitHub nu a putut fi fotografiată.',
        'disabled' => 'Fotografierea paginii de GitHub este oprită pe acest server.',
        'failed' => 'Pagina de GitHub nu a putut fi fotografiată.',
    ],

    'script' => [
        'no_readme' => 'Repository-ul nu are README sau descriere din care să scriem textele.',
        'ai_off' => 'Generarea cu AI este oprită, așa că textele vin direct din descriere, din README și din cifre.',
        'ai_unusable' => 'Răspunsul AI nu a putut fi folosit, așa că textele vin direct din descriere, din README și din cifre.',
        'start_text_missing' => 'Textul lipsește într-una din limbi sau are peste :limit de caractere.',
        'command_missing' => 'Comanda lipsește sau e prea lungă pentru ecran.',
        'command_not_in_readme' => 'Comanda nu apare exact în README.',
        'scene_missing' => 'Scena lipsește din răspuns.',
        'language_text_missing' => 'Textul în :language lipsește sau are peste :limit de caractere.',
        'quote_missing' => 'Citatul-sursă nu apare în textele trimise.',
        'invented_number' => 'Textul spune cifra :number, care nu apare în citatul-sursă.',
    ],

];
