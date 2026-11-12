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
        'pending' => 'Queued',
        'cloning' => 'Cloning',
        'analyzing' => 'Analyzing code',
        'documenting' => 'Writing docs',
        'ready' => 'Ready',
        'updating' => 'Updating',
        'failed' => 'Failed',
    ],

    'media_status' => [
        'pending' => 'Queued',
        'running' => 'Generating',
        'ready' => 'Ready',
        'failed' => 'Failed',
    ],

    'consulting_status' => [
        'pending' => 'Queued',
        'running' => 'Analyzing',
        'ready' => 'Ready',
        'failed' => 'Failed',
    ],

    'scan_tool_status' => [
        'ran' => 'Ran',
        'not_installed' => 'Not installed',
        'failed' => 'Failed',
        'timed_out' => 'Timed out',
    ],

    'finding_severity' => [
        'critical' => 'Critical',
        'high' => 'High',
        'medium' => 'Medium',
        'low' => 'Low',
        'info' => 'Informational',
    ],

    'finding_kind' => [
        'bug' => 'Bug',
        'vulnerability' => 'Vulnerability',
    ],

];
