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
        Schema::create('im_message_reads', function (Blueprint $table) use ($user) {
            $table->foreignId('message_id')->constrained('im_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained($user->getTable(), $user->getKeyName())->cascadeOnDelete();
            $table->timestamp('read_at')->useCurrent();
            $table->primary(['message_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('im_message_reads');
    }
};
