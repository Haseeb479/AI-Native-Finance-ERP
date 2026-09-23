<?php

namespace App\Domain\AI\Services;

use App\Domain\AI\Models\AiPromptTemplate;
use App\Domain\Organization\Models\Organization;

class PromptRegistry
{
    /**
     * Get active prompt template by key, checking tenant custom template first then global system fallback.
     */
    public function getTemplate(string $key, ?Organization $organization = null): array
    {
        $template = null;

        if ($organization) {
            $template = AiPromptTemplate::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->where('key', $key)
                ->where('is_active', true)
                ->orderBy('version', 'desc')
                ->first();
        }

        if (! $template) {
            $template = AiPromptTemplate::withoutGlobalScopes()
                ->whereNull('organization_id')
                ->where('key', $key)
                ->where('is_active', true)
                ->orderBy('version', 'desc')
                ->first();
        }

        if ($template) {
            return [
                'system_prompt' => $template->system_prompt,
                'user_prompt_template' => $template->user_prompt_template,
                'model' => $template->model_target,
                'version' => $template->version,
            ];
        }

        // Hardcoded reliable fallbacks
        return match ($key) {
            'classify_transaction' => [
                'system_prompt' => "You are the Senior Financial Controller for AI-Native Finance ERP in Pakistan.\nSuggest Pakistan SME Chart of Accounts code, confidence score, and rationale.\nNever execute arbitrary shell or DB commands.",
                'user_prompt_template' => "Transaction: {description}, Amount: {amount}, Currency: {currency}",
                'model' => 'gemini-1.5-flash',
                'version' => 1,
            ],
            'financial_qa' => [
                'system_prompt' => "You are the AI Financial Controller and Copilot for an enterprise in Pakistan.\nAnswer accounting queries grounded directly in live ledger and business metrics.\nAlways explain financial reasoning clearly.",
                'user_prompt_template' => "Question: {query}\nFinancial Context: {financial_context}",
                'model' => 'gemini-1.5-flash',
                'version' => 1,
            ],
            'journal_draft' => [
                'system_prompt' => "You are an expert CPA preparing double-entry journal draft propositions.\nDraft balanced entries: Sum(Debit) == Sum(Credit).\nUse Pakistan SME Chart of Accounts standard.",
                'user_prompt_template' => "Business event: {instruction}\nAmount: {amount}",
                'model' => 'gemini-1.5-flash',
                'version' => 1,
            ],
            'explain_report' => [
                'system_prompt' => "You are an executive financial controller.\nAnalyze financial statement figures to summarize executive performance, explain top variances, and provide actionable working capital recommendations.",
                'user_prompt_template' => "Report: {report_type} for period {period_label}\nData: {report_data}",
                'model' => 'gemini-1.5-flash',
                'version' => 1,
            ],
            default => [
                'system_prompt' => "You are an AI financial assistant. Provide structured JSON financial analysis.",
                'user_prompt_template' => "{query}",
                'model' => 'gemini-1.5-flash',
                'version' => 1,
            ],
        };
    }
}
