<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $duplicateUserIds = DB::table('characterizations')
            ->select('user_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('user_id')
            ->all();

        if ($duplicateUserIds !== []) {
            $preview = implode(', ', array_slice($duplicateUserIds, 0, 10));
            $suffix = count($duplicateUserIds) > 10 ? ', ...' : '';

            throw new RuntimeException(
                "Cannot add characterizations_user_id_unique while duplicate user_id rows exist ({$preview}{$suffix}). Resolve duplicates non-destructively before rerunning this migration."
            );
        }

        Schema::table('characterizations', function (Blueprint $table): void {
            $table->unique('user_id', 'characterizations_user_id_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // MySQL/MariaDB may use the unique index to support the existing
        // user_id foreign key. Give that constraint a non-unique index before
        // removing the uniqueness rule so rollback remains executable.
        Schema::table('characterizations', function (Blueprint $table): void {
            $table->index('user_id', 'characterizations_user_id_rollback_index');
        });

        Schema::table('characterizations', function (Blueprint $table): void {
            $table->dropUnique('characterizations_user_id_unique');
        });
    }
};
