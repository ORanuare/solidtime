<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->foreignUuid('user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->string('eventable_type', 255)->nullable();
            $table->uuid('eventable_id')->nullable();
            $table->string('title', 500);
            $table->text('description')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->boolean('all_day')->default(false);
            $table->string('visibility', 20);
            $table->timestamps();

            $table->index(['eventable_type', 'eventable_id']);
            $table->index(['organization_id', 'starts_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
