<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('characterization_document_purges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('source_document_id')->index();
            $table->char('path_hash', 64)->unique();
            $table->string('storage_disk', 64);
            $table->text('stored_path');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error', 64)->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->uuid('lease_token')->nullable();
            $table->timestamp('leased_until')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('characterization_document_purges');
    }
};
