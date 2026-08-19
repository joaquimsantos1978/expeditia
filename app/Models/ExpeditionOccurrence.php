<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpeditionOccurrence extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'expedition_id', 'specimen_id', 'person_id',
    ];

    public function expedition()
    {
        return $this->belongsTo(Expedition::class);
    }

    public function specimen()
    {
        return $this->belongsTo(Specimen::class);
    }

    public function person()
    {
        return $this->belongsTo(Person::class);
    }
}