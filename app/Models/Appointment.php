<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * @method isCompleted
 * @property int $id
 * @property int $order_id
 * @property string $status
 * @property Carbon $scheduled_at
 * @property Collection<array-key, Production> $productions
 * @property Order $order
 *
 */
class Appointment extends Model
{
    protected $connection = 'live';

    protected $table = 'appointments';

    protected $fillable = [
        'id',
        'legacy_id',
        'order_id',
        'cancelled_by',
        'employee_id',
        'duration',
        'return_appointment',
        'return_appointment_notes',
        'travel_time_to',
        'travel_time_from',
        'product_groups',
        'planner_name',
        'planner_type',
        'region_id',
        'on_hold_until',
        'created_at',
        'updated_at',
        'flow_class',
        'flow_busy',
        'current_task',
        'status',
        'full_address',
        'retrieve_key_address_id',
        'estimate_time',
        'planner_id',
        'scheduled_with_preferred_photographer',
        'scheduled_at',
        'description',
        'options_within_5_days',
    ];

    protected $casts = [
        'id' => 'integer',
        'legacy_id' => 'integer',
        'order_id' => 'integer',
        'cancelled_by' => 'integer',
        'employee_id' => 'integer',
        'duration' => 'integer',
        'return_appointment' => 'integer',
        'travel_time_to' => 'integer',
        'travel_time_from' => 'integer',
        'on_hold_until' => 'Datetime',
        'created_at' => 'Datetime',
        'updated_at' => 'Datetime',
        'flow_busy' => 'integer',
        'estimate_time' => 'integer',
        'planner_id' => 'integer',
        'scheduled_at' => 'datetime',
        'scheduled_with_preferred_photographer' => 'integer',
        'options_within_5_days' => 'integer',
    ];

    protected $appends = [
        'scheduled_date',
    ];

    public function scopeIsCompleted(Builder $query)
    {
        return $query->where('status', 'completed');
    }

    public function productions(): HasMany
    {
        return $this->hasMany(Production::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Production::class);
    }

    public function getScheduledDateAttribute()
    {
        return $this->scheduled_at->format('Y-m-d');
    }
}
