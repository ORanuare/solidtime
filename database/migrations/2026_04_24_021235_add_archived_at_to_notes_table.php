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
        Schema::table('notes', function (Blueprint $table): void {
            $table->dateTime('archived_at')->nullable()->after('visibility');
            $table->index(['organization_id', 'archived_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notes', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'archived_at']);
            $table->dropColumn('archived_at');
        });
    }
};
