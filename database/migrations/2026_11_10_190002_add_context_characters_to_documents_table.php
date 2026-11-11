<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * "context_characters": the size of the text the writer received for this page (the instructions plus
     * the page's context). Together with input_tokens of the pages written by Claude it gives the measured
     * characters-per-token ratio that the benchmark uses for its estimates. Null for pages written before
     * this column existed.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedInteger('context_characters')->nullable()->after('output_tokens');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('context_characters');
        });
    }
};
