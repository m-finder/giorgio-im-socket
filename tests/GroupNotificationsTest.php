<?php

namespace GiorgioImSocket\Tests;

use GiorgioImSocket\Services\ImService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GroupNotificationsTest extends TestCase
{
    private Account $owner;

    private Account $member;

    private int $group;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = Account::create(['display_name' => 'Owner']);
        $this->member = Account::create(['display_name' => 'Member']);
        DB::table('im_friends')->insert(['user_id' => $this->owner->getKey(), 'friend_id' => $this->member->getKey(), 'group_id' => app(ImService::class)->category($this->owner->getKey())]);
        $this->group = $this->actingAs($this->owner)->postJson('/api/im/groups', ['groupname' => 'Team', 'members' => [$this->member->getKey()]])->assertOk()->json('id');
    }

    public function test_members_can_persist_their_own_notification_preference_without_muting_the_group(): void
    {
        $this->actingAs($this->member)->patchJson('/api/im/groups/'.$this->group.'/notifications', ['muted' => true])->assertOk()->assertJsonPath('notificationsMuted', true);
        $this->getJson('/api/im/init')->assertOk()->assertJsonPath('init.group.0.notificationsMuted', true)->assertJsonPath('init.group.0.muteAll', false);
        $this->actingAs($this->owner)->getJson('/api/im/init')->assertOk()->assertJsonPath('init.group.0.notificationsMuted', false);
        $this->actingAs($this->member)->postJson('/api/im/messages', ['type' => 'group', 'id' => $this->group, 'mid' => (string) Str::uuid(), 'content' => 'Still able to send'])->assertOk();
        $this->patchJson('/api/im/groups/'.$this->group.'/notifications', ['muted' => false])->assertOk()->assertJsonPath('notificationsMuted', false);
        $this->getJson('/api/im/init')->assertOk()->assertJsonPath('init.group.0.notificationsMuted', false);
    }

    public function test_notification_changes_only_sync_to_the_current_user(): void
    {
        DB::table('im_events')->delete();
        $this->actingAs($this->member)->patchJson('/api/im/groups/'.$this->group.'/notifications', ['muted' => true])->assertOk();
        $events = DB::table('im_events')->get();
        $this->assertCount(1, $events);
        $this->assertSame($this->member->getKey(), $events[0]->user_id);
        $this->assertSame('group.updated', $events[0]->type);
        $this->assertTrue(json_decode($events[0]->data, true)['contact']['notificationsMuted']);
        $this->actingAs($this->owner)->patchJson('/api/im/groups/'.$this->group, ['groupname' => 'Renamed'])->assertOk()->assertJsonPath('notificationsMuted', false);
        $broadcast = DB::table('im_events')->where('user_id', $this->member->getKey())->orderByDesc('id')->first();
        $this->assertArrayNotHasKey('notificationsMuted', json_decode($broadcast->data, true)['contact']);
        $this->actingAs($this->member)->getJson('/api/im/init')->assertOk()->assertJsonPath('init.group.0.notificationsMuted', true);
    }

    public function test_nonmembers_cannot_change_notifications_and_invalid_values_are_rejected(): void
    {
        $outsider = Account::create(['display_name' => 'Other']);
        $this->actingAs($outsider)->patchJson('/api/im/groups/'.$this->group.'/notifications', ['muted' => true])->assertForbidden();
        $this->actingAs($this->member)->patchJson('/api/im/groups/'.$this->group.'/notifications', ['muted' => 'invalid'])->assertUnprocessable();
        $this->patchJson('/api/im/groups/'.$this->group.'/notifications', [])->assertUnprocessable();
        $this->getJson('/api/im/init')->assertOk()->assertJsonPath('init.group.0.notificationsMuted', false);
    }

    public function test_notification_migration_preserves_existing_memberships(): void
    {
        $migration = require __DIR__.'/../database/migrations/2026_11_07_000006_add_im_group_notifications.php';
        $migration->down();
        $migration->up();
        $this->actingAs($this->member)->getJson('/api/im/init')->assertOk()->assertJsonPath('init.group.0.id', $this->group)->assertJsonPath('init.group.0.notificationsMuted', false);
    }
}
