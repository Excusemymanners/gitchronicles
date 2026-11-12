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

    'not_indexed' => 'The code of this repository isn’t ready for the assistant yet. Try again once processing has finished.',

    'validation' => [
        'question_required' => 'Write a question.',
        'question_min' => 'The question must be at least 3 characters long.',
        'question_max' => 'The question can be at most 1,000 characters long.',
        'history_shape' => 'The conversation sent doesn’t have the right shape.',
        'history_max' => 'The conversation sent can have at most 6 messages.',
        'history_content_max' => 'A message of the conversation can be at most 4,000 characters long.',
    ],

];
