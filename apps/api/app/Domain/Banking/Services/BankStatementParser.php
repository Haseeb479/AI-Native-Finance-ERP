<?php

namespace App\Domain\Banking\Services;

use Carbon\Carbon;
use InvalidArgumentException;

class BankStatementParser
{
    /**
     * Parse raw CSV content into normalized transaction arrays.
     * Supported CSV structures:
     * Header format 1: Date, Description, Reference, Withdrawal, Deposit, Balance
     * Header format 2: Date, Particulars, Cheque No, Debit, Credit, Balance
     * Header format 3: Date, Description, Reference, Amount, Type (DR/CR), Balance
     *
     * @return array{
     *     from_date: string,
     *     to_date: string,
     *     opening_balance: float,
     *     closing_balance: float,
     *     transactions: array<int, array{
     *         date: string,
     *         description: string,
     *         reference: ?string,
     *         type: string,
     *         amount: float,
     *         balance_after: ?float
     *     }>
     * }
     */
    public function parseCsv(string $csvContent): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csvContent));
        if (empty($lines)) {
            throw new InvalidArgumentException('CSV content is empty.');
        }

        $headerLine = array_shift($lines);
        $headers = str_getcsv($headerLine);
        $headerMap = $this->mapHeaders($headers);

        $transactions = [];
        $dates = [];
        $firstBalance = null;
        $lastBalance = null;

        foreach ($lines as $lineIndex => $rawLine) {
            $line = trim($rawLine);
            if ($line === '') {
                continue;
            }

            $row = str_getcsv($line);
            if (count($row) < count($headers)) {
                // Pad if row has trailing empty elements
                $row = array_pad($row, count($headers), '');
            }

            $dateStr = trim($row[$headerMap['date']] ?? '');
            if ($dateStr === '') {
                continue;
            }

            $date = $this->parseDate($dateStr);
            $dates[] = $date;

            $description = trim($row[$headerMap['description']] ?? 'Bank Transaction');
            $reference = isset($headerMap['reference']) && !empty(trim($row[$headerMap['reference']]))
                ? trim($row[$headerMap['reference']])
                : null;

            // Determine amount & type
            $type = 'credit';
            $amount = 0.0;

            if (isset($headerMap['withdrawal']) && isset($headerMap['deposit'])) {
                $withdrawalStr = $this->cleanAmount($row[$headerMap['withdrawal']] ?? '');
                $depositStr = $this->cleanAmount($row[$headerMap['deposit']] ?? '');

                if ($withdrawalStr > 0) {
                    $type = 'debit';
                    $amount = $withdrawalStr;
                } elseif ($depositStr > 0) {
                    $type = 'credit';
                    $amount = $depositStr;
                }
            } elseif (isset($headerMap['amount'])) {
                $rawAmount = $this->cleanAmount($row[$headerMap['amount']] ?? '');
                if (isset($headerMap['type'])) {
                    $rawType = strtoupper(trim($row[$headerMap['type']] ?? ''));
                    $type = in_array($rawType, ['DR', 'DEBIT', 'WITHDRAWAL']) ? 'debit' : 'credit';
                    $amount = abs($rawAmount);
                } else {
                    $type = $rawAmount < 0 ? 'debit' : 'credit';
                    $amount = abs($rawAmount);
                }
            }

            $balanceAfter = null;
            if (isset($headerMap['balance'])) {
                $balStr = trim($row[$headerMap['balance']] ?? '');
                if ($balStr !== '') {
                    $balanceAfter = $this->cleanAmount($balStr);
                    if ($firstBalance === null) {
                        $firstBalance = $balanceAfter;
                    }
                    $lastBalance = $balanceAfter;
                }
            }

            $transactions[] = [
                'date' => $date,
                'description' => $description,
                'reference' => $reference,
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $balanceAfter,
            ];
        }

        if (empty($transactions)) {
            throw new InvalidArgumentException('No valid transaction rows found in CSV.');
        }

        sort($dates);
        $fromDate = $dates[0];
        $toDate = end($dates);

        return [
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'opening_balance' => $firstBalance ?? 0.0,
            'closing_balance' => $lastBalance ?? 0.0,
            'transactions' => $transactions,
        ];
    }

    private function mapHeaders(array $headers): array
    {
        $map = [];
        foreach ($headers as $index => $raw) {
            $h = strtolower(trim(str_replace(['"', "'"], '', $raw)));

            if (in_array($h, ['date', 'txn date', 'transaction date', 'posting date', 'value date'])) {
                $map['date'] = $index;
            } elseif (in_array($h, ['description', 'particulars', 'narration', 'details', 'memo'])) {
                $map['description'] = $index;
            } elseif (in_array($h, ['reference', 'ref', 'ref no', 'ref number', 'cheque no', 'chq no', 'instrument no'])) {
                $map['reference'] = $index;
            } elseif (in_array($h, ['withdrawal', 'withdrawals', 'debit', 'dr', 'paid out'])) {
                $map['withdrawal'] = $index;
            } elseif (in_array($h, ['deposit', 'deposits', 'credit', 'cr', 'paid in'])) {
                $map['deposit'] = $index;
            } elseif (in_array($h, ['amount', 'txn amount', 'transaction amount'])) {
                $map['amount'] = $index;
            } elseif (in_array($h, ['type', 'dr/cr', 'cr/dr', 'd/c'])) {
                $map['type'] = $index;
            } elseif (in_array($h, ['balance', 'running balance', 'ledger balance'])) {
                $map['balance'] = $index;
            }
        }

        if (!isset($map['date'])) {
            throw new InvalidArgumentException('Missing required "Date" column in bank statement CSV.');
        }

        if (!isset($map['withdrawal']) && !isset($map['deposit']) && !isset($map['amount'])) {
            throw new InvalidArgumentException('Missing amount columns (Debit/Credit or Amount) in bank statement CSV.');
        }

        if (!isset($map['description'])) {
            $map['description'] = $map['reference'] ?? $map['date'];
        }

        return $map;
    }

    private function cleanAmount(string $value): float
    {
        $cleaned = preg_replace('/[^\d\.\-]/', '', trim($value));
        return (float) $cleaned;
    }

    private function parseDate(string $dateStr): string
    {
        $formats = ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'Y/m/d', 'd-M-Y', 'd-M-y'];
        foreach ($formats as $format) {
            try {
                return Carbon::createFromFormat($format, trim($dateStr))->format('Y-m-d');
            } catch (\Exception $e) {
                // Continue trying other formats
            }
        }

        return Carbon::parse($dateStr)->format('Y-m-d');
    }
}
