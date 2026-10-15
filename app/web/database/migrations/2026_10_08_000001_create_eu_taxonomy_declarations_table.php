<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eu_taxonomy_declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('characterization_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision')->default(1);
            $table->string('source_hash', 64);
            $table->json('source_context');
            $table->json('activities');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eu_taxonomy_declarations');
    }
};
