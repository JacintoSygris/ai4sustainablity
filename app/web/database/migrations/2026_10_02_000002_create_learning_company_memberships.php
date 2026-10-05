<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_company_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learning_company_group_id')
                ->constrained('learning_company_groups')
                ->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject_type', 16);
            $table->string('subject_identifier', 64);
            $table->char('evidence_digest', 64);
            $table->string('evidence_type', 64);
            $table->char('revocation_evidence_digest', 64)->nullable();
            $table->string('revocation_evidence_type', 64)->nullable();
            $table->string('verification_status', 16);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['subject_type', 'subject_identifier'],
                'learning_company_memberships_subject_unique',
            );
            $table->index(
                ['learning_company_group_id', 'verification_status'],
                'learning_company_memberships_group_status_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_company_memberships');
    }
};
