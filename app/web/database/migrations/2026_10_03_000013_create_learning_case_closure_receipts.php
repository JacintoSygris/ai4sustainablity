<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('learning_case_closure_receipts', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('learning_case_id')->unique()->constrained('learning_cases')->cascadeOnDelete();
            $t->string('idempotency_key',128); $t->char('intent_digest',64);
            $t->char('source_token',64); $t->longText('source_text');
            $t->longText('receipt_text'); $t->char('receipt_hash',64);
            $t->timestamp('created_at'); $t->unique(['user_id','idempotency_key']);
        });
        Schema::create('learning_case_closure_drafts', function (Blueprint $t) {
            $t->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $t->longText('draft_text');
        });
    }
    public function down(): void {
        Schema::dropIfExists('learning_case_closure_drafts');
        Schema::dropIfExists('learning_case_closure_receipts');
    }
};
