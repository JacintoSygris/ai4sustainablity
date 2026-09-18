<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reporting_facts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('characterization_id')->constrained()->cascadeOnDelete();
            $table->string('fact_id');
            $table->string('schema_version')->default('reporting_fact_v1');
            $table->string('profile_id')->default('esrs-2023-preparatory-v1');
            $table->string('datapoint_id');
            $table->string('applicability');
            $table->string('value_type');
            $table->json('value')->nullable();
            $table->string('unit')->nullable();
            $table->integer('decimals')->nullable();
            $table->json('dimensions');
            $table->string('language')->nullable();
            $table->boolean('nil')->default(false);
            $table->string('nil_reason')->nullable();
            $table->json('evidence_refs');
            $table->string('provenance');
            $table->string('approval_status');
            $table->json('blocking_reasons');
            $table->timestamps();

            $table->unique(['characterization_id', 'fact_id']);
            $table->index(['characterization_id', 'datapoint_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reporting_facts');
    }
};
