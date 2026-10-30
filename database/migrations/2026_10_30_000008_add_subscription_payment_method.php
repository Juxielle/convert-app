<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_intents', fn (Blueprint $table) => $table->string('payment_method', 30)->nullable());
        Schema::table('subscriptions', fn (Blueprint $table) => $table->string('payment_method', 30)->nullable());
    }

    public function down(): void
    {
        Schema::table('subscription_intents', fn (Blueprint $table) => $table->dropColumn('payment_method'));
        Schema::table('subscriptions', fn (Blueprint $table) => $table->dropColumn('payment_method'));
    }
};
