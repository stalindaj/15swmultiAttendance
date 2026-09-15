<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->unique()->after('name');
            $table->string('role')->default('scanner')->after('username');    // admin | scanner
            $table->boolean('is_active')->default(true)->after('role');
            $table->unsignedInteger('session_version')->default(0)->after('is_active');
            $table->string('email')->nullable()->change();
        });

        Schema::table('scans', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('personnel_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['username', 'role', 'is_active', 'session_version']);
        });
    }
};
