<?php

declare(strict_types=1);

use App\Models\Project;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Backfill is_paid using fixed-contract payment completion (same rule as Project::syncIsPaidFromPaymentProgress).
     */
    public function up(): void
    {
        Project::query()->chunkById(100, function ($projects): void {
            foreach ($projects as $project) {
                $project->syncIsPaidFromPaymentProgress();
                $project->saveQuietly();
            }
        });
    }

    /**
     * Not reversible: prior manual is_paid semantics are discarded.
     */
    public function down(): void {}
};
