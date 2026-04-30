<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('withings_access_token')->nullable()->after('avatar');
            $table->text('withings_refresh_token')->nullable()->after('withings_access_token');
            $table->timestamp('withings_token_expires_at')->nullable()->after('withings_refresh_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'withings_access_token',
                'withings_refresh_token',
                'withings_token_expires_at',
            ]);
        });
    }
};
