<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $currency_code
 * @property int|null $default_billable_rate Minor units per hour
 * @property int|null $sort_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Organization $organization
 */
class OrganizationCurrency extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'currency_code',
        'default_billable_rate',
        'sort_order',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'currency_code' => 'string',
        'default_billable_rate' => 'integer',
        'sort_order' => 'integer',
    ];

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
}
