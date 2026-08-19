<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institutions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique();         // institution_code from specimens
            $table->string('name')->nullable();            // resolved from GBIF GRSciColl
            $table->string('country')->nullable();         // e.g. "PT", "GB"
            $table->string('city')->nullable();
            $table->string('homepage')->nullable();
            $table->string('gbif_key')->nullable();        // GRSciColl UUID
            $table->string('wikidata_qid', 20)->nullable();// from P664 or manual
            // Pre-computed stats (refreshed on each sync)
            $table->unsignedInteger('specimens_count')->default(0);
            $table->unsignedInteger('expeditions_count')->default(0);
            $table->unsignedInteger('images_count')->default(0);
            $table->timestamp('gbif_synced_at')->nullable();// last GBIF metadata fetch
            $table->timestamps();

            $table->index('specimens_count');
            $table->index('country');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('institutions');
    }
};
