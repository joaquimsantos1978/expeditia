<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MapItem extends Model
{
    protected $fillable = [
        'expedition_id',
        'order',
        'type',
        'source_id',
        'date_label',
        'name',
        'description',
        'image',
        'lat',
        'lon',
    ];

    public function expedition()
    {
        return $this->belongsTo(Expedition::class);
    }
    public function specimen()
    {
        return $this->belongsTo(Specimen::class, 'source_id')
            ->where('type', 'specimen');
    }
}
