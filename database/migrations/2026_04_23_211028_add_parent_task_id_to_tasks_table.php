<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->uuid('parent_task_id')->nullable();
            $table->foreign('parent_task_id')
                ->references('id')
                ->on('tasks')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
        });

        DB::statement('CREATE UNIQUE INDEX tasks_project_root_name_unique ON tasks (project_id, name) WHERE parent_task_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX tasks_parent_child_name_unique ON tasks (parent_task_id, name) WHERE parent_task_id IS NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS tasks_parent_child_name_unique');
        DB::statement('DROP INDEX IF EXISTS tasks_project_root_name_unique');

        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropForeign(['parent_task_id']);
            $table->dropColumn('parent_task_id');
        });
    }
};
