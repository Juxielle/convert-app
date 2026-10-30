<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversion_jobs', fn (Blueprint $table) => $table->uuid('batch_id')->nullable()->index());
    }

    public function down(): void
    {
        Schema::table('conversion_jobs', fn (Blueprint $table) => $table->dropColumn('batch_id'));
    }
};
