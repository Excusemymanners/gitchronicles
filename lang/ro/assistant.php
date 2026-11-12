<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Assistant Messages
    |--------------------------------------------------------------------------
    |
    | The errors of the assistant's question endpoint (AssistantQuestionController
    | and AskAssistantRequest), shown under the question field.
    |
    */

    'not_indexed' => 'Codul acestui repository nu a fost pregătit încă pentru asistent. Încearcă după ce se termină procesarea.',

    'busy' => 'Asistentul a primit foarte multe întrebări de la toți vizitatorii. Încearcă din nou mai târziu.',

    'validation' => [
        'question_required' => 'Scrie o întrebare.',
        'question_min' => 'Întrebarea trebuie să aibă cel puțin 3 caractere.',
        'question_max' => 'Întrebarea poate avea cel mult 1.000 de caractere.',
        'history_shape' => 'Conversația trimisă nu are forma corectă.',
        'history_max' => 'Conversația trimisă poate avea cel mult 6 mesaje.',
        'history_content_max' => 'Un mesaj al conversației poate avea cel mult 4.000 de caractere.',
    ],

];
