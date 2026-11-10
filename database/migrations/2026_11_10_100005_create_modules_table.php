<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A module is a folder of the repository that groups its files (App\Analysis\ModuleGrouper),
     * for example "app/Http" or "resources/js". The module "/" holds the files of the root folder.
     * The documentation (Faza 6) writes one page for each module.
     *
     * Like the other analysis tables, it has no timestamps: every analysis replaces all its rows,
     * and repositories.analyzed_at already says when that happened.
     */
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->string('path', 1024);
            $table->string('name');
            $table->unsignedInteger('file_count')->default(0);
            $table->unsignedInteger('line_count')->default(0);

            $table->unique(['repository_id', 'path']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};
