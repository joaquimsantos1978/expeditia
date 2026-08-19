<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('specimens', function (Blueprint $table) {
            // Darwin Core coordinateUncertaintyInMeters — how much to trust
            // the pin (0 = precise GPS, 10000-25000 = locality-based estimate).
            $table->unsignedInteger('coord_uncertainty_m')->nullable()->after('coord_status');
        });
    }

    public function down(): void
    {
        Schema::table('specimens', function (Blueprint $table) {
            $table->dropColumn('coord_uncertainty_m');
        });
    }
};
