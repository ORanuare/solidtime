<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('calendar_event_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('calendar_event_id');
            $table->foreign('calendar_event_id')
                ->references('id')
                ->on('calendar_events')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->string('assignable_type', 255);
            $table->uuid('assignable_id');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['calendar_event_id', 'assignable_type', 'assignable_id'], 'calendar_event_assign_unique');
            $table->index(['assignable_type', 'assignable_id']);
        });

        $rows = DB::table('calendar_events')
            ->whereNotNull('eventable_type')
            ->whereNotNull('eventable_id')
            ->get(['id', 'eventable_type', 'eventable_id']);

        $now = now();
        foreach ($rows as $row) {
            DB::table('calendar_event_assignments')->insert([
                'id' => (string) Str::uuid(),
                'calendar_event_id' => $row->id,
                'assignable_type' => $row->eventable_type,
                'assignable_id' => $row->eventable_id,
                'position' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::table('calendar_events', function (Blueprint $table): void {
            $table->dropIndex(['eventable_type', 'eventable_id']);
            $table->dropColumn(['eventable_type', 'eventable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table): void {
            $table->string('eventable_type', 255)->nullable();
            $table->uuid('eventable_id')->nullable();
            $table->index(['eventable_type', 'eventable_id']);
        });

        $assignments = DB::table('calendar_event_assignments')
            ->orderBy('calendar_event_id')
            ->orderBy('position')
            ->orderBy('id')
            ->get(['calendar_event_id', 'assignable_type', 'assignable_id']);

        $seen = [];
        foreach ($assignments as $assignment) {
            if (isset($seen[$assignment->calendar_event_id])) {
                continue;
            }
            $seen[$assignment->calendar_event_id] = true;
            DB::table('calendar_events')
                ->where('id', $assignment->calendar_event_id)
                ->update([
                    'eventable_type' => $assignment->assignable_type,
                    'eventable_id' => $assignment->assignable_id,
                ]);
        }

        Schema::dropIfExists('calendar_event_assignments');
    }
};
