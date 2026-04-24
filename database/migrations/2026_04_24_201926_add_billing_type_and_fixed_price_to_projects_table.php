<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('billing_type', 32)->default('hourly')->after('billable_rate');
            $table->unsignedBigInteger('fixed_price')->nullable()->after('billing_type');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn(['billing_type', 'fixed_price']);
        });
    }
};
