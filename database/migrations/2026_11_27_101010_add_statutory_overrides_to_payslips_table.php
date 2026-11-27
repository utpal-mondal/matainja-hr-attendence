<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee PF and ESI amounts set by an admin on review, used instead of
 * the calculated figures (null = use the calculation).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->decimal('employee_pf_override', 12, 2)->nullable()->after('employee_pf');
            $table->decimal('employee_esi_override', 12, 2)->nullable()->after('employee_esi');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn(['employee_pf_override', 'employee_esi_override']);
        });
    }
};
