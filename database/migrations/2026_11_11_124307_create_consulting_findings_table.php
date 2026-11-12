<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One problem found by a tool of the „Consultanță” report:
     *
     * - in the code (Semgrep): the rule, the file and its lines;
     * - in a dependency (OSV-Scanner): the package, its installed version, the version that fixes it
     *   (when the advisory says) and the advisory with its address; "path" is the lock file.
     *
     * severity is normalized (critical, high, medium, low, info) and original_severity keeps what the tool
     * said ("ERROR", "CVSS 7.5", "HIGH"). Every text comes from the repository or a tool, so the scanner cuts
     * it to these lengths and saves at most a fixed number of findings per tool.
     */
    public function up(): void
    {
        Schema::create('consulting_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consulting_report_id')->constrained()->cascadeOnDelete();
            $table->string('tool', 20);
            $table->string('kind', 20);
            $table->string('severity', 10);
            $table->string('original_severity', 50)->nullable();
            $table->string('rule_id', 200);
            $table->string('title', 255);
            $table->text('message');
            $table->string('path', 500)->nullable();
            $table->unsignedInteger('start_line')->nullable();
            $table->unsignedInteger('end_line')->nullable();
            $table->string('package', 255)->nullable();
            $table->string('ecosystem', 50)->nullable();
            $table->string('installed_version', 100)->nullable();
            $table->string('fixed_version', 100)->nullable();
            $table->string('advisory_id', 100)->nullable();
            $table->string('advisory_url', 500)->nullable();
            $table->timestamps();

            $table->index(['consulting_report_id', 'severity']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('consulting_findings');
    }
};
