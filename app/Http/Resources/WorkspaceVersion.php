<?php

namespace App\Http\Resources;

use App\Models\Repository;
use Illuminate\Support\Facades\DB;

/**
 * A short text that changes whenever anything the workspace shows changes (Faza 11, speed).
 *
 * The workspace of a large repository is about 90 KB of JSON and costs a dozen queries, but it only changes
 * when a processing run ends, a video or a report is made, and so on. This version is read with ONE query
 * (a count and the newest id or time of every table the workspace reads, plus the language, because the
 * labels are translated) and then serves two purposes:
 *
 * - the server keeps the built workspace in the cache under this version (RendersRepositoryPage);
 * - the browser keeps it too: it is an Inertia "once" prop with this text as its key, so moving from one
 *   page of the repository to another does not send it again, and a new version is sent as soon as it changes.
 */
final class WorkspaceVersion
{
    /**
     * The tables the workspace and the other derived props read, and the column that shows a change in each
     * (a table without timestamps is changed only by adding rows, so its newest id says it).
     *
     * @var array<string, string>
     */
    private const TABLES = [
        'documents' => 'updated_at',
        'diagrams' => 'updated_at',
        'modules' => 'id',
        'module_dependencies' => 'id',
        'commits' => 'id',
        'code_chunks' => 'id',
        'media_assets' => 'updated_at',
        'consulting_reports' => 'updated_at',
        'processing_runs' => 'updated_at',
    ];

    /**
     * The version of a repository's workspace in the current language.
     */
    public static function of(Repository $repository): string
    {
        $selects = ['(select updated_at from repositories where id = ?) as repository'];
        $bindings = [$repository->id];

        foreach (self::TABLES as $table => $column) {
            $selects[] = "(select count(*) || ':' || coalesce(max({$column}), '') from {$table} where repository_id = ?) as {$table}";
            $bindings[] = $repository->id;
        }

        $row = DB::selectOne('select '.implode(', ', $selects), $bindings);

        return substr(md5(app()->getLocale().'|'.json_encode($row)), 0, 16);
    }
}
