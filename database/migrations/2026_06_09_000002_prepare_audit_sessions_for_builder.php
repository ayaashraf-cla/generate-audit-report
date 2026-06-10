<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop the FK constraint on initiated_by before making it nullable.
        Schema::table('audit_sessions', function (Blueprint $table) {
            $table->dropForeign('audit_sessions_initiated_by_foreign');
        });

        Schema::table('audit_sessions', function (Blueprint $table) {
            // Make survey_id nullable so builder-created sessions (no survey) are valid.
            $table->unsignedBigInteger('survey_id')->nullable()->change();

            // Make initiated_by nullable for system/programmatic audit triggers.
            $table->unsignedBigInteger('initiated_by')->nullable()->change();

            // Context storage for the builder API — null for legacy survey sessions.
            $table->longText('context_prompt')->nullable()->after('auditable_id');
            $table->json('context_document_ids')->nullable()->after('context_prompt');
        });
    }

    public function down(): void
    {
        Schema::table('audit_sessions', function (Blueprint $table) {
            $table->dropColumn(['context_prompt', 'context_document_ids']);
        });

        Schema::table('audit_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('survey_id')->nullable(false)->change();
            $table->unsignedBigInteger('initiated_by')->nullable(false)->change();
            $table->foreign('initiated_by')->references('id')->on('users');
        });
    }
};
