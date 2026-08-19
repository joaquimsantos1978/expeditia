<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Person extends Model
{
protected $fillable = [
    'wikidata_qid', 'name', 'description', 'aliases', 'image',
    'date_birth', 'place_birth', 'date_death', 'place_death',
    'occupation', 'orcid', 'bionomia_id',
    'bionomia_synced_at', 'bionomia_etag', 'synced_at',
    'wikipedia_url',
];
    public function expeditions()
    {
        return $this->belongsToMany(Expedition::class, 'expedition_people');
    }

    public function specimens()
    {
        return $this->hasMany(Specimen::class);
    }
}