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
     * The history of the diagrams (the time travel of Faza 9): a new row each time the Mermaid code of
     * a diagram changes, with the commit it was drawn from. A diagram is identified by (type, key), like
     * in the diagrams table, not by its id: the flows are deleted and written again, and their history
     * must survive that. A row with mermaid = null says the diagram disappeared at that commit.
     * Rows are never updated, so there is only created_at.
     *
     * The diagrams that already exist become their own first version.
     */
    public function up(): void
    {
        Schema::create('diagram_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('key', 100)->default('');
            $table->string('sha', 40)->nullable();
            $table->text('mermaid')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['repository_id', 'type', 'key']);
        });

        foreach (DB::table('diagrams')->orderBy('id')->get() as $diagram) {
            DB::table('diagram_versions')->insert([
                'repository_id' => $diagram->repository_id,
                'type' => $diagram->type,
                'key' => $diagram->key,
                'sha' => $diagram->source_sha,
                'mermaid' => $diagram->mermaid,
                'meta' => $diagram->meta,
                'created_at' => $diagram->updated_at,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('diagram_versions');
    }
};
