<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Only the rules this application uses, with the same keys as
    | lang/ro/validation.php (tests/Feature/TranslationFilesTest.php checks
    | it). A rule missing from both files falls back to Laravel's own English
    | message, which ships with the framework.
    |
    */

    'boolean' => 'The :attribute field must be true or false.',
    'confirmed' => 'The :attribute confirmation does not match.',
    'email' => 'The :attribute must be a valid email address.',
    'lowercase' => 'The :attribute must be lowercase.',
    'max' => [
        'string' => 'The :attribute may not be longer than :max characters.',
    ],
    'min' => [
        'string' => 'The :attribute must be at least :min characters.',
    ],
    'password' => [
        'letters' => 'The :attribute must contain at least one letter.',
        'numbers' => 'The :attribute must contain at least one number.',
        'uncompromised' => 'This :attribute appeared in a data leak. Choose a different one.',
    ],
    'regex' => 'The :attribute format is invalid.',
    'required' => 'The :attribute field is required.',
    'string' => 'The :attribute must be text.',
    'unique' => 'This :attribute is already taken.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | The field names shown in the messages instead of the input names.
    |
    */

    'attributes' => [
        'name' => 'name',
        'email' => 'email',
        'password' => 'password',
        'url' => 'link',
        'locale' => 'language',
    ],

];
