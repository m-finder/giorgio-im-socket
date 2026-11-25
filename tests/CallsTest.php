<?php

namespace GiorgioImSocket\Tests;

use GiorgioImSocket\Services\ImService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CallsTest extends TestCase
{
    public function test_private_calls_preserve_sdp_and_finish_after_answering(): void
    {
        $alice = Account::create(['display_name' => 'Alice']);
        $bob = Account::create(['display_name' => 'Bob']);
        foreach ([[$alice, $bob], [$bob, $alice]] as [$user, $friend]) {
            DB::table('im_friends')->insert(['user_id' => $user->getKey(), 'friend_id' => $friend->getKey(), 'group_id' => app(ImService::class)->category($user->getKey())]);
        }
        $id = (string) Str::uuid();
        $this->actingAs($alice)->postJson('/api/im/calls/signals', ['id' => $bob->getKey(), 'callId' => $id, 'action' => 'offer', 'type' => 'video', 'sdp' => "v=0\r\n"])->assertOk();
        $signal = json_decode(DB::table('im_events')->where('type', 'call.signal')->value('data'), true);
        $this->assertSame(["v=0\r\n", 'video', $alice->getKey()], [$signal['sdp'], $signal['type'], $signal['id']]);
        $this->actingAs($bob)->postJson('/api/im/calls/signals', ['id' => $alice->getKey(), 'callId' => $id, 'action' => 'answer', 'sdp' => 'v=0'])->assertOk();
        $this->postJson('/api/im/calls/signals', ['id' => $alice->getKey(), 'callId' => $id, 'action' => 'keepalive'])->assertOk();
        $this->assertDatabaseHas('im_calls', ['id' => $id, 'state' => 'accepted']);
        $this->postJson('/api/im/calls/signals', ['id' => $alice->getKey(), 'callId' => $id, 'action' => 'end'])->assertOk();
        $this->assertDatabaseHas('im_calls', ['id' => $id, 'state' => 'ended']);
    }

    public function test_group_calls_limit_participants_and_end_for_everyone_when_creator_hangs_up(): void
    {
        $owner = Account::create(['display_name' => 'Owner']);
        $members = [];
        for ($index = 0; $index < 4; $index++) {
            $member = Account::create(['display_name' => 'Member '.$index]);
            $members[] = $member;
            DB::table('im_friends')->insert(['user_id' => $owner->getKey(), 'friend_id' => $member->getKey(), 'group_id' => app(ImService::class)->category($owner->getKey())]);
        }
        $group = $this->actingAs($owner)->postJson('/api/im/groups', ['groupname' => 'Team', 'members' => array_map(fn ($user) => $user->getKey(), $members)])->assertOk()->json('id');
        $signal = ['id' => $group, 'groupId' => $group, 'callId' => (string) Str::uuid(), 'type' => 'voice'];
        $this->postJson('/api/im/calls/signals', $signal + ['action' => 'invite'])->assertOk();
        foreach (array_slice($members, 0, 3) as $member) {
            $this->actingAs($member)->postJson('/api/im/calls/signals', $signal + ['action' => 'join'])->assertOk();
        }
        $this->actingAs($members[3])->postJson('/api/im/calls/signals', $signal + ['action' => 'join'])->assertStatus(409);
        $this->actingAs($members[0])->postJson('/api/im/calls/signals', $signal + ['action' => 'leave'])->assertOk();
        $this->assertDatabaseHas('im_group_calls', ['id' => $signal['callId'], 'state' => 'active']);
        $this->actingAs($owner)->postJson('/api/im/calls/signals', $signal + ['action' => 'end'])->assertOk();
        $this->assertDatabaseHas('im_group_calls', ['id' => $signal['callId'], 'state' => 'ended']);
        $this->assertSame(0, DB::table('im_group_call_members')->where('state', 'joined')->count());
    }

    public function test_calls_require_authentication_and_friendship(): void
    {
        $alice = Account::create(['display_name' => 'Alice']);
        $bob = Account::create(['display_name' => 'Bob']);
        $signal = ['id' => $bob->getKey(), 'callId' => (string) Str::uuid(), 'action' => 'offer', 'sdp' => 'v=0'];
        $this->postJson('/api/im/calls/signals', $signal)->assertUnauthorized();
        $this->actingAs($alice)->postJson('/api/im/calls/signals', $signal)->assertForbidden();
        $this->assertDatabaseCount('im_calls', 0);
    }
}
