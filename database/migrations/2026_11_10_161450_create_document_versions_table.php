<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The history of every documentation page: a new row each time its Markdown changes (the first
     * version included), with the commit it was written from. The time travel (Faza 9) reads it.
     * Rows are never updated, so there is only created_at.
     */
    public function up(): void
    {
        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->string('sha', 40)->nullable();
            $table->longText('markdown');
            $table->timestamp('created_at')->nullable();

            $table->index(['document_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_versions');
    }
};
