<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * code_chunks: the pieces of code the assistant searches (Faza 8): one class or function,
     * or a window of at most 80 lines, with the exact lines it covers (the citations point to them).
     *
     * code_chunks_fts: on SQLite, a full-text index (FTS5) of the chunks, written explicitly by
     * App\Search\SqliteCodeSearch. Its rowid is the id of the chunk; repository_id is stored
     * (UNINDEXED: not searchable) so a repository's whole index can be deleted at once.
     * Other databases have no such table and use App\Search\LikeCodeSearch.
     */
    public function up(): void
    {
        Schema::create('code_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->foreignId('repository_file_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('start_line');
            $table->unsignedInteger('end_line');
            $table->string('symbol_name')->nullable();
            $table->text('content');

            $table->index('repository_id');
            $table->index('repository_file_id');
        });

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement("CREATE VIRTUAL TABLE code_chunks_fts USING fts5(
                path, symbol_name, body, repository_id UNINDEXED,
                tokenize = 'unicode61 remove_diacritics 2'
            )");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('DROP TABLE IF EXISTS code_chunks_fts');
        }

        Schema::dropIfExists('code_chunks');
    }
};
