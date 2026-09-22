<?php

namespace App\Domain\Banking\Models;

use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Traits\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankStatement extends Model
{
    use HasFactory, HasUuids, BelongsToOrganization;

    protected $table = 'bank_statements';

    protected $fillable = [
        'organization_id',
        'bank_account_id',
        'statement_number',
        'from_date',
        'to_date',
        'opening_balance',
        'closing_balance',
        'source_file_name',
        'status',
        'uploaded_by',
    ];

    protected $casts = [
        'from_date' => 'date',
        'to_date' => 'date',
        'opening_balance' => 'decimal:4',
        'closing_balance' => 'decimal:4',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class, 'bank_statement_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
