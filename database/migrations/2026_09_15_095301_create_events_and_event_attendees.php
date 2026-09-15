<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every scan now belongs to an event (Safety Meeting, formation, ...) with its own attendee list.
     * Scans recorded before events existed are moved into one "Attendance" event per day, expecting
     * everyone who was on the roster.
     */
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('event_date')->index();
            $table->boolean('is_open')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('event_attendees', function (Blueprint $table) {
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('personnel_id')->constrained('personnel')->cascadeOnDelete();
            $table->primary(['event_id', 'personnel_id']);
        });

        Schema::table('scans', function (Blueprint $table) {
            $table->foreignId('event_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        $roster = DB::table('personnel')->where('is_walk_in', false)->pluck('id');
        foreach (DB::table('scans')->distinct()->orderBy('day')->pluck('day') as $day) {
            $day = substr((string) $day, 0, 10);
            $eventId = DB::table('events')->insertGetId([
                'name' => 'Attendance',
                'event_date' => $day,
                'is_open' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            foreach ($roster->chunk(500) as $chunk) {
                DB::table('event_attendees')->insert($chunk->map(fn ($id) => ['event_id' => $eventId, 'personnel_id' => $id])->all());
            }
            DB::table('scans')->where('day', $day)->update(['event_id' => $eventId]);
        }
    }

    public function down(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('event_id');
        });
        Schema::dropIfExists('event_attendees');
        Schema::dropIfExists('events');
    }
};
