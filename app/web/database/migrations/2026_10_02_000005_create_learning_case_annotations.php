<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('learning_case_annotations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('learning_case_id')->constrained('learning_cases')->cascadeOnDelete();
            $t->foreignId('curator_id')->constrained('users')->cascadeOnDelete();
            $t->unsignedBigInteger('annotation_revision');
            $t->unsignedBigInteger('expected_revision');
            $t->char('command_id', 64);
            $t->longText('command_text'); $t->char('command_digest', 64);
            $t->longText('annotation_text'); $t->char('annotation_digest', 64);
            $t->unsignedBigInteger('curator_authorization_generation');
            $t->char('curator_authorization_digest', 64);
            $t->unique(['learning_case_id','annotation_revision']);
            $t->unique(['learning_case_id','command_id']);
        });
    }

    public function down(): void { Schema::dropIfExists('learning_case_annotations'); }
};
