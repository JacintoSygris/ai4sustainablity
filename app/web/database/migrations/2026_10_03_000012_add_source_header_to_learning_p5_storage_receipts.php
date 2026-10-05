<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('learning_case_p5_storage_receipts', function (Blueprint $table) {
            $table->longText('source_header_text')->nullable();
        });
    }
    public function down(): void {
        Schema::table('learning_case_p5_storage_receipts', function (Blueprint $table) {
            $table->dropColumn('source_header_text');
        });
    }
};
