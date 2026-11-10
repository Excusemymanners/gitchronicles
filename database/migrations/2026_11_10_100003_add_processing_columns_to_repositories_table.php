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
     * The columns the processing pipeline fills, step by step:
     * - default_branch, cloned_at: the clone (Faza 3);
     * - analyzed_at: the code analysis (Faza 5); documented_at: the documentation (Faza 6);
     * - description: a short description of the project (later phases);
     * - error: the message shown to the user when a step fails (never raw git output).
     *
     * The "cloned" status no longer exists: such rows go back to "pending",
     * so the new pipeline processes them again.
     */
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->string('description', 500)->nullable();
            $table->string('default_branch')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('cloned_at')->nullable();
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamp('documented_at')->nullable();
        });

        DB::table('repositories')->where('status', 'cloned')->update(['status' => 'pending']);
    }

    /**
     * Reverse the migrations.
     *
     * The rows moved from "cloned" to "pending" stay "pending": nothing tells them apart anymore.
     */
    public function down(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->dropColumn(['description', 'default_branch', 'error', 'cloned_at', 'analyzed_at', 'documented_at']);
        });
    }
};
