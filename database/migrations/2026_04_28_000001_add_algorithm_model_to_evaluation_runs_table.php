<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('evaluation_runs') || Schema::hasColumn('evaluation_runs', 'algorithm_model')) {
            return;
        }

        Schema::table('evaluation_runs', function (Blueprint $table): void {
            $table->string('algorithm_model', 40)->default('SGMA')->after('title');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('evaluation_runs') || !Schema::hasColumn('evaluation_runs', 'algorithm_model')) {
            return;
        }

        Schema::table('evaluation_runs', function (Blueprint $table): void {
            $table->dropColumn('algorithm_model');
        });
    }
};
