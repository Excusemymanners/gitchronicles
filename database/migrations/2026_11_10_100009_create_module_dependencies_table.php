<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The dependency graph between modules: "from" uses "to", and weight says how many imports
     * between their files we found. One row for each pair of modules.
     */
    public function up(): void
    {
        Schema::create('module_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_module_id')->constrained('modules')->cascadeOnDelete();
            $table->foreignId('to_module_id')->constrained('modules')->cascadeOnDelete();
            $table->unsignedInteger('weight');

            $table->unique(['from_module_id', 'to_module_id']);
            $table->index('repository_id');
            $table->index('to_module_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('module_dependencies');
    }
};
