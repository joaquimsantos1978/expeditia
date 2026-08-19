<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('specimens', function (Blueprint $table) {
            $table->string('coord_source', 20)->nullable()->after('lon'); // 'gbif' | 'bionomia' | 'georeference'
            $table->string('coord_status', 20)->nullable()->after('coord_source'); // only set when coord_source='georeference': 'validated' | 'has_suggestion'
        });

        // Coordinates saved before this feature could only have come from GBIF/Bionomia.
        DB::table('specimens')
            ->whereNotNull('lat')
            ->whereNull('coord_source')
            ->update(['coord_source' => 'gbif']);
    }

    public function down(): void
    {
        Schema::table('specimens', function (Blueprint $table) {
            $table->dropColumn(['coord_source', 'coord_status']);
        });
    }
};
