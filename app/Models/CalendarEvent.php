<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NoteVisibility;
use App\Models\Concerns\CustomAuditable;
use App\Models\Concerns\HasUuids;
use Database\Factories\CalendarEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $user_id
 * @property string|null $eventable_type
 * @property string|null $eventable_id
 * @property string $title
 * @property string|null $description
 * @property \Illuminate\Support\Carbon $starts_at
 * @property \Illuminate\Support\Carbon $ends_at
 * @property bool $all_day
 * @property NoteVisibility $visibility
 * @property-read Organization $organization
 * @property-read User $user
 * @property-read Project|Task|null $eventable
 *
 * @method static CalendarEventFactory factory()
 */
class CalendarEvent extends Model implements AuditableContract
{
    use CustomAuditable;

    /** @use HasFactory<CalendarEventFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'description',
        'starts_at',
        'ends_at',
        'all_day',
        'visibility',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'title' => 'string',
        'description' => 'string',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'all_day' => 'boolean',
        'visibility' => NoteVisibility::class,
    ];

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return MorphTo<Project|Task, $this>
     */
    public function eventable(): MorphTo
    {
        return $this->morphTo();
    }
}
