<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_session_id')->constrained()->cascadeOnDelete();
            $table->string('risk_level', 20)->nullable();
            $table->decimal('compliance_score', 5, 2)->nullable();
            $table->text('executive_summary')->nullable();
            $table->json('findings')->nullable();
            $table->json('recommendations')->nullable();
            $table->json('evidence_references')->nullable();
            $table->longText('raw_report')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->index('audit_session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_reports');
    }
};
