<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row for every execution of the pipeline (the first processing or an update):
     * what started it, which commits it covered, how long it took and how much work it did.
     * These rows are the measurements of the thesis (incremental update vs. full processing).
     *
     * The counters start at 0 and each pipeline step fills its own (files in Faza 3 and 5,
     * modules in Faza 5, documents and tokens in Faza 6). duration_ms stays null while running.
     *
     * The times keep milliseconds (precision 3): with whole seconds, duration_ms could be
     * off by up to one second, too much for runs that take only a few seconds.
     */
    public function up(): void
    {
        Schema::create('processing_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->string('mode');
            $table->string('trigger');
            $table->string('from_sha', 40)->nullable();
            $table->string('to_sha', 40)->nullable();
            $table->string('status');
            $table->unsignedInteger('files_total')->default(0);
            $table->unsignedInteger('files_changed')->default(0);
            $table->unsignedInteger('files_analyzed')->default(0);
            $table->unsignedInteger('modules_total')->default(0);
            $table->unsignedInteger('modules_affected')->default(0);
            $table->unsignedInteger('documents_regenerated')->default(0);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('started_at', precision: 3);
            $table->timestamp('finished_at', precision: 3)->nullable();
            $table->text('error')->nullable();
            $table->timestamps(precision: 3);

            $table->index(['repository_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('processing_runs');
    }
};
