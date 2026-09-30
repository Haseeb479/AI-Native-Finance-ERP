<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Migration safety: transactional execution guarantees atomic DDL/DML application (P1-37).
     */
    public $withinTransaction = true;

    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            // 1. journal_entries accounting constraints (P1-23)
            DB::statement('ALTER TABLE journal_entries DROP CONSTRAINT IF EXISTS check_journal_entries_status');
            DB::statement('ALTER TABLE journal_entries DROP CONSTRAINT IF EXISTS check_journal_entries_total_amount');
            DB::statement("ALTER TABLE journal_entries ADD CONSTRAINT check_journal_entries_status CHECK (status IN ('draft', 'posted', 'void', 'voided', 'archived'))");
            DB::statement('ALTER TABLE journal_entries ADD CONSTRAINT check_journal_entries_total_amount CHECK (total_amount >= 0)');

            // 2. sales_invoices accounting constraints (P1-23)
            DB::statement('ALTER TABLE sales_invoices DROP CONSTRAINT IF EXISTS check_sales_invoices_status');
            DB::statement('ALTER TABLE sales_invoices DROP CONSTRAINT IF EXISTS check_sales_invoices_amounts');
            DB::statement("ALTER TABLE sales_invoices ADD CONSTRAINT check_sales_invoices_status CHECK (status IN ('draft', 'pending_approval', 'approved', 'posted', 'paid', 'partially_paid', 'partial', 'sent', 'void', 'voided', 'rejected'))");
            DB::statement('ALTER TABLE sales_invoices ADD CONSTRAINT check_sales_invoices_amounts CHECK (subtotal >= 0 AND tax_amount >= 0 AND total_amount >= 0 AND amount_paid >= 0)');

            // 3. sales_invoice_lines accounting constraints (P1-23)
            DB::statement('ALTER TABLE sales_invoice_lines DROP CONSTRAINT IF EXISTS check_sales_invoice_lines_values');
            DB::statement('ALTER TABLE sales_invoice_lines ADD CONSTRAINT check_sales_invoice_lines_values CHECK (quantity > 0 AND unit_price >= 0 AND subtotal >= 0 AND total >= 0)');

            // 4. purchase_bills accounting constraints (P1-23)
            DB::statement('ALTER TABLE purchase_bills DROP CONSTRAINT IF EXISTS check_purchase_bills_status');
            DB::statement('ALTER TABLE purchase_bills DROP CONSTRAINT IF EXISTS check_purchase_bills_amounts');
            DB::statement("ALTER TABLE purchase_bills ADD CONSTRAINT check_purchase_bills_status CHECK (status IN ('draft', 'pending_approval', 'approved', 'posted', 'received', 'paid', 'partially_paid', 'partial', 'void', 'voided', 'rejected'))");
            DB::statement('ALTER TABLE purchase_bills ADD CONSTRAINT check_purchase_bills_amounts CHECK (subtotal >= 0 AND tax_amount >= 0 AND wht_amount >= 0 AND total_amount >= 0)');

            // 5. purchase_bill_lines accounting constraints (P1-23)
            DB::statement('ALTER TABLE purchase_bill_lines DROP CONSTRAINT IF EXISTS check_purchase_bill_lines_values');
            DB::statement('ALTER TABLE purchase_bill_lines ADD CONSTRAINT check_purchase_bill_lines_values CHECK (quantity > 0 AND unit_price >= 0 AND subtotal >= 0)');

            // 6. bank_transactions accounting constraints (P1-23)
            DB::statement('ALTER TABLE bank_transactions DROP CONSTRAINT IF EXISTS check_bank_transactions_amount');
            DB::statement('ALTER TABLE bank_transactions DROP CONSTRAINT IF EXISTS check_bank_transactions_type');
            DB::statement('ALTER TABLE bank_transactions DROP CONSTRAINT IF EXISTS check_bank_transactions_status');
            DB::statement('ALTER TABLE bank_transactions ADD CONSTRAINT check_bank_transactions_amount CHECK (amount > 0)');
            DB::statement("ALTER TABLE bank_transactions ADD CONSTRAINT check_bank_transactions_type CHECK (type IN ('debit', 'credit'))");
            DB::statement("ALTER TABLE bank_transactions ADD CONSTRAINT check_bank_transactions_status CHECK (reconciliation_status IN ('unreconciled', 'matched', 'reconciled', 'disputed'))");

            // 7. accounts accounting constraints (P1-23)
            DB::statement('ALTER TABLE accounts DROP CONSTRAINT IF EXISTS check_accounts_classification');
            DB::statement('ALTER TABLE accounts DROP CONSTRAINT IF EXISTS check_accounts_normal_balance');
            DB::statement("ALTER TABLE accounts ADD CONSTRAINT check_accounts_classification CHECK (classification IN ('asset', 'liability', 'equity', 'revenue', 'expense'))");
            DB::statement("ALTER TABLE accounts ADD CONSTRAINT check_accounts_normal_balance CHECK (normal_balance IN ('debit', 'credit'))");

            // 8. DB-level Double-Entry Balanced Journal Trigger (PostgreSQL) (P1-23)
            DB::statement(<<<SQL
                CREATE OR REPLACE FUNCTION check_journal_entry_posted_balance()
                RETURNS TRIGGER AS $$
                DECLARE
                    v_debit NUMERIC(15,4);
                    v_credit NUMERIC(15,4);
                    v_count INT;
                BEGIN
                    IF NEW.status = 'posted' THEN
                        SELECT COALESCE(SUM(debit), 0), COALESCE(SUM(credit), 0), COUNT(*)
                        INTO v_debit, v_credit, v_count
                        FROM journal_lines
                        WHERE journal_entry_id = NEW.id;

                        IF v_count < 2 THEN
                            RAISE EXCEPTION 'DB Invariant Violated: Journal entry % cannot be posted with fewer than 2 lines (found %)', NEW.id, v_count;
                        END IF;

                        IF v_debit <> v_credit THEN
                            RAISE EXCEPTION 'DB Invariant Violated: Journal entry % cannot be posted unbalanced: total debit (%) != total credit (%)', NEW.id, v_debit, v_credit;
                        END IF;
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
            SQL);

            DB::statement('DROP TRIGGER IF EXISTS trg_journal_entry_posted_balance ON journal_entries');
            DB::statement(<<<SQL
                CREATE TRIGGER trg_journal_entry_posted_balance
                AFTER INSERT OR UPDATE OF status ON journal_entries
                FOR EACH ROW
                WHEN (NEW.status = 'posted')
                EXECUTE FUNCTION check_journal_entry_posted_balance();
            SQL);
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS trg_journal_entry_posted_balance ON journal_entries');
            DB::statement('DROP FUNCTION IF EXISTS check_journal_entry_posted_balance()');

            DB::statement('ALTER TABLE accounts DROP CONSTRAINT IF EXISTS check_accounts_normal_balance');
            DB::statement('ALTER TABLE accounts DROP CONSTRAINT IF EXISTS check_accounts_classification');

            DB::statement('ALTER TABLE bank_transactions DROP CONSTRAINT IF EXISTS check_bank_transactions_status');
            DB::statement('ALTER TABLE bank_transactions DROP CONSTRAINT IF EXISTS check_bank_transactions_type');
            DB::statement('ALTER TABLE bank_transactions DROP CONSTRAINT IF EXISTS check_bank_transactions_amount');

            DB::statement('ALTER TABLE purchase_bill_lines DROP CONSTRAINT IF EXISTS check_purchase_bill_lines_values');
            DB::statement('ALTER TABLE purchase_bills DROP CONSTRAINT IF EXISTS check_purchase_bills_amounts');
            DB::statement('ALTER TABLE purchase_bills DROP CONSTRAINT IF EXISTS check_purchase_bills_status');

            DB::statement('ALTER TABLE sales_invoice_lines DROP CONSTRAINT IF EXISTS check_sales_invoice_lines_values');
            DB::statement('ALTER TABLE sales_invoices DROP CONSTRAINT IF EXISTS check_sales_invoices_amounts');
            DB::statement('ALTER TABLE sales_invoices DROP CONSTRAINT IF EXISTS check_sales_invoices_status');

            DB::statement('ALTER TABLE journal_entries DROP CONSTRAINT IF EXISTS check_journal_entries_total_amount');
            DB::statement('ALTER TABLE journal_entries DROP CONSTRAINT IF EXISTS check_journal_entries_status');
        }
    }
};
