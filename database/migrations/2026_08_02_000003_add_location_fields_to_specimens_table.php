<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('specimens', function (Blueprint $table) {
            // Darwin Core location fields GBIF/georeference.it already give
            // us but we weren't capturing — geodeticDatum/continent excluded
            // since GBIF marks those as "Inferred" (computed, not source data).
            $table->string('higher_geography')->nullable()->after('verbatim_locality');
            $table->string('state_province')->nullable()->after('higher_geography');
            $table->string('county')->nullable()->after('state_province');
            $table->string('municipality')->nullable()->after('county');
        });
    }

    public function down(): void
    {
        Schema::table('specimens', function (Blueprint $table) {
            $table->dropColumn(['higher_geography', 'state_province', 'county', 'municipality']);
        });
    }
};
