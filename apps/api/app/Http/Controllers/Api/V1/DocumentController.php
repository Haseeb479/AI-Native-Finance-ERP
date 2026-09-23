<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Services\DocumentService;
use App\Domain\Documents\Services\DocumentStorageService;
use App\Domain\Organization\Models\Organization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\ApproveExtractionRequest;
use App\Http\Requests\Documents\UploadDocumentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    public function __construct(
        protected DocumentService $documentService,
        protected DocumentStorageService $storageService,
    ) {}

    private function getAuthorizedOrganization(Request $request, string $orgId): ?Organization
    {
        return $request->user()?->organizations()
            ->where('organizations.id', $orgId)
            ->first();
    }

    private function unauthorizedResponse(): JsonResponse
    {
        return response()->json([
            'data' => null,
            'meta' => ['timestamp' => now()->toISOString()],
            'errors' => [[
                'code' => 'ORGANIZATION_NOT_FOUND',
                'message' => 'Organization not found or access denied.',
            ]],
        ], 404);
    }

    /**
     * List documents for an organization with optional filters.
     */
    public function index(Request $request, string $orgId): JsonResponse
    {
        $org = $this->getAuthorizedOrganization($request, $orgId);
        if (!$org) return $this->unauthorizedResponse();

        $query = Document::where('organization_id', $org->id)
            ->with(['uploader:id,name', 'reviewer:id,name']);

        if ($request->filled('document_type')) {
            $query->where('document_type', $request->query('document_type'));
        }
        if ($request->filled('ocr_status')) {
            $query->where('ocr_status', $request->query('ocr_status'));
        }
        if ($request->filled('human_review_status')) {
            $query->where('human_review_status', $request->query('human_review_status'));
        }

        $documents = $query->latest()->paginate(20);

        return response()->json([
            'data' => $documents->items(),
            'meta' => [
                'total' => $documents->total(),
                'page' => $documents->currentPage(),
                'per_page' => $documents->perPage(),
                'last_page' => $documents->lastPage(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Upload a document and queue async OCR extraction.
     * Files are stored privately — no public URLs are issued.
     */
    public function store(UploadDocumentRequest $request, string $orgId): JsonResponse
    {
        $org = $this->getAuthorizedOrganization($request, $orgId);
        if (!$org) return $this->unauthorizedResponse();

        try {
            $document = $this->documentService->upload(
                file: $request->file('file'),
                org: $org,
                documentType: $request->input('document_type'),
                user: $request->user(),
                autoOcr: (bool) $request->input('auto_ocr', true),
            );

            return response()->json([
                'data' => $document,
                'meta' => [
                    'message' => 'Document uploaded. OCR extraction has been queued.',
                ],
                'errors' => [],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'data' => null,
                'meta' => [],
                'errors' => [[
                    'code' => 'DOCUMENT_UPLOAD_FAILED',
                    'message' => $e->getMessage(),
                ]],
            ], 422);
        }
    }

    /**
     * View a single document with its extraction data.
     */
    public function show(Request $request, string $orgId, string $id): JsonResponse
    {
        $org = $this->getAuthorizedOrganization($request, $orgId);
        if (!$org) return $this->unauthorizedResponse();

        $document = Document::where('organization_id', $org->id)
            ->with(['uploader:id,name', 'reviewer:id,name'])
            ->findOrFail($id);

        return response()->json([
            'data' => $document,
            'meta' => [],
            'errors' => [],
        ]);
    }

    /**
     * Return a 5-minute signed preview URL.
     * Raw storage paths are NEVER exposed in responses.
     */
    public function previewUrl(Request $request, string $orgId, string $id): JsonResponse
    {
        $org = $this->getAuthorizedOrganization($request, $orgId);
        if (!$org) return $this->unauthorizedResponse();

        $document = Document::where('organization_id', $org->id)->findOrFail($id);
        $url = $this->storageService->getSignedUrl($document, 5);

        return response()->json([
            'data' => [
                'signed_url' => $url,
                'expires_at' => now()->addMinutes(5)->toISOString(),
                'document_id' => $document->id,
                'filename' => $document->original_filename,
            ],
            'meta' => [],
            'errors' => [],
        ]);
    }

    /**
     * Human approves (and optionally corrects) the AI-extracted data.
     */
    public function approve(ApproveExtractionRequest $request, string $orgId, string $id): JsonResponse
    {
        $org = $this->getAuthorizedOrganization($request, $orgId);
        if (!$org) return $this->unauthorizedResponse();

        $document = Document::where('organization_id', $org->id)->findOrFail($id);

        try {
            $updated = $this->documentService->approveExtraction(
                doc: $document,
                correctedData: $request->input('corrected_data', []),
                user: $request->user(),
            );

            return response()->json([
                'data' => $updated,
                'meta' => ['message' => 'Document extraction approved.'],
                'errors' => [],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'data' => null,
                'meta' => [],
                'errors' => [[
                    'code' => 'APPROVAL_FAILED',
                    'message' => $e->getMessage(),
                ]],
            ], 422);
        }
    }

    /**
     * Human rejects the document (illegible scan, wrong document, etc.).
     */
    public function reject(Request $request, string $orgId, string $id): JsonResponse
    {
        $org = $this->getAuthorizedOrganization($request, $orgId);
        if (!$org) return $this->unauthorizedResponse();

        $document = Document::where('organization_id', $org->id)->findOrFail($id);
        $notes = $request->input('notes', 'Rejected by reviewer.');

        $updated = $this->documentService->rejectExtraction(
            doc: $document,
            notes: $notes,
            user: $request->user(),
        );

        return response()->json([
            'data' => $updated,
            'meta' => ['message' => 'Document rejected.'],
            'errors' => [],
        ]);
    }
}
