<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Only the rules this application uses, with the same keys as
    | lang/en/validation.php (tests/Feature/TranslationFilesTest.php checks
    | it). A rule missing from this file falls back to the English message
    | (fallback_locale = en).
    |
    | Romanian needs "de" after numbers from 20 up: "255 de caractere", but
    | "8 caractere". The max/min messages fit the limits used here (max:255, min:8).
    |
    */

    'boolean' => 'Câmpul :attribute trebuie să fie adevărat sau fals.',
    'confirmed' => 'Confirmarea pentru :attribute nu se potrivește.',
    'email' => 'Câmpul :attribute trebuie să fie o adresă de email validă.',
    'lowercase' => 'Câmpul :attribute trebuie să conțină doar litere mici.',
    'max' => [
        'string' => 'Câmpul :attribute poate avea cel mult :max de caractere.',
    ],
    'min' => [
        'string' => 'Câmpul :attribute trebuie să aibă cel puțin :min caractere.',
    ],
    'password' => [
        'letters' => 'Câmpul :attribute trebuie să conțină cel puțin o literă.',
        'numbers' => 'Câmpul :attribute trebuie să conțină cel puțin o cifră.',
        'uncompromised' => 'Această :attribute a apărut într-o scurgere de date. Alege alta.',
    ],
    'regex' => 'Formatul câmpului :attribute nu este valid.',
    'required' => 'Câmpul :attribute este obligatoriu.',
    'string' => 'Câmpul :attribute trebuie să fie un text.',
    'unique' => 'Valoarea câmpului :attribute este deja folosită.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | The field names shown in the messages instead of the input names.
    |
    */

    'attributes' => [
        'name' => 'nume',
        'email' => 'email',
        'password' => 'parolă',
        'url' => 'link',
        'locale' => 'limbă',
    ],

];
