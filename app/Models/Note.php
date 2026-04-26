<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NoteVisibility;
use App\Models\Concerns\CustomAuditable;
use App\Models\Concerns\HasUuids;
use Database\Factories\NoteFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $user_id
 * @property string|null $notable_type
 * @property string|null $notable_id
 * @property string $body
 * @property NoteVisibility $visibility
 * @property-read bool $is_archived
 * @property Carbon|null $archived_at
 * @property-read Organization $organization
 * @property-read User $user
 * @property-read Project|Task|null $notable
 *
 * @method static NoteFactory factory()
 */
class Note extends Model implements AuditableContract
{
    use CustomAuditable;

    /** @use HasFactory<NoteFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'body',
        'visibility',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'body' => 'string',
        'visibility' => NoteVisibility::class,
        'archived_at' => 'datetime',
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
    public function notable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return Attribute<bool, never>
     */
    protected function isArchived(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, array $attributes) => isset($attributes['archived_at']),
        );
    }
}
