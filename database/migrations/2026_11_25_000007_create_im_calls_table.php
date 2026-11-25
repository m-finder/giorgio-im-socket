<?php

use GiorgioImSocket\Services\Users;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $user = app(Users::class)->query()->getModel();
        Schema::create('im_calls', function (Blueprint $table) use ($user) {
            $table->uuid('id')->primary();
            $table->foreignId('caller_id')->constrained($user->getTable(), $user->getKeyName())->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained($user->getTable(), $user->getKeyName())->cascadeOnDelete();
            $table->string('state', 16);
            $table->timestamp('expires_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('im_calls');
    }
};
