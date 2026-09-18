<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('characterization_id')->constrained()->cascadeOnDelete();
            $table->string('profile_id');
            $table->string('profile_hash', 64);
            $table->string('facts_hash', 64);
            $table->string('characterization_hash', 64);
            $table->string('snapshot_hash', 64)->unique();
            $table->json('source_manifest');
            $table->json('snapshot_json');
            $table->string('stale_state')->default('fresh');
            $table->json('stale_reasons');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['characterization_id', 'created_at']);
            $table->index(['stale_state']);
        });

        Schema::create('report_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_snapshot_id')->constrained('report_snapshots')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role_mode');
            $table->foreignId('preparer_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reviewer_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('approver_user_id')->constrained('users')->cascadeOnDelete();
            $table->text('single_person_declaration');
            $table->string('snapshot_hash', 64);
            $table->timestamp('approved_at');
            $table->timestamps();

            $table->unique('report_snapshot_id');
            $table->index(['user_id', 'approved_at']);
        });

        Schema::create('report_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('characterization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('report_snapshot_id')->nullable()->constrained('report_snapshots')->nullOnDelete();
            $table->foreignId('report_approval_id')->nullable()->constrained('report_approvals')->nullOnDelete();
            $table->string('event_type');
            $table->json('payload');
            $table->timestamps();

            $table->index(['user_id', 'event_type']);
            $table->index(['report_snapshot_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_audit_events');
        Schema::dropIfExists('report_approvals');
        Schema::dropIfExists('report_snapshots');
    }
};
