<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Institution extends Model
{
    protected $fillable = [
        'code',
        'name',
        'country',
        'city',
        'homepage',
        'gbif_key',
        'wikidata_qid',
        'specimens_count',
        'expeditions_count',
        'images_count',
        'gbif_synced_at',
    ];

    protected $casts = [
        'gbif_synced_at' => 'datetime',
        'specimens_count'   => 'integer',
        'expeditions_count' => 'integer',
        'images_count'      => 'integer',
    ];
}
