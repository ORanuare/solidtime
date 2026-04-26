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
        DB::table('notes')->orderBy('id')->chunk(100, function ($notes): void {
            foreach ($notes as $note) {
                $title = trim((string) $note->title);
                $body = (string) $note->body;
                if ($title === '') {
                    continue;
                }
                if (trim($body) === '') {
                    DB::table('notes')->where('id', $note->id)->update(['body' => $title]);
                } else {
                    DB::table('notes')->where('id', $note->id)->update(['body' => rtrim($title)."\n".$body]);
                }
            }
        });

        Schema::table('notes', function (Blueprint $table): void {
            $table->dropColumn('title');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notes', function (Blueprint $table): void {
            $table->string('title', 500)->after('notable_id')->default('');
        });
    }
};
