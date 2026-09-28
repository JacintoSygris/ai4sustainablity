<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('password_reset_tokens', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable()->after('email')->index();
            $table->unsignedBigInteger('auth_version')->nullable()->after('token');
            $table->uuid('generation')->nullable()->after('auth_version');
            $table->char('token_fingerprint', 64)->nullable()->after('generation');
        });
    }

    public function down(): void
    {
        Schema::table('password_reset_tokens', function (Blueprint $table): void {
            $table->dropIndex(['user_id']);
            $table->dropColumn(['user_id', 'auth_version', 'generation', 'token_fingerprint']);
        });
    }
};
