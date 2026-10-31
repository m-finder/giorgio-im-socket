<?php

namespace GiorgioImSocket\Tests;

use GiorgioImSocket\Services\ImService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BlockingTest extends TestCase
{
    private function friends(): array
    {
        $alice = Account::create(['display_name' => 'Alice']);
        $bob = Account::create(['display_name' => 'Bob']);
        foreach ([[$alice, $bob], [$bob, $alice]] as [$user, $friend]) {
            DB::table('im_friends')->insert(['user_id' => $user->getKey(), 'friend_id' => $friend->getKey(), 'group_id' => app(ImService::class)->category($user->getKey())]);
        }

        return [$alice, $bob];
    }

    public function test_block_removes_friendship_rejects_requests_and_preserves_private_history(): void
    {
        [$alice, $bob] = $this->friends();
        $mid = (string) Str::uuid();
        $this->actingAs($bob)->postJson('/api/im/messages', ['type' => 'friend', 'id' => $alice->getKey(), 'mid' => $mid, 'content' => 'Keep this'])->assertOk();
        $this->actingAs($alice)->postJson('/api/im/blocks/'.$bob->getKey())->assertOk();
        $this->postJson('/api/im/blocks/'.$bob->getKey())->assertOk();
        $this->assertDatabaseCount('im_blocks', 1);
        $this->assertDatabaseCount('im_friends', 0);
        $this->getJson('/api/im/init')->assertOk()->assertJsonPath('init.blocked.0.id', $bob->getKey())->assertJsonCount(0, 'init.friend.0.list');
        $this->getJson('/api/im/messages?type=friend&id='.$bob->getKey())->assertOk()->assertJsonPath('messages.0.content', 'Keep this');
        $this->postJson('/api/im/messages/read', ['type' => 'friend', 'id' => $bob->getKey(), 'mids' => [$mid]])->assertForbidden();
        $this->actingAs($bob)->postJson('/api/im/friend-requests', ['id' => $alice->getKey()])->assertForbidden();
        $this->postJson('/api/im/messages', ['type' => 'friend', 'id' => $alice->getKey(), 'mid' => (string) Str::uuid(), 'content' => 'Denied'])->assertForbidden();
        $this->postJson('/api/im/conversations/typing', ['type' => 'friend', 'id' => $alice->getKey(), 'typing' => true])->assertForbidden();
        $this->getJson('/api/im/blocks')->assertOk()->assertExactJson([]);
    }

    public function test_block_resolves_pending_requests_and_accept_cannot_bypass_the_block(): void
    {
        [$alice, $bob] = $this->friends();
        DB::table('im_friends')->delete();
        $this->actingAs($bob)->postJson('/api/im/friend-requests', ['id' => $alice->getKey()])->assertOk();
        $notice = DB::table('im_notices')->value('id');
        $this->actingAs($alice)->postJson('/api/im/blocks/'.$bob->getKey())->assertOk();
        $this->assertDatabaseHas('im_notices', ['id' => $notice, 'resolution' => 'reject']);
        $this->postJson('/api/im/notices/'.$notice, ['action' => 'accept', 'groupId' => app(ImService::class)->category($alice->getKey())])->assertForbidden();
        // Even an unresolved legacy request must not restore a blocked friendship.
        DB::table('im_notices')->where('id', $notice)->update(['resolved_at' => null, 'resolution' => null]);
        $this->postJson('/api/im/notices/'.$notice, ['action' => 'accept', 'groupId' => app(ImService::class)->category($alice->getKey())])->assertForbidden();
        $this->assertDatabaseCount('im_friends', 0);
    }

    public function test_unblock_is_owner_scoped_and_does_not_restore_friendship(): void
    {
        [$alice, $bob] = $this->friends();
        $this->actingAs($alice)->postJson('/api/im/blocks/'.$bob->getKey())->assertOk();
        $this->actingAs($bob)->deleteJson('/api/im/blocks/'.$alice->getKey())->assertOk();
        $this->postJson('/api/im/friend-requests', ['id' => $alice->getKey()])->assertForbidden();
        $this->actingAs($alice)->deleteJson('/api/im/blocks/'.$bob->getKey())->assertOk();
        $this->getJson('/api/im/blocks')->assertOk()->assertExactJson([]);
        $this->assertDatabaseCount('im_friends', 0);
        $this->actingAs($bob)->postJson('/api/im/friend-requests', ['id' => $alice->getKey()])->assertOk();
    }

    public function test_deleted_friends_can_read_their_own_history_but_cannot_send(): void
    {
        [$alice, $bob] = $this->friends();
        $this->actingAs($alice)->postJson('/api/im/messages', ['type' => 'friend', 'id' => $bob->getKey(), 'mid' => (string) Str::uuid(), 'content' => 'History'])->assertOk();
        $this->deleteJson('/api/im/friends/'.$bob->getKey())->assertOk();
        $this->getJson('/api/im/messages?type=friend&id='.$bob->getKey())->assertOk()->assertJsonPath('messages.0.content', 'History');
        $this->postJson('/api/im/messages', ['type' => 'friend', 'id' => $bob->getKey(), 'mid' => (string) Str::uuid(), 'content' => 'Denied'])->assertForbidden();
        $stranger = Account::create(['display_name' => 'Stranger']);
        $this->actingAs($stranger)->getJson('/api/im/messages?type=friend&id='.$bob->getKey())->assertForbidden();
    }

    public function test_block_validation_and_durable_events(): void
    {
        [$alice, $bob] = $this->friends();
        $this->actingAs($alice)->postJson('/api/im/blocks/'.$alice->getKey())->assertUnprocessable();
        $this->postJson('/api/im/blocks/99999')->assertNotFound();
        $this->postJson('/api/im/blocks/'.$bob->getKey())->assertOk();
        $this->getJson('/api/im/events?after=0')->assertOk()->assertJsonPath('events.0.type', 'user.blocked')->assertJsonPath('events.0.data.contact.id', $bob->getKey());
        $this->actingAs($bob)->getJson('/api/im/events?after=0')->assertOk()->assertJsonPath('events.0.type', 'friend.removed');
        $this->actingAs($alice)->deleteJson('/api/im/blocks/'.$bob->getKey())->assertOk();
        $this->assertDatabaseHas('im_events', ['user_id' => $alice->getKey(), 'type' => 'user.unblocked']);
    }
}
