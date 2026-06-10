<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_sessions', function (Blueprint $table) {
            $table->string('context_language', 10)->nullable()->after('context_document_ids');
        });
    }

    public function down(): void
    {
        Schema::table('audit_sessions', function (Blueprint $table) {
            $table->dropColumn('context_language');
        });
    }
};
