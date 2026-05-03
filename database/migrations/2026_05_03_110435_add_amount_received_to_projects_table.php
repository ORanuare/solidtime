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
        Schema::table('projects', function (Blueprint $table): void {
            $table->unsignedBigInteger('amount_received')->nullable()->after('fixed_price');
        });

        DB::table('projects')
            ->where('billing_type', 'fixed')
            ->whereNotNull('fixed_price')
            ->where('is_paid', '=', true)
            ->update(['amount_received' => DB::raw('fixed_price')]);

        DB::table('projects')
            ->where('billing_type', 'fixed')
            ->whereNotNull('fixed_price')
            ->where('is_paid', '=', false)
            ->update(['amount_received' => 0]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('amount_received');
        });
    }
};
