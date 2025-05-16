<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * @method isNotCancelled
 * @property Appointment $appointment
 * @property Collection<array-key, Delivery> $deliveries
 */
class Production extends Model
{
    protected $connection = 'live';

    protected $table = 'productions';

    protected $fillable = [
        'id',
        'description',
        'order_id',
        'amount_of_raw_files',
        'amount_of_delivered_files',
        'delivery_filename',
        'invisible_for_client',
        'delivery_date',
        'created_at',
        'updated_at',
        'notes',
        'employee_notes',
        'product_group',
        'flow_class',
        'flow_busy',
        'current_task',
        'status',
        'product_id',
        'salesforce_id',
        'appointment_id',
        'bright_river_upload_attempts',
        'expected_delivery_date',
        'production_class',
        'legacy_id',
    ];

    protected $casts = [
        'id' => 'integer',
        'order_id' => 'integer',
        'amount_of_raw_files' => 'integer',
        'amount_of_delivered_files' => 'integer',
        'invisible_for_client' => 'integer',
        'flow_busy' => 'integer',
        'appointment_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'expected_delivery_date' => 'datetime',
    ];

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    public function scopeIsNotCancelled(Builder $query): Builder
    {
        return $query->whereNot('status', 'cancelled');
    }

    public function scopeHasAppointment(Builder $query): Builder
    {
        return $query->whereNot('appointment_id', 0);
    }
}
