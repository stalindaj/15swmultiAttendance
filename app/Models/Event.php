<?php

namespace App\Models;

use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An occasion attendance is taken for (Safety Meeting, formation, ...), with its own attendee list. */
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'event_date' => 'date:Y-m-d',
        'is_open' => 'boolean',
    ];

    public function attendees(): BelongsToMany
    {
        return $this->belongsToMany(Personnel::class, 'event_attendees');
    }

    public function scans(): HasMany
    {
        return $this->hasMany(Scan::class);
    }

    public function label(): string
    {
        return $this->name.' — '.$this->event_date->format('d M Y');
    }

    /**
     * @return array{id: int, name: string, date: string, label: string, is_open: bool}
     */
    public function card(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'date' => $this->event_date->format('Y-m-d'),
            'label' => $this->label(),
            'is_open' => $this->is_open,
        ];
    }
}
