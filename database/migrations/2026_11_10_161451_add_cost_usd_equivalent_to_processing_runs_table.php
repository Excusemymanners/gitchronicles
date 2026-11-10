<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * What the AI calls of a run would have cost on the Anthropic API, in dollars (the Claude Code CLI
     * reports it; on the subscription nothing is billed per call). Null when no call reported a cost,
     * for example a run without AI. It sits next to input_tokens and output_tokens for the thesis measurements.
     */
    public function up(): void
    {
        Schema::table('processing_runs', function (Blueprint $table) {
            $table->decimal('cost_usd_equivalent', 10, 6)->nullable()->after('output_tokens');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('processing_runs', function (Blueprint $table) {
            $table->dropColumn('cost_usd_equivalent');
        });
    }
};
