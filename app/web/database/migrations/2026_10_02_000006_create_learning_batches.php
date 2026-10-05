<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('learning_batches', function (Blueprint $t) {
            $t->unsignedTinyInteger('id')->primary();
            $t->unsignedBigInteger('fence')->default(0);
            $t->string('batch_id',32)->nullable(); $t->string('status')->default('idle');
            $t->unsignedBigInteger('lease_until')->nullable(); $t->unsignedBigInteger('heartbeat_at')->nullable();
            $t->json('issuer_state')->nullable(); $t->json('dataset_state')->nullable();
            $t->json('receipt')->nullable(); $t->string('context_digest',64)->nullable();
        });
    }
    public function down(): void { /* retained: no destructive rollback in synthetic lane */ }
};
