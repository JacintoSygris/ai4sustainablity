<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('auth_version')->default(0)->after('remember_token');
        });

        Schema::create('oauth_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('issuer');
            $table->string('subject');
            $table->char('identity_hash', 64)->unique();
            $table->timestamps();
            $table->index(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_identities');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('auth_version');
        });
    }
};
