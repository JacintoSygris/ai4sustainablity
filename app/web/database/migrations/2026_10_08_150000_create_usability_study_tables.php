<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usability_participants', function (Blueprint $table) {
            $table->uuid('study_participant_id')->primary();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('consent_version', 40);
            $table->timestamp('consented_at');
            $table->timestamp('enrolled_at');
        });
        Schema::create('usability_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('study_participant_id');
            $table->foreign('study_participant_id')->references('study_participant_id')->on('usability_participants')->cascadeOnDelete();
            $table->string('step', 3);
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('outcome', 10)->nullable();
            $table->unsignedInteger('error_count')->nullable();
        });
        Schema::create('usability_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('task_id');
            $table->foreign('task_id')->references('id')->on('usability_tasks')->cascadeOnDelete();
            $table->string('event', 10);
            $table->timestamp('occurred_at');
            $table->unsignedInteger('error_count')->nullable();
            $table->unique(['task_id', 'event']);
        });
        Schema::create('usability_questionnaires', function (Blueprint $table) {
            $table->uuid('study_participant_id')->primary();
            $table->foreign('study_participant_id')->references('study_participant_id')->on('usability_participants')->cascadeOnDelete();
            $table->json('answers');
            $table->string('locale', 2);
            $table->text('comments')->nullable();
            $table->timestamp('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usability_questionnaires');
        Schema::dropIfExists('usability_events');
        Schema::dropIfExists('usability_tasks');
        Schema::dropIfExists('usability_participants');
    }
};
