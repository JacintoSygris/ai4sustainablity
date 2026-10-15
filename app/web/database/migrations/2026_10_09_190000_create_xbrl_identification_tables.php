<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('xbrl_identification_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('characterization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('creator_id')->constrained('users')->cascadeOnDelete();
            $table->string('subject_key', 300);
            $table->foreignId('reporting_fact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('datapoint_id')->nullable();
            $table->json('input_json');
            $table->char('input_sha256', 64);
            $table->char('context_sha256', 64);
            $table->json('response_json');
            $table->string('model_version', 100);
            $table->char('artifact_sha256', 64);
            $table->char('catalog_sha256', 64);
            $table->timestamp('created_at');
            $table->index(['characterization_id', 'subject_key', 'created_at'], 'xbrl_runs_subject_time');
        });
        Schema::create('xbrl_identification_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('xbrl_identification_runs')->cascadeOnDelete();
            $table->string('candidate_qname', 500);
            $table->string('decision', 16);
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at');
            $table->text('reason');
            $table->unsignedInteger('sequence');
            $table->unique(['run_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('xbrl_identification_reviews');
        Schema::dropIfExists('xbrl_identification_runs');
    }
};
