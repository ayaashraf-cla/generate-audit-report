<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_step_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_session_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('step');
            $table->string('step_name', 100);
            $table->string('status', 20);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('output')->nullable();
            $table->json('token_usage')->nullable();
            $table->unsignedTinyInteger('chunks_retrieved')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['audit_session_id', 'step']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_step_logs');
    }
};
