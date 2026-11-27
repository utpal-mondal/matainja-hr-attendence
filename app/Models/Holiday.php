<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Not cached anywhere: GET /holidays always reads the table. */
class Holiday extends Model
{
    protected $fillable = ['branch_id', 'holiday_date', 'name', 'type'];

    protected function casts(): array
    {
        return ['holiday_date' => 'date'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
