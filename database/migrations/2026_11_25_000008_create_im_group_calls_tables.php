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
        Schema::create('im_group_calls', function (Blueprint $table) use ($user) {
            $table->uuid('id')->primary();
            $table->foreignId('group_id')->constrained('im_groups')->cascadeOnDelete();
            $table->foreignId('creator_id')->constrained($user->getTable(), $user->getKeyName())->cascadeOnDelete();
            $table->string('type', 8);
            $table->string('state', 16);
            $table->timestamp('expires_at')->index();
        });
        Schema::create('im_group_call_members', function (Blueprint $table) use ($user) {
            $table->foreignUuid('call_id')->constrained('im_group_calls')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained($user->getTable(), $user->getKeyName())->cascadeOnDelete();
            $table->string('state', 8);
            $table->timestamp('expires_at')->index();
            $table->primary(['call_id', 'user_id']);
            $table->index(['user_id', 'state', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('im_group_call_members');
        Schema::dropIfExists('im_group_calls');
    }
};
