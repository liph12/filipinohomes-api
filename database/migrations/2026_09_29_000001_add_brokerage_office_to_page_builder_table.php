<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Agent-editable overrides for two About-section credential tiles that used
// to be fixed: "Brokerage" was a hardcoded "Filipino Homes" string for every
// agent (nothing backed it), and "Filipinohomes Office" fell back to the
// agent's own profile address. Both nullable = keep using those defaults.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_builder', function (Blueprint $table) {
            $table->string('brokerage')->nullable()->after('about_photo');
            $table->string('office')->nullable()->after('brokerage');
        });
    }

    public function down(): void
    {
        Schema::table('page_builder', function (Blueprint $table) {
            $table->dropColumn(['brokerage', 'office']);
        });
    }
};
