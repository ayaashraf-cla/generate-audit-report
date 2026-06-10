<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_step_logs', function (Blueprint $table) {
            $table->longText('prompt')->nullable()->change();
        });

        Schema::table('audit_sessions', function (Blueprint $table) {
            $table->mediumText('error_message')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('audit_step_logs', function (Blueprint $table) {
            $table->text('prompt')->nullable()->change();
        });

        Schema::table('audit_sessions', function (Blueprint $table) {
            $table->text('error_message')->nullable()->change();
        });
    }
};
