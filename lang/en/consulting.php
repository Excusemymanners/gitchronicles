<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Texts of the runtime and framework version findings
    |--------------------------------------------------------------------------
    |
    | Written by App\Consulting\Runtime\RuntimeVersionCheck into the report (English, like every generated
    | text of the report); the page's own labels are in resources/js/lang. The Romanian file is the same
    | structure, so both locales stay complete.
    |
    */

    'runtime' => [
        'declared_in' => 'Declared in :where as ":declared".',
        'also_declared' => 'The same version is also declared in :places.',
        'range_note' => 'That is a range: the lowest version it allows, :version, is the one judged here.',
        'end_of_life' => [
            'title' => ':product :line is past end of life',
            'message' => ':product :line has had no security fixes since :since.',
            'change' => 'Change it to :target (newest release :target_newest) or later.',
        ],
        'behind' => [
            'title' => ':product :version is behind the newest :line release',
            'message' => ':product :line is supported, but :version is older than its newest release, :newest.',
            'change' => 'Change it to :newest.',
        ],
        'behind_security' => [
            'title' => ':product :version misses :count security release|:product :version misses :count security releases',
            'message' => ':product :line is supported, but :version is behind by :count security release; the newest is :newest.|:product :line is supported, but :version is behind by :count security releases; the newest is :newest.',
            'change' => 'Change it to :newest.',
        ],
        'security_only' => [
            'title' => ':product :line gets security fixes only',
            'message' => ':product :line no longer gets bug fixes, only security fixes, until :until.',
            'change' => 'Plan the move to :target (newest release :target_newest) before then.',
        ],
    ],

];
