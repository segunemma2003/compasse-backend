<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lets a school's row carry its own PayHub company: provisioned lazily,
     * once, the first time anyone tries to pay a fee online for that school
     * (see PayHubService::ensureCompanyForSchool()). The API key is stored
     * encrypted — it authenticates as this school against PayHub, so it's
     * as sensitive as a real payment-gateway secret key.
     */
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->string('payhub_company_id')->nullable()->after('settings');
            $table->text('payhub_api_key')->nullable()->after('payhub_company_id');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn(['payhub_company_id', 'payhub_api_key']);
        });
    }
};
