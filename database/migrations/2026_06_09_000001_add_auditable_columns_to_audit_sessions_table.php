<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_sessions', function (Blueprint $table) {
            $table->string('auditable_type', 255)->nullable()->after('response_id');
            $table->unsignedBigInteger('auditable_id')->nullable()->after('auditable_type');

            $table->index(['auditable_type', 'auditable_id'], 'audit_sessions_auditable_index');
        });
    }

    public function down(): void
    {
        Schema::table('audit_sessions', function (Blueprint $table) {
            $table->dropIndex('audit_sessions_auditable_index');
            $table->dropColumn(['auditable_type', 'auditable_id']);
        });
    }
};
