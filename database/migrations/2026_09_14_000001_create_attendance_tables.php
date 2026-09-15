<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personnel', function (Blueprint $table) {
            $table->id();
            $table->string('rank')->default('');
            $table->string('surname')->default('');
            $table->string('first_name')->default('');
            $table->string('middle')->default('');
            $table->string('full_name')->default('');
            $table->string('serial')->default('')->index();
            $table->string('squadron')->default('');
            $table->string('office')->default('');
            $table->string('psr_status')->default('');   // ON DUTY, DEPLOYED, ORD LEAVE ... from the PSR
            $table->boolean('is_walk_in')->default(false);
            // Normalized keys used for matching QR text against the roster.
            $table->string('surname_key')->default('')->index();
            $table->string('name_key')->default('');
            $table->string('squadron_key')->default('');
            $table->string('office_key')->default('');
            $table->timestamps();
        });

        Schema::create('qr_links', function (Blueprint $table) {
            $table->string('qr_text')->primary();
            $table->foreignId('personnel_id')->constrained('personnel')->cascadeOnDelete();
            $table->string('source')->default('confirmed');
            $table->timestamps();
        });

        Schema::create('scans', function (Blueprint $table) {
            $table->id();
            $table->string('client_id')->nullable()->unique();
            $table->string('qr_text')->nullable()->index();
            $table->foreignId('personnel_id')->nullable()->constrained('personnel')->nullOnDelete();
            $table->string('kind', 3);                 // IN | OUT
            $table->string('station')->default('');
            $table->string('method')->default('');     // camera | photo | keyboard | manual
            $table->date('day')->index();
            $table->dateTime('scanned_at');
            $table->string('status');                  // ok | duplicate | pending | void
            $table->string('note')->default('');
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('scans');
        Schema::dropIfExists('qr_links');
        Schema::dropIfExists('personnel');
    }
};
