<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('characterization_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('characterization_id')->constrained()->cascadeOnDelete();
            $table->string('original_filename');
            $table->string('stored_path');
            $table->string('sha256', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->string('mime');
            $table->string('status')->default('uploaded');
            $table->json('extraction_json')->nullable();
            $table->string('merged_state_version')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('characterization_documents');
    }
};
