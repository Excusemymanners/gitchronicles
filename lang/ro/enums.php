<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enum Labels
    |--------------------------------------------------------------------------
    |
    | The names of the statuses, severities and kinds the server sends as labels (RepositoryStatus, MediaStatus, ConsultingStatus, ScanToolStatus, FindingSeverity, FindingKind).
    |
    */

    'repository_status' => [
        'pending' => 'În așteptare',
        'cloning' => 'Se clonează',
        'analyzing' => 'Se analizează codul',
        'documenting' => 'Se scrie documentația',
        'ready' => 'Gata',
        'updating' => 'Se actualizează',
        'failed' => 'Eroare',
    ],

    'media_status' => [
        'pending' => 'În așteptare',
        'running' => 'Se generează',
        'ready' => 'Gata',
        'failed' => 'Eroare',
    ],

    'consulting_status' => [
        'pending' => 'În așteptare',
        'running' => 'Se analizează',
        'ready' => 'Gata',
        'failed' => 'Eroare',
    ],

    'scan_tool_status' => [
        'ran' => 'A rulat',
        'not_installed' => 'Nu e instalat',
        'failed' => 'Eroare',
        'timed_out' => 'Timp depășit',
    ],

    'finding_severity' => [
        'critical' => 'Critică',
        'high' => 'Ridicată',
        'medium' => 'Medie',
        'low' => 'Scăzută',
        'info' => 'Informativă',
    ],

    'finding_kind' => [
        'bug' => 'Bug',
        'vulnerability' => 'Vulnerabilitate',
    ],

];
