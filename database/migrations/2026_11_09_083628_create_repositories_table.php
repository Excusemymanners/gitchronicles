<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One public record per GitHub repository: "url" is unique for the whole table.
     * "user_id" is the user who added it. It becomes null when that user deletes
     * their account, so the public catalog keeps the repository.
     */
    public function up(): void
    {
        Schema::create('repositories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('url')->unique();
            $table->string('owner');
            $table->string('name');
            $table->string('status')->default('pending');
            $table->string('last_sha', 40)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('repositories');
    }
};
