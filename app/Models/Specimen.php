<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Specimen extends Model
{
    protected $fillable = [
        'person_id', 'gbif_id', 'dataset_key', 'occurrence_id',
        'action', 'basis_of_record', 'license',
        'scientific_name', 'family', 'kingdom', 'type_status',
        'recorded_by', 'recorded_by_id', 'identified_by', 'identified_by_id',
        'institution_code', 'collection_code', 'catalog_number',
        'date_identified', 'event_date', 'year',
        'country', 'country_code', 'lat', 'lon', 'coord_source', 'coord_status', 'coord_uncertainty_m', 'gbif_synced_at',
        'image', 'gbif_url',
        'bionomia_created_by', 'bionomia_created_by_uri', 'bionomia_created','locality',
'verbatim_locality',
'image',
        'higher_geography', 'state_province', 'county', 'municipality',
    ];

    protected $casts = [
        'gbif_synced_at' => 'datetime',
    ];

    public function person()
    {
        return $this->belongsTo(Person::class);
    }

    public function expeditions()
    {
        return $this->belongsToMany(Expedition::class, 'expedition_occurrences');
    }
}