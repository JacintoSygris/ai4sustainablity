<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characterization_documents', function (Blueprint $table) {
            $table->uuid('extraction_generation')->nullable()->index()->after('status');
            $table->uuid('extraction_lease_token')->nullable()->after('extraction_generation');
            $table->timestamp('extraction_dispatched_at')->nullable()->after('extraction_lease_token');
            $table->timestamp('extraction_started_at')->nullable()->after('extraction_dispatched_at');
        });
    }

    public function down(): void
    {
        Schema::table('characterization_documents', function (Blueprint $table) {
            $table->dropIndex(['extraction_generation']);
            $table->dropColumn([
                'extraction_generation',
                'extraction_lease_token',
                'extraction_dispatched_at',
                'extraction_started_at',
            ]);
        });
    }
};
