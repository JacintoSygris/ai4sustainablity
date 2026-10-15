<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('learning_consent_at')->nullable();
            $table->string('learning_consent_version', 64)->nullable();
            $table->uuid('learning_subject')->nullable()->unique();
            $table->boolean('learning_synthetic')->default(false);
        });
        Schema::create('learning_erasure_requests', function (Blueprint $table) {
            $table->uuid('subject')->primary();
            $table->timestamp('created_at');
        });
        Schema::create('learning_engine_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('status', 32);
            $table->char('dataset_sha256', 64)->nullable();
            $table->string('candidate_id', 64)->nullable();
            $table->json('receipt')->nullable();
            $table->timestamps();
        });
        // Missing from the original learning migration set. Ordinary durable DBs
        // receive the same additive, cascading revision header as P5/P6/P8.
        if (! Schema::hasTable('learning_p9_source_revisions')) {
            Schema::create('learning_p9_source_revisions', function (Blueprint $table) {
                $table->foreignId('characterization_id')->primary()->constrained('characterizations')->cascadeOnDelete();
                $table->unsignedBigInteger('generation');
                $table->unsignedBigInteger('revision');
                $table->string('epoch', 64);
                $table->string('digest', 64);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_p9_source_revisions');
        Schema::dropIfExists('learning_engine_runs');
        Schema::dropIfExists('learning_erasure_requests');
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['learning_subject']);
            $table->dropColumn(['learning_consent_at', 'learning_consent_version', 'learning_subject', 'learning_synthetic']);
        });
    }
};
