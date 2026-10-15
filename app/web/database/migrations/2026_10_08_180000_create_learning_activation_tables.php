<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_bandit_events', function (Blueprint $table) {
            $table->id();
            $table->char('source_key', 64)->unique();
            $table->uuid('subject')->index();
            $table->char('stream', 64)->index();
            $table->string('revision', 100);
            $table->json('event');
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('created_at');
        });
        Schema::create('learning_admin_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Nullable system actor; audit survives account removal.
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('loop', 8);
            $table->string('action', 20);
            $table->string('candidate', 64)->nullable();
            $table->string('reason', 500);
            $table->string('status', 32);
            $table->char('before_sha256', 64)->nullable();
            $table->char('after_sha256', 64)->nullable();
            $table->string('error_code', 80)->nullable();
            $table->timestamps();
        });
        Schema::create('learning_heartbeats', function (Blueprint $table) {
            $table->string('component', 32)->primary();
            $table->timestamp('seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_heartbeats');
        Schema::dropIfExists('learning_admin_actions');
        Schema::dropIfExists('learning_bandit_events');
    }
};
