<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $member_id
 * @property string $currency_code
 * @property int|null $billable_rate Minor units per hour
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Member $member
 */
class MemberCurrencyRate extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'member_id',
        'currency_code',
        'billable_rate',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'currency_code' => 'string',
        'billable_rate' => 'integer',
    ];

    /**
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }
}
