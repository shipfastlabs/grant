<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class RoleAssignment extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /** @return BelongsTo<Model, $this> */
    public function user(): BelongsTo
    {
        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model');

        return $this->belongsTo($model, 'user_id');
    }

    /** @return MorphTo<Model, $this> */
    public function scopeable(): MorphTo
    {
        return $this->morphTo();
    }
}
