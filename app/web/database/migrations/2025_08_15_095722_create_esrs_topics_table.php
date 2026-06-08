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
        Schema::create('esrs_topics', function (Blueprint $table) {
            $table->id();
            $table->string('esrs_code');
            $table->string('theme_es');
            $table->string('theme_en');
            $table->string('subtheme_es');
            $table->string('subtheme_en');
            $table->string('subtopic_es')->nullable();
            $table->string('subtopic_en')->nullable();
            $table->json('examples_es')->nullable();
            $table->json('examples_en')->nullable();
            $table->boolean('competency_1')->default(false);
            $table->boolean('competency_2')->default(false);
            $table->boolean('competency_3')->default(false);
            $table->boolean('regulation_1')->default(false);
            $table->boolean('regulation_2')->default(false);
            $table->boolean('regulation_3')->default(false);
            $table->boolean('internal_strategy')->default(false);
            $table->boolean('internal_activities')->default(false);
            $table->boolean('internal_policies')->default(false);
            $table->boolean('internal_regulations')->default(false);
            $table->boolean('consolidated')->default(false);
            $table->string('hash')->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('esrs_topics');
    }
};
