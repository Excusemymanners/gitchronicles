<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The classes, interfaces, functions, methods, ... found by tree-sitter in each file,
     * with the lines where they start and end (the links to GitHub point to these lines).
     */
    public function up(): void
    {
        Schema::create('code_symbols', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->foreignId('repository_file_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('name');
            $table->unsignedInteger('start_line');
            $table->unsignedInteger('end_line');

            $table->index('repository_id');
            $table->index('repository_file_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('code_symbols');
    }
};
