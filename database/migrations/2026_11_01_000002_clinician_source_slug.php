<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clinicians', function (Blueprint $t) {
            $t->string('source_slug', 120)->nullable()->unique();   // the doctor's slug on drfeyselclinic.com
        });
    }

    public function down(): void
    {
        Schema::table('clinicians', function (Blueprint $t) {
            $t->dropUnique(['source_slug']);
            $t->dropColumn('source_slug');
        });
    }
};
