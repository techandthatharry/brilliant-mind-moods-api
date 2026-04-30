<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mood_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->float('score');            // mood: -5 to +5
            $table->float('sleep_score');      // behaviour: 0 to 8
            $table->float('appetite_score');
            $table->float('activity_score');
            $table->float('interests_score');
            $table->float('social_score');
            $table->float('focus_score');
            $table->text('diary')->nullable();
            $table->boolean('medication_unchanged')->default(true);
            $table->json('medications_snapshot')->nullable(); // snapshot of meds at time of entry
            $table->date('entry_date');
            $table->timestamps();

            $table->unique(['user_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mood_entries');
    }
};
