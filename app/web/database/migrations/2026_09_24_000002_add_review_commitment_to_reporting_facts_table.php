<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reporting_facts', function (Blueprint $table) {
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->char('review_declaration_sha256', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reporting_facts', function (Blueprint $table) {
            $table->dropForeign(['reviewed_by_user_id']);
            $table->dropColumn(['reviewed_at', 'reviewed_by_user_id', 'review_declaration_sha256']);
        });
    }
};
