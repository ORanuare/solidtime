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
        Schema::create('organization_currencies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')
                ->constrained()
                ->cascadeOnDelete()
                ->restrictOnUpdate();
            $table->string('currency_code', 3);
            $table->unsignedInteger('default_billable_rate')->nullable();
            $table->unsignedSmallInteger('sort_order')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'currency_code']);
        });

        Schema::create('member_currency_rates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('member_id')
                ->references('id')
                ->on('members')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->string('currency_code', 3);
            $table->unsignedInteger('billable_rate')->nullable();
            $table->timestamps();
            $table->unique(['member_id', 'currency_code']);
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->string('currency', 3)->nullable()->after('organization_id');
        });

        $now = now();
        foreach (DB::table('organizations')->select(['id', 'currency', 'billable_rate'])->cursor() as $org) {
            DB::table('organization_currencies')->insert([
                'id' => (string) Str::uuid(),
                'organization_id' => $org->id,
                'currency_code' => $org->currency,
                'default_billable_rate' => $org->billable_rate,
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (DB::table('members')->select(['id', 'billable_rate', 'organization_id'])->cursor() as $member) {
            if ($member->billable_rate === null) {
                continue;
            }
            $currency = DB::table('organizations')
                ->where('id', '=', $member->organization_id)
                ->value('currency');
            if ($currency === null) {
                continue;
            }
            DB::table('member_currency_rates')->insert([
                'id' => (string) Str::uuid(),
                'member_id' => $member->id,
                'currency_code' => $currency,
                'billable_rate' => $member->billable_rate,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::statement('
            UPDATE projects
            SET currency = organizations.currency
            FROM organizations
            WHERE projects.organization_id = organizations.id
        ');

        Schema::table('projects', function (Blueprint $table): void {
            $table->string('currency', 3)->nullable(false)->change();
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->foreign(['organization_id', 'currency'])
                ->references(['organization_id', 'currency_code'])
                ->on('organization_currencies')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropForeign(['organization_id', 'currency']);
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('currency');
        });

        Schema::dropIfExists('member_currency_rates');

        Schema::dropIfExists('organization_currencies');
    }
};
