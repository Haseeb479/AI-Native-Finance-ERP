<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->string('fbr_invoice_number', 50)->nullable()->index()->after('invoice_number');
            $table->string('fbr_status', 30)->default('pending')->index()->after('fbr_invoice_number');
            $table->timestamp('fbr_fiscalized_at')->nullable()->after('fbr_status');
            $table->text('fbr_qr_code')->nullable()->after('fbr_fiscalized_at');
            $table->jsonb('fbr_response_data')->nullable()->after('fbr_qr_code');
        });

        Schema::table('sales_invoice_lines', function (Blueprint $table) {
            $table->string('pct_code', 20)->nullable()->after('description'); // Pakistan Customs Tariff / HS code
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_invoice_lines', function (Blueprint $table) {
            $table->dropColumn('pct_code');
        });

        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropColumn([
                'fbr_invoice_number',
                'fbr_status',
                'fbr_fiscalized_at',
                'fbr_qr_code',
                'fbr_response_data',
            ]);
        });
    }
};
