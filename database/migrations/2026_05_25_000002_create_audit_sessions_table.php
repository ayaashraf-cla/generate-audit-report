<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id');
            $table->foreignId('response_id')->nullable();
            $table->foreignId('initiated_by')->constrained('users');
            $table->string('provider', 50)->default('gemini');
            $table->string('thread_id')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('current_step')->default(0);
            $table->string('step_label', 150)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('survey_id');
            $table->index('response_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_sessions');
    }
};
