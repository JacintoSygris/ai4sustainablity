<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characterizations', function (Blueprint $table): void {
            $table->unsignedBigInteger('submission_generation')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('characterizations', function (Blueprint $table): void {
            $table->dropColumn('submission_generation');
        });
    }
};
