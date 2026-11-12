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

    'generic' => 'Claude nu a putut scrie textul acum. Încearcă din nou mai târziu.',
    'limit' => 'Limita abonamentului Claude a fost atinsă; încearcă mai târziu.',
    'too_slow' => 'Claude a răspuns prea greu, așa că am oprit cererea. Încearcă din nou mai târziu.',
    'too_long' => 'Răspunsul lui Claude a fost prea lung și s-a oprit la jumătate.',
    'refused' => 'Claude a refuzat să scrie acest text.',
    'budget_reached' => 'Bugetul zilnic de AI a fost atins — aceste pagini au fost scrise din șabloane; regenerează mâine sau mărește AI_DAILY_CALLS.',
    'disabled' => 'Generarea cu AI este oprită pe acest server.',

];
