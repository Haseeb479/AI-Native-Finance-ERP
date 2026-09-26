<?php

namespace App\Domain\Accounting\Journal\Models;

use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalSequence extends Model
{
    use BelongsToOrganization;

    protected $table = 'journal_sequences';

    protected $fillable = [
        'organization_id',
        'prefix',
        'current_sequence',
    ];

    protected $casts = [
        'current_sequence' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
