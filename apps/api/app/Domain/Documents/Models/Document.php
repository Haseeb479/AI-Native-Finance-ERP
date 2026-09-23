<?php

namespace App\Domain\Documents\Models;

use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Traits\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Document extends Model
{
    use HasFactory, HasUuids, SoftDeletes, BelongsToOrganization;

    protected $table = 'documents';

    protected $fillable = [
        'organization_id',
        'uploaded_by',
        'document_number',
        'document_type',
        'original_filename',
        'storage_disk',
        'storage_path',
        'mime_type',
        'file_size_bytes',
        'ocr_status',
        'ocr_raw_text',
        'ocr_was_flagged',
        'extracted_data',
        'extraction_confidence',
        'human_review_status',
        'human_reviewed_by',
        'human_reviewed_at',
        'human_review_notes',
        'linkable_type',
        'linkable_id',
    ];

    protected $casts = [
        'ocr_was_flagged' => 'boolean',
        'extracted_data' => 'array',
        'extraction_confidence' => 'decimal:2',
        'human_reviewed_at' => 'datetime',
        'file_size_bytes' => 'integer',
    ];

    /**
     * Never expose internal storage paths in serialized output.
     */
    protected $hidden = ['storage_path', 'storage_disk', 'ocr_raw_text'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'human_reviewed_by');
    }

    /**
     * Polymorphic link to a business source document (SalesInvoice, PurchaseBill, etc.)
     */
    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isOcrComplete(): bool
    {
        return $this->ocr_status === 'completed';
    }

    public function isPendingReview(): bool
    {
        return $this->human_review_status === 'pending';
    }

    public function requiresHumanReview(): bool
    {
        return in_array($this->human_review_status, ['pending', 'rejected']);
    }

    public function isApproved(): bool
    {
        return $this->human_review_status === 'approved';
    }

    /**
     * Generate a temporary signed URL for document preview (never expose raw storage path).
     */
    public function getSignedUrl(int $expiresInMinutes = 5): ?string
    {
        try {
            return Storage::disk($this->storage_disk)->temporaryUrl(
                $this->storage_path,
                now()->addMinutes($expiresInMinutes)
            );
        } catch (\Exception $e) {
            // Fallback for local/fake disks in testing
            return null;
        }
    }
}
