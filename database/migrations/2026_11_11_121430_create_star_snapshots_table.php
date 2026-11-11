<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The stars of a watched repository on one day: at most one row per repository and day (unique key),
     * so running `discover:refresh` twice on the same day updates the row instead of adding another.
     * The growth on the „Descoperă” page is the difference between two of these rows.
     *
     * captured_on is the day as a "Y-m-d" string (application time zone, UTC), compared as text.
     */
    public function up(): void
    {
        Schema::create('star_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('watched_repository_id')->constrained()->cascadeOnDelete();
            $table->date('captured_on')->index();
            $table->unsignedInteger('stars');
            $table->unsignedInteger('forks');
            $table->timestamps();

            $table->unique(['watched_repository_id', 'captured_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('star_snapshots');
    }
};
