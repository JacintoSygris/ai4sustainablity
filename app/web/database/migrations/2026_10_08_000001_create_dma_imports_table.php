<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dma_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('characterization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('sources');
            $table->json('payload');
            $table->timestamp('uploaded_at');
            $table->index(['characterization_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dma_imports');
    }
};
