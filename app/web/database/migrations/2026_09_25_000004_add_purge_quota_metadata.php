<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characterization_document_purges', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->index()->after('source_document_id');
            $table->unsignedBigInteger('characterization_id')->nullable()->index()->after('user_id');
            $table->unsignedBigInteger('size_bytes')->default(0)->after('characterization_id');
        });

        Schema::table('characterization_document_purges', function (Blueprint $table) {
            $table->unsignedBigInteger('source_document_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('characterization_document_purges')->whereNull('source_document_id')->delete();

        Schema::table('characterization_document_purges', function (Blueprint $table) {
            $table->unsignedBigInteger('source_document_id')->nullable(false)->change();
            $table->dropIndex(['user_id']);
            $table->dropIndex(['characterization_id']);
            $table->dropColumn(['user_id', 'characterization_id', 'size_bytes']);
        });
    }
};
