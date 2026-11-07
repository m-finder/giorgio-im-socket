<?php

namespace GiorgioImSocket\Tests;

use GiorgioImSocket\Services\ImService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MessageContextTest extends TestCase
{
    private Account $alice;

    private Account $bob;

    private int $group;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alice = Account::create(['display_name' => 'Alice']);
        $this->bob = Account::create(['display_name' => 'Bob']);
        foreach ([[$this->alice, $this->bob], [$this->bob, $this->alice]] as [$user, $friend]) {
            DB::table('im_friends')->insert(['user_id' => $user->getKey(), 'friend_id' => $friend->getKey(), 'group_id' => app(ImService::class)->category($user->getKey())]);
        }
        $this->group = $this->actingAs($this->alice)->postJson('/api/im/groups', ['groupname' => 'Team', 'members' => [$this->bob->getKey()]])->assertOk()->json('id');
    }

    public function test_group_mentions_and_quotes_survive_delivery_history_and_retries(): void
    {
        $original = ['type' => 'group', 'id' => $this->group, 'mid' => (string) Str::uuid(), 'content' => 'Original'];
        $this->postJson('/api/im/messages', $original)->assertOk();
        $reply = ['type' => 'group', 'id' => $this->group, 'mid' => (string) Str::uuid(), 'content' => '😀 @Bob hello',
            'mentions' => [['id' => $this->bob->getKey(), 'username' => 'Bob', 'start' => 3, 'end' => 7]],
            'replyTo' => ['mid' => $original['mid'], 'fromid' => $this->alice->getKey()],
        ];
        $this->postJson('/api/im/messages', $reply)->assertOk()->assertJsonPath('mentions', $reply['mentions'])
            ->assertJsonPath('replyTo.content', 'Original')->assertJsonPath('replyTo.username', 'Alice');
        $this->postJson('/api/im/messages', $reply)->assertOk();
        $this->postJson('/api/im/messages', array_merge($reply, ['mentions' => []]))->assertStatus(409);
        $this->actingAs($this->bob)->getJson('/api/im/messages?type=group&id='.$this->group)->assertOk()
            ->assertJsonPath('messages.1.mentions', $reply['mentions'])->assertJsonPath('messages.1.replyTo.content', 'Original');
        $event = DB::table('im_events')->where(['user_id' => $this->bob->getKey(), 'type' => 'message'])->orderByDesc('id')->value('data');
        $this->assertSame($reply['mentions'], json_decode($event, true)['mentions']);
        $this->assertDatabaseCount('im_messages', 2);
    }

    public function test_private_replies_use_the_original_sender_and_reject_other_conversations(): void
    {
        $mid = (string) Str::uuid();
        $this->actingAs($this->bob)->postJson('/api/im/messages', ['type' => 'friend', 'id' => $this->alice->getKey(), 'mid' => $mid, 'content' => 'Private original'])->assertOk();
        $reply = ['type' => 'friend', 'id' => $this->bob->getKey(), 'mid' => (string) Str::uuid(), 'content' => 'Reply',
            'replyTo' => ['mid' => $mid, 'fromid' => $this->bob->getKey()],
        ];
        $this->actingAs($this->alice)->postJson('/api/im/messages', $reply)->assertOk()
            ->assertJsonPath('replyTo.username', 'Bob')->assertJsonPath('replyTo.content', 'Private original');
        $this->postJson('/api/im/messages', array_merge($reply, ['mid' => (string) Str::uuid(), 'type' => 'group', 'id' => $this->group]))->assertUnprocessable();
        $this->postJson('/api/im/messages', array_merge($reply, ['mid' => (string) Str::uuid(), 'replyTo' => ['mid' => $mid, 'fromid' => $this->alice->getKey()]]))->assertUnprocessable();
        $this->actingAs($this->bob)->getJson('/api/im/messages?type=friend&id='.$this->alice->getKey())->assertOk()->assertJsonPath('messages.1.replyTo.content', 'Private original');
    }

    public function test_quote_retries_ignore_reference_key_order(): void
    {
        $mid = (string) Str::uuid();
        $this->postJson('/api/im/messages', ['type' => 'friend', 'id' => $this->bob->getKey(), 'mid' => $mid, 'content' => 'Original'])->assertOk();
        $reply = ['type' => 'friend', 'id' => $this->bob->getKey(), 'mid' => (string) Str::uuid(), 'content' => 'Reply',
            'replyTo' => ['fromid' => $this->alice->getKey(), 'mid' => $mid],
        ];
        $this->postJson('/api/im/messages', $reply)->assertOk();
        $this->postJson('/api/im/messages', $reply)->assertOk()->assertJsonPath('replyTo.mid', $mid);
        $reply['replyTo'] = ['mid' => $mid, 'fromid' => (string) $this->alice->getKey()];
        $this->postJson('/api/im/messages', $reply)->assertOk();
        $this->assertDatabaseCount('im_messages', 2);
    }

    public function test_mentions_reject_non_members_invalid_offsets_and_private_chats(): void
    {
        $outsider = Account::create(['display_name' => 'Other']);
        $message = ['type' => 'group', 'id' => $this->group, 'mid' => (string) Str::uuid(), 'content' => '@Bob hello',
            'mentions' => [['id' => $this->bob->getKey(), 'username' => 'Bob', 'start' => 0, 'end' => 4]],
        ];
        $this->postJson('/api/im/messages', array_replace_recursive($message, ['mentions' => [['id' => $outsider->getKey()]]]))->assertUnprocessable();
        $this->postJson('/api/im/messages', array_replace_recursive($message, ['mentions' => [['start' => 1]]]))->assertUnprocessable();
        $this->postJson('/api/im/messages', array_merge($message, ['type' => 'friend', 'id' => $this->bob->getKey()]))->assertUnprocessable();
        $this->postJson('/api/im/messages', array_merge($message, ['mentions' => [$message['mentions'][0], $message['mentions'][0]]]))->assertUnprocessable();
        $this->assertDatabaseCount('im_messages', 0);
    }

    public function test_quotes_cannot_expose_group_messages_from_before_joining(): void
    {
        $mid = (string) Str::uuid();
        $this->postJson('/api/im/messages', ['type' => 'group', 'id' => $this->group, 'mid' => $mid, 'content' => 'Old'])->assertOk();
        DB::table('im_group_members')->where(['group_id' => $this->group, 'user_id' => $this->bob->getKey()])->update(['joined_at' => now()->addMinute()]);
        $this->actingAs($this->bob)->postJson('/api/im/messages', ['type' => 'group', 'id' => $this->group, 'mid' => (string) Str::uuid(), 'content' => 'Reply',
            'replyTo' => ['mid' => $mid, 'fromid' => $this->alice->getKey()],
        ])->assertUnprocessable();
    }

    public function test_mentions_cannot_extend_past_the_utf16_content_length(): void
    {
        $this->postJson('/api/im/messages', ['type' => 'group', 'id' => $this->group, 'mid' => (string) Str::uuid(), 'content' => '😀 @Bob',
            'mentions' => [['id' => $this->bob->getKey(), 'username' => 'Bob', 'start' => 3, 'end' => 8]],
        ])->assertUnprocessable();
    }

    public function test_quote_snapshots_cannot_be_supplied_by_the_client(): void
    {
        $this->postJson('/api/im/messages', ['type' => 'friend', 'id' => $this->bob->getKey(), 'mid' => (string) Str::uuid(), 'content' => 'Reply',
            'replyTo' => ['mid' => (string) Str::uuid(), 'fromid' => $this->bob->getKey(), 'username' => 'Forged name', 'content' => 'Forged quote'],
        ])->assertUnprocessable();
    }

    public function test_context_migration_preserves_messages_from_existing_installations(): void
    {
        $migration = require __DIR__.'/../database/migrations/2026_11_06_000005_add_im_message_context.php';
        $migration->down();
        $mid = (string) Str::uuid();
        DB::table('im_messages')->insert(['sender_id' => $this->alice->getKey(), 'recipient_id' => $this->bob->getKey(),
            'client_mid' => $mid, 'content' => 'Existing message', 'created_at' => now(),
        ]);
        $migration->up();
        $this->getJson('/api/im/messages?type=friend&id='.$this->bob->getKey())->assertOk()
            ->assertJsonPath('messages.0.mid', $mid)->assertJsonPath('messages.0.content', 'Existing message');
        $this->postJson('/api/im/messages', ['type' => 'friend', 'id' => $this->bob->getKey(), 'mid' => (string) Str::uuid(), 'content' => 'New reply',
            'replyTo' => ['mid' => $mid, 'fromid' => $this->alice->getKey()],
        ])->assertOk()->assertJsonPath('replyTo.content', 'Existing message');
    }

    public function test_single_attachments_and_multiple_images_keep_their_quote_snapshots(): void
    {
        $mid = (string) Str::uuid();
        $this->postJson('/api/im/messages', ['type' => 'group', 'id' => $this->group, 'mid' => $mid, 'content' => 'Original'])->assertOk();
        $images = [
            ['kind' => 'image', 'url' => 'https://example.test/one.png', 'name' => 'one.png'],
            ['kind' => 'image', 'url' => 'https://example.test/two.png', 'name' => 'two.png'],
        ];
        foreach ($images as $image) {
            DB::table('im_uploads')->insert(array_merge($image, ['user_id' => $this->alice->getKey(), 'path' => $image['name'], 'mime' => 'image/png']));
        }
        foreach (['attachment' => $images[0], 'attachments' => $images] as $field => $attachment) {
            $this->postJson('/api/im/messages', ['type' => 'group', 'id' => $this->group, 'mid' => (string) Str::uuid(), 'content' => 'Photos',
                $field => $attachment, 'replyTo' => ['mid' => $mid, 'fromid' => $this->alice->getKey()],
            ])->assertOk()->assertJsonPath($field, $attachment)->assertJsonPath('replyTo.content', 'Original');
        }
        $this->actingAs($this->bob)->getJson('/api/im/messages?type=group&id='.$this->group)->assertOk()
            ->assertJsonPath('messages.1.attachment', $images[0])->assertJsonPath('messages.2.attachments', $images)
            ->assertJsonPath('messages.1.replyTo.content', 'Original')->assertJsonPath('messages.2.replyTo.content', 'Original');
    }

    public function test_private_image_quotes_keep_the_original_attachment_on_delivery_history_and_retry(): void
    {
        $image = ['kind' => 'image', 'url' => 'https://example.test/photo.png', 'name' => 'photo.png'];
        DB::table('im_uploads')->insert(array_merge($image, ['user_id' => $this->alice->getKey(), 'path' => $image['name'], 'mime' => 'image/png']));
        foreach ([$image['url'], $image['name']] as $content) {
            $mid = (string) Str::uuid();
            $this->postJson('/api/im/messages', ['type' => 'friend', 'id' => $this->bob->getKey(), 'mid' => $mid, 'content' => $content, 'attachment' => $image])->assertOk();
            $reply = ['type' => 'friend', 'id' => $this->bob->getKey(), 'mid' => (string) Str::uuid(), 'content' => 'Reply',
                'replyTo' => ['mid' => $mid, 'fromid' => $this->alice->getKey()],
            ];
            $this->postJson('/api/im/messages', $reply)->assertOk()->assertJsonPath('replyTo.attachment', $image)->assertJsonPath('replyTo.content', $content);
            $this->postJson('/api/im/messages', $reply)->assertOk()->assertJsonPath('replyTo.attachment', $image);
            $event = DB::table('im_events')->where(['user_id' => $this->bob->getKey(), 'type' => 'message'])->orderByDesc('id')->value('data');
            $this->assertSame($image, json_decode($event, true)['replyTo']['attachment']);
        }
        $this->actingAs($this->bob)->getJson('/api/im/messages?type=friend&id='.$this->alice->getKey())->assertOk()
            ->assertJsonPath('messages.1.replyTo.attachment', $image)->assertJsonPath('messages.3.replyTo.attachment', $image);
    }

    public function test_group_image_and_text_quotes_keep_the_original_image_collection(): void
    {
        $images = [
            ['kind' => 'image', 'url' => 'https://example.test/one.png', 'name' => 'one.png'],
            ['kind' => 'image', 'url' => 'https://example.test/two.png', 'name' => 'two.png'],
        ];
        foreach ($images as $image) {
            DB::table('im_uploads')->insert(array_merge($image, ['user_id' => $this->alice->getKey(), 'path' => $image['name'], 'mime' => 'image/png']));
        }
        $mid = (string) Str::uuid();
        $this->postJson('/api/im/messages', ['type' => 'group', 'id' => $this->group, 'mid' => $mid, 'content' => 'Two photos', 'attachments' => $images])->assertOk();
        $reply = ['type' => 'group', 'id' => $this->group, 'mid' => (string) Str::uuid(), 'content' => 'Reply',
            'replyTo' => ['mid' => $mid, 'fromid' => $this->alice->getKey()],
        ];
        $this->postJson('/api/im/messages', $reply)->assertOk()->assertJsonPath('replyTo.attachments', $images)->assertJsonPath('replyTo.content', 'Two photos');
        $this->postJson('/api/im/messages', $reply)->assertOk()->assertJsonPath('replyTo.attachments', $images);
        $this->actingAs($this->bob)->getJson('/api/im/messages?type=group&id='.$this->group)->assertOk()->assertJsonPath('messages.1.replyTo.attachments', $images);
        $event = DB::table('im_events')->where(['user_id' => $this->bob->getKey(), 'type' => 'message'])->orderByDesc('id')->value('data');
        $this->assertSame($images, json_decode($event, true)['replyTo']['attachments']);
    }

    public function test_clients_cannot_supply_quoted_attachments(): void
    {
        foreach (['attachment', 'attachments'] as $field) {
            $this->postJson('/api/im/messages', ['type' => 'friend', 'id' => $this->bob->getKey(), 'mid' => (string) Str::uuid(), 'content' => 'Reply',
                'replyTo' => ['mid' => (string) Str::uuid(), 'fromid' => $this->bob->getKey(), $field => []],
            ])->assertUnprocessable();
        }
    }

    public function test_legacy_text_only_quotes_recover_visible_original_attachments_without_rewriting_storage(): void
    {
        $image = ['kind' => 'image', 'url' => 'https://example.test/old.png', 'name' => 'old.png'];
        DB::table('im_uploads')->insert(array_merge($image, ['user_id' => $this->alice->getKey(), 'path' => $image['name'], 'mime' => 'image/png']));
        $mid = (string) Str::uuid();
        $this->postJson('/api/im/messages', ['type' => 'friend', 'id' => $this->bob->getKey(), 'mid' => $mid, 'content' => $image['name'], 'attachment' => $image])->assertOk();
        $reply = ['type' => 'friend', 'id' => $this->bob->getKey(), 'mid' => (string) Str::uuid(), 'content' => 'Reply',
            'replyTo' => ['mid' => $mid, 'fromid' => $this->alice->getKey()],
        ];
        $this->postJson('/api/im/messages', $reply)->assertOk();
        $legacy = json_encode(['mid' => $mid, 'fromid' => $this->alice->getKey(), 'username' => 'Old username', 'content' => $image['name']]);
        DB::table('im_messages')->where('client_mid', $reply['mid'])->update(['reply_to' => $legacy]);
        $this->postJson('/api/im/messages', $reply)->assertOk()->assertJsonPath('replyTo.attachment', $image)->assertJsonPath('replyTo.username', 'Old username');
        $this->actingAs($this->bob)->getJson('/api/im/messages?type=friend&id='.$this->alice->getKey())->assertOk()->assertJsonPath('messages.1.replyTo.attachment', $image);
        $this->assertSame($legacy, DB::table('im_messages')->where('client_mid', $reply['mid'])->value('reply_to'));
    }

    public function test_legacy_quotes_do_not_recover_original_images_from_before_the_viewer_joined(): void
    {
        $mid = (string) Str::uuid();
        $original = DB::table('im_messages')->insertGetId(['group_id' => $this->group, 'sender_id' => $this->alice->getKey(), 'client_mid' => $mid,
            'content' => 'Old image', 'attachment' => json_encode(['kind' => 'image', 'url' => 'https://example.test/hidden.png', 'name' => 'hidden.png']), 'created_at' => now()->subMinute(),
        ]);
        $reference = ['mid' => $mid, 'fromid' => $this->alice->getKey(), 'username' => 'Alice', 'content' => 'Old image'];
        DB::table('im_messages')->insert(['group_id' => $this->group, 'sender_id' => $this->alice->getKey(), 'client_mid' => (string) Str::uuid(),
            'content' => 'Reply', 'reply_to' => json_encode($reference), 'created_at' => now()->addSecond(),
        ]);
        $this->actingAs($this->bob)->getJson('/api/im/messages?type=group&id='.$this->group)->assertOk()->assertJsonPath('messages.0.replyTo', $reference);
        $this->assertDatabaseHas('im_messages', ['id' => $original]);
    }
}
