<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dma_nlp_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('characterization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dma_import_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('iro_ids'); $table->string('stage', 2); $table->string('locale', 2);
            $table->string('input_digest', 64); $table->string('context_digest', 64);
            $table->unsignedInteger('p8_revision');
            $table->string('model_sha256', 64); $table->string('catalogue_sha256', 64);
            $table->json('result'); $table->timestamp('created_at');
            $table->unique(['dma_import_id', 'input_digest']);
        });
        Schema::create('dma_nlp_reviews', function (Blueprint $table) {
            $table->id(); $table->foreignId('dma_nlp_run_id')->constrained()->cascadeOnDelete();
            $table->string('candidate_id', 64);
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();
            $table->string('decision', 8); $table->timestamp('created_at');
            $table->unique(['dma_nlp_run_id', 'candidate_id']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('dma_nlp_reviews'); Schema::dropIfExists('dma_nlp_runs');
    }
};
