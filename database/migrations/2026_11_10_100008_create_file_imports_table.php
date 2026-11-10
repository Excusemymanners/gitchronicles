<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * What each file imports, as written in the code ("target", for example "App\Models\User"
     * or "./format"). target_file_id is the file of the same repository the import points to;
     * it stays null for a library (is_external) or for an import we could not resolve.
     */
    public function up(): void
    {
        Schema::create('file_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->foreignId('repository_file_id')->constrained()->cascadeOnDelete();
            $table->string('target', 1024);
            $table->foreignId('target_file_id')->nullable()->constrained('repository_files')->nullOnDelete();
            $table->boolean('is_external')->default(false);

            $table->index('repository_id');
            $table->index('repository_file_id');
            $table->index('target_file_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('file_imports');
    }
};
