<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('im_messages', function (Blueprint $table) {
            $table->json('mentions')->nullable();
            $table->json('reply_to')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('im_messages', function (Blueprint $table) {
            $table->dropColumn(['mentions', 'reply_to']);
        });
    }
};
