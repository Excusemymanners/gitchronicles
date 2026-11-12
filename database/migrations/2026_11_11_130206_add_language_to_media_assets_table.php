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
     * The presentation video now exists once per language (App\Enums\MediaLanguage: "en", "ro"), each with
     * its own voice, captions and MP4. The language is a column, not a key inside "meta", because every
     * language has its own state: one can be ready while the other is still rendering or has failed, and
     * the page shows each one's status, length, size and error.
     *
     * So a repository has one row per (kind, language) instead of one per kind. The rows that already
     * exist were made with the Romanian voice and Romanian slides, hence the default "ro": they keep
     * working unchanged. The slides stay one row per repository; its language says which language the
     * deck was written in.
     */
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->string('language', 5)->default('ro')->after('kind');
        });

        Schema::table('media_assets', function (Blueprint $table) {
            $table->dropUnique(['repository_id', 'kind']);
            $table->unique(['repository_id', 'kind', 'language']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * The old shape allows one row per kind, so the videos of the other languages are removed first.
     */
    public function down(): void
    {
        DB::table('media_assets')->where('language', '!=', 'ro')->delete();

        Schema::table('media_assets', function (Blueprint $table) {
            $table->dropUnique(['repository_id', 'kind', 'language']);
            $table->unique(['repository_id', 'kind']);
        });

        Schema::table('media_assets', function (Blueprint $table) {
            $table->dropColumn('language');
        });
    }
};
