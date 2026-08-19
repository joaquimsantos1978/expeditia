<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('specimens', function (Blueprint $table) {
            // When GBIF was last successfully checked for this specimen.
            // Metadata/coordinates already "complete" only get re-checked
            // once this is older than GbifService::STALE_DAYS, or on a
            // forced resync — publishers do correct their records over time.
            $table->timestamp('gbif_synced_at')->nullable()->after('coord_uncertainty_m');
        });
    }

    public function down(): void
    {
        Schema::table('specimens', function (Blueprint $table) {
            $table->dropColumn('gbif_synced_at');
        });
    }
};
