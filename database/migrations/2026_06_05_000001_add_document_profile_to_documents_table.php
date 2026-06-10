<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('rag.documents_connection', 'mysql'))->table('documents', function (Blueprint $table) {
            $table->json('document_profile')->nullable()->after('summary_ar');
            $table->timestamp('profiled_at')->nullable()->after('document_profile');
        });
    }

    public function down(): void
    {
        Schema::connection(config('rag.documents_connection', 'mysql'))->table('documents', function (Blueprint $table) {
            $table->dropColumn(['document_profile', 'profiled_at']);
        });
    }
};
