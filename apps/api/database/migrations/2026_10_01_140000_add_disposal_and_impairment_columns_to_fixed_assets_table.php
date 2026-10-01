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
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->date('disposal_date')->nullable()->after('last_depreciated_date');
            $table->decimal('disposal_proceeds', 15, 4)->nullable()->after('disposal_date');
            $table->decimal('gain_loss_amount', 15, 4)->nullable()->after('disposal_proceeds');
            $table->date('impairment_date')->nullable()->after('gain_loss_amount');
            $table->decimal('impairment_loss', 15, 4)->nullable()->after('impairment_date');
            $table->string('impairment_reason', 255)->nullable()->after('impairment_loss');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->dropColumn([
                'disposal_date',
                'disposal_proceeds',
                'gain_loss_amount',
                'impairment_date',
                'impairment_loss',
                'impairment_reason',
            ]);
        });
    }
};
