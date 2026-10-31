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
        Schema::create('im_blocks', function (Blueprint $table) use ($user) {
            $table->foreignId('user_id')->constrained($user->getTable(), $user->getKeyName())->cascadeOnDelete();
            $table->foreignId('blocked_id')->constrained($user->getTable(), $user->getKeyName())->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['user_id', 'blocked_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('im_blocks');
    }
};
