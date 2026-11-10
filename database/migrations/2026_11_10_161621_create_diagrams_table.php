<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The diagrams of a repository (Faza 7), saved as Mermaid code and drawn in the browser.
     * "type" is App\Enums\DiagramType; "key" tells apart several diagrams of the same type
     * (the „Cum funcționează” flows) and stays '' for the others, so (repository_id, type, key) is unique.
     * "meta" holds what the page shows around the diagram: title, caption, the excluded folders,
     * the detected technologies, or the explanation and the source lines of a flow.
     * "source_sha" is the commit the diagram was drawn from (repositories.last_sha at that time).
     */
    public function up(): void
    {
        Schema::create('diagrams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('key', 100)->default('');
            $table->text('mermaid');
            $table->json('meta')->nullable();
            $table->string('source_sha', 40)->nullable();
            $table->timestamps();

            $table->unique(['repository_id', 'type', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('diagrams');
    }
};
