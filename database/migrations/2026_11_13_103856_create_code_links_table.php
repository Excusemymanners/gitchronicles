<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The connections the import lines do not show (App\Analysis\LinkScanner): a class used from the same
     * namespace, a route to a controller, a job dispatched, a page rendered, a program started, a service
     * called. Each row is one place in the code (file + line + the matched text) and points either to
     * another file of the repository (target_file_id) or to something outside the code (target, for
     * example "tool:git", "service:github", "store:database"). They are written again at every analysis,
     * next to file_imports, which stays as it was so its readers are not affected.
     */
    public function up(): void
    {
        Schema::create('code_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->foreignId('repository_file_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('line');
            $table->string('kind', 20);
            $table->foreignId('target_file_id')->nullable()->constrained('repository_files')->cascadeOnDelete();
            $table->string('target', 120)->nullable();
            $table->string('excerpt', 160);

            $table->index(['repository_id', 'kind']);
            $table->index('repository_file_id');
            $table->index('target_file_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('code_links');
    }
};
