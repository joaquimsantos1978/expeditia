<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Expedition extends Model
{
    protected $fillable = [
        'wikidata_qid', 'label', 'description', 'image',
        'start_time', 'end_time', 'synced_at',
        'short_name', 'commanded_by', 'vessel', 'organizer',
        'goal', 'field_of_work', 'country', 'named_after',
        'external_url', 'end_cause', 'follows_qid', 'followed_by_qid',
        'num_participants', 'num_deaths',
        'wikipedia_url',
    ];

    public function people()
    {
        return $this->belongsToMany(Person::class, 'expedition_people');
    }

    public function points()
    {
        return $this->hasMany(Point::class)->orderBy('order');
    }

    public function occurrences()
    {
        return $this->hasMany(ExpeditionOccurrence::class);
    }

    public function specimens()
    {
        return $this->belongsToMany(Specimen::class, 'expedition_occurrences');
    }

    public function mapItems()
    {
        return $this->hasMany(MapItem::class)->orderBy('order');
    }
}