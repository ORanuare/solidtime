<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property string $id
 * @property string $calendar_event_id
 * @property string $assignable_type
 * @property string $assignable_id
 * @property int $position
 * @property-read CalendarEvent $calendarEvent
 * @property-read Project|Task $assignable
 */
class CalendarEventAssignment extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'calendar_event_id',
        'assignable_type',
        'assignable_id',
        'position',
    ];

    /**
     * @return BelongsTo<CalendarEvent, $this>
     */
    public function calendarEvent(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'calendar_event_id');
    }

    /**
     * @return MorphTo<Project|Task, $this>
     */
    public function assignable(): MorphTo
    {
        return $this->morphTo();
    }
}
