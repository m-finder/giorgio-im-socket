<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('im_group_members', function (Blueprint $table) {
            $table->boolean('notifications_muted')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('im_group_members', function (Blueprint $table) {
            $table->dropColumn('notifications_muted');
        });
    }
};
