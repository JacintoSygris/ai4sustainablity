<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('learning_candidate_selections',function (Blueprint $t) {
            $t->string('candidate_key',64)->primary();
            $t->string('batch_id',32); $t->unsignedBigInteger('fence');
            $t->unsignedBigInteger('authority_generation');
            $t->string('context_digest',64); $t->string('authority_digest',64);
            $t->text('technical_metadata');
            $t->boolean('selected')->default(false);
        });
    }
    public function down(): void { /* retained; no destructive rollback in this lane */ }
};
