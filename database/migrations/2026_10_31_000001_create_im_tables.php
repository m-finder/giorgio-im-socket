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
        Schema::create('im_profiles', function (Blueprint $table) use ($user) {
            $table->foreignId('user_id')->primary()->constrained($user->getTable(), $user->getKeyName())->cascadeOnDelete();
            $table->string('avatar', 2048)->nullable();
            $table->string('sign', 255)->default('');
            $table->string('status', 10)->default('online');
            $table->timestamp('presence_at')->nullable();
        });
        Schema::create('im_friend_groups', function (Blueprint $table) use ($user) {
            $table->id();
            $table->foreignId('user_id')->constrained($user->getTable(), $user->getKeyName())->cascadeOnDelete();
            $table->string('groupname', 80);
            $table->unique(['user_id', 'groupname']);
        });
        Schema::create('im_friends', function (Blueprint $table) use ($user) {
            $table->foreignId('user_id')->constrained($user->getTable(), $user->getKeyName())->cascadeOnDelete();
            $table->foreignId('friend_id')->constrained($user->getTable(), $user->getKeyName())->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('im_friend_groups')->cascadeOnDelete();
            $table->primary(['user_id', 'friend_id']);
        });
        Schema::create('im_groups', function (Blueprint $table) use ($user) {
            $table->id();
            $table->foreignId('owner_id')->constrained($user->getTable(), $user->getKeyName());
            $table->string('groupname', 80);
            $table->string('avatar', 2048)->nullable();
            $table->boolean('mute_all')->default(false);
            $table->timestamps();
        });
        Schema::create('im_group_members', function (Blueprint $table) use ($user) {
            $table->foreignId('group_id')->constrained('im_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained($user->getTable(), $user->getKeyName())->cascadeOnDelete();
            $table->boolean('admin')->default(false);
            $table->boolean('muted')->default(false);
            $table->timestamp('joined_at');
            $table->primary(['group_id', 'user_id']);
        });
        Schema::create('im_uploads', function (Blueprint $table) use ($user) {
            $table->id();
            $table->foreignId('user_id')->constrained($user->getTable(), $user->getKeyName())->cascadeOnDelete();
            $table->string('url', 2048);
            $table->string('kind', 10);
            $table->string('name');
            $table->string('path');
            $table->string('mime');
        });
        Schema::create('im_messages', function (Blueprint $table) use ($user) {
            $table->id();
            $table->foreignId('sender_id')->constrained($user->getTable(), $user->getKeyName());
            $table->foreignId('recipient_id')->nullable()->constrained($user->getTable(), $user->getKeyName());
            $table->foreignId('group_id')->nullable()->constrained('im_groups')->cascadeOnDelete();
            $table->uuid('client_mid');
            $table->text('content');
            $table->json('attachment')->nullable();
            $table->timestamp('created_at', 3)->useCurrent();
            $table->unique(['sender_id', 'client_mid']);
            $table->index(['recipient_id', 'sender_id', 'id']);
            $table->index(['group_id', 'id']);
        });
        Schema::create('im_notices', function (Blueprint $table) use ($user) {
            $table->id();
            $table->foreignId('user_id')->constrained($user->getTable(), $user->getKeyName())->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained($user->getTable(), $user->getKeyName())->nullOnDelete();
            $table->string('type', 30);
            $table->string('title');
            $table->text('content');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution', 10)->nullable();
            $table->index(['user_id', 'id']);
        });
        Schema::create('im_events', function (Blueprint $table) use ($user) {
            $table->id();
            $table->foreignId('user_id')->constrained($user->getTable(), $user->getKeyName())->cascadeOnDelete();
            $table->string('type', 40);
            $table->json('data');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('published_at')->nullable()->index();
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        foreach (['im_events', 'im_notices', 'im_messages', 'im_uploads', 'im_group_members', 'im_groups', 'im_friends', 'im_friend_groups', 'im_profiles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
