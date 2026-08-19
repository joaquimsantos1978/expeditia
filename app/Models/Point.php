<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Point extends Model
{
    protected $fillable = [
        'expedition_id', 'wikidata_qid', 'type', 'name', 'description',
        'image', 'lat', 'lon',
        'start_time', 'end_time', 'point_in_time', 'order',
    ];

    public function expedition()
    {
        return $this->belongsTo(Expedition::class);
    }
}