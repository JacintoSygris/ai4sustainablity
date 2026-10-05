<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_authorization_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('learning_company_group_id')->constrained('learning_company_groups')->restrictOnDelete();
            $table->char('subject_digest', 64)->unique();
            $table->string('holder_ref', 96);
            $table->string('purpose', 128);
            $table->string('status', 16);
            $table->unsignedBigInteger('generation');
            $table->char('event_digest', 64);
            $table->string('provenance', 32)->default('synthetic-only');
            $table->boolean('promotion_allowed')->default(false);
            $table->timestamps();
        });
        Schema::create('learning_authorization_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learning_authorization_state_id')->constrained('learning_authorization_states')->restrictOnDelete();
            $table->char('command_id', 64)->unique();
            $table->char('intent_digest', 64);
            $table->unsignedBigInteger('previous_generation');
            $table->unsignedBigInteger('generation');
            $table->char('previous_digest', 64)->nullable();
            $table->char('event_digest', 64)->unique();
            $table->longText('payload_text');
            $table->string('provenance', 32)->default('synthetic-only');
            $table->boolean('promotion_allowed')->default(false);
            $table->timestamp('created_at');
            $table->unique(['learning_authorization_state_id', 'generation'], 'learning_authorizations_generation_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_authorization_records');
        Schema::dropIfExists('learning_authorization_states');
    }
};
