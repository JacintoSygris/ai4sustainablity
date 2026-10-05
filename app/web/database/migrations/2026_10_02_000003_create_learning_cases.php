<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learning_company_group_id')
                ->constrained('learning_company_groups')
                ->restrictOnDelete();
            $table->string('case_id', 128)->index();
            $table->string('period_key', 64);
            $table->string('perimeter_key', 64);
            $table->char('revision_tuple_digest', 64);
            $table->char('identity_digest', 64);
            $table->char('case_hash', 64)->index();
            $table->longText('payload_text');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                [
                    'learning_company_group_id',
                    'identity_digest',
                ],
                'learning_cases_identity_unique',
            );
        });

        Schema::create('learning_case_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learning_case_id')
                ->unique()
                ->constrained('learning_cases')
                ->cascadeOnDelete();
            $table->string('status', 64);
            $table->unsignedBigInteger('state_version')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_case_states');
        Schema::dropIfExists('learning_cases');
    }
};
