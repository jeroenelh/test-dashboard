<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @method isNotCancelled
 */
class Order extends Model
{
    protected $connection = 'live';

    protected $table = 'orders';

    protected $fillable = [
        'id',
        'legacy_id',
        'date_completed',
        'client_id',
        'address_id',
        'placed_by',
        'salesforce_id',
        'consumer_id',
        'consumer_token',
        'description',
        'invoice_to',
        'other_invoice_id',
        'notes',
        'delivery_mail_sent',
        'reference',
        'cancel_reason',
        'consumer_can_plan',
        'consumer_can_download',
        'consumer_can_get_mail',
        'cubic_eyes_id',
        'object_type',
        'residency_type',
        'surface',
        'outbuildings_surface',
        'plot_surface',
        'created_at',
        'updated_at',
        'flow_class',
        'flow_busy',
        'current_task',
        'status',
        'connected_client_id',
    ];

    protected $casts = [
        'id' => 'integer',
        'legacy_id' => 'integer',
        'client_id' => 'integer',
        'address_id' => 'integer',
        'placed_by' => 'integer',
        'delivery_mail_sent' => 'integer',
        'consumer_can_plan' => 'integer',
        'consumer_can_download' => 'integer',
        'consumer_can_get_mail' => 'integer',
        'surface' => 'integer',
        'flow_busy' => 'integer',
    ];

    public function productions(): HasMany
    {
        return $this->hasMany(Production::class);
    }
}
