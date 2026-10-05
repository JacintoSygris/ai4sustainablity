<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('learning_case_p5_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learning_case_id')->unique()->constrained('learning_cases')->cascadeOnDelete();
            $table->longText('values_text');
            $table->string('schema_version',64);
            $table->char('digest',64);
            $table->string('feature_schema_version',64);
            $table->string('transform_version',64);
            $table->unsignedBigInteger('actor_id');
            $table->unsignedBigInteger('group_id');
            $table->string('purpose',128);
            $table->char('authorization_digest',64);
            $table->unsignedBigInteger('authorization_generation');
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::create('learning_case_p5_storage_receipts', function (Blueprint $table) {
            $table->foreignId('learning_case_id')->unique()->constrained('learning_cases')->cascadeOnDelete();
            // Deliberately no snapshot FK: ordinary snapshot deletion remains allowed.
            $table->unsignedBigInteger('snapshot_id');
            $table->char('completion_reference',64);
        });
    }
    public function down(): void {
        Schema::dropIfExists('learning_case_p5_storage_receipts');
        Schema::dropIfExists('learning_case_p5_snapshots');
    }
};
