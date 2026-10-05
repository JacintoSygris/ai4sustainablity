<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_p6_base_source_revisions', function (Blueprint $table) {
            $table->foreignId('characterization_id')->primary()->constrained('characterizations')->cascadeOnDelete();
            $table->unsignedBigInteger('generation');
            $table->unsignedBigInteger('revision');
            $table->string('epoch', 64);
            $table->string('digest', 64);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_p6_base_source_revisions');
    }
};
