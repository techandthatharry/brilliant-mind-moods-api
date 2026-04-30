<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('body_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('measured_at');
            $table->decimal('weight_kg', 6, 2)->nullable();
            $table->decimal('fat_percentage', 5, 2)->nullable();
            $table->decimal('bmi', 5, 2)->nullable();
            $table->unsignedBigInteger('withings_group_id')->nullable()->index();
            $table->timestamps();

            // One record per user per day — later syncs overwrite earlier ones
            $table->unique(['user_id', 'measured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('body_metrics');
    }
};
