<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_step_logs', function (Blueprint $table) {
            $table->json('messages_snapshot')->nullable()->after('output');
        });
    }

    public function down(): void
    {
        Schema::table('audit_step_logs', function (Blueprint $table) {
            $table->dropColumn('messages_snapshot');
        });
    }
};
