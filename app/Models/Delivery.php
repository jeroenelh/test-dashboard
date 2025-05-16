<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @method isNotCancelled
 * @property Appointment $appointment
 */
class Delivery extends Model
{
    protected $connection = 'live';

    protected $table = 'deliveries';

    protected $fillable = [
        'id',
        'production_id',
        'salesforce_id',
        'filename',
        'manual_upload',
        'is_revision',
        'username',
        'floorplanner_id',
        'created_at',
        'updated_at',
        'order_id',
    ];

    protected $casts = [
        'id' => 'integer',
        'production_id' => 'integer',
        'manual_upload' => 'bool',
        'is_revision' => 'bool',
        'floorplanner_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'order_id' => 'integer',
    ];

    public function production(): BelongsTo
    {
        return $this->belongsTo(Production::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
