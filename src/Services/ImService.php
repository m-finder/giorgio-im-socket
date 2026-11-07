<?php

namespace GiorgioImSocket\Services;

use GiorgioSocket\Services\EventStore;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ImService
{
    public function requestFriend(int $senderId, int $recipientId): void
    {
        $this->assertNotBlocked($senderId, $recipientId);
        $notice = DB::table('im_notices')->where(['user_id' => $recipientId, 'sender_id' => $senderId, 'type' => 'friend-request'])->whereNull('resolved_at')->first();
        if (! $notice) {
            $noticeId = DB::table('im_notices')->insertGetId([
                'user_id' => $recipientId, 'sender_id' => $senderId, 'type' => 'friend-request',
                'title' => '好友申请', 'content' => $this->user($senderId)['username'].' 申请添加你为好友',
            ]);
            $notice = DB::table('im_notices')->where('id', $noticeId)->first();
            $this->event([$recipientId], 'notice.updated', $this->notice($notice));
        }
    }

    public function profile(int $id): object
    {
        if ($profile = DB::table('im_profiles')->where('user_id', $id)->first()) {
            return $profile;
        }
        DB::table('im_profiles')->insertOrIgnore(['user_id' => $id]);

        return DB::table('im_profiles')->where('user_id', $id)->first();
    }

    public function user(int $id, bool $self = false): array
    {
        $user = app(Users::class)->query()->findOrFail($id);
        $profile = $this->profile($id);

        return [
            'id' => $id, 'username' => $user->getAttribute(config('im.user_name_column', 'name')), 'avatar' => $profile->avatar,
            'sign' => $profile->sign,
            'status' => $self ? $profile->status : ($profile->status === 'online' && $profile->presence_at && Carbon::parse($profile->presence_at)->gt(now()->subSeconds(100)) ? 'online' : 'offline'),
        ];
    }

    public function category(int $userId): int
    {
        DB::table('im_friend_groups')->insertOrIgnore(['user_id' => $userId, 'groupname' => 'Friends']);

        return (int) DB::table('im_friend_groups')->where('user_id', $userId)->orderBy('id')->value('id');
    }

    public function ownedCategory(int $userId, int $id): void
    {
        abort_unless(DB::table('im_friend_groups')->where(['id' => $id, 'user_id' => $userId])->exists(), 404, 'Friend category not found.');
    }

    /** A block in either direction forbids new requests and private communication. */
    public function assertNotBlocked(int $userId, int $otherId): void
    {
        abort_if(DB::table('im_blocks')->where(fn ($query) => $query
            ->where(['user_id' => $userId, 'blocked_id' => $otherId])
            ->orWhere(['user_id' => $otherId, 'blocked_id' => $userId]))->exists(), 403, '好友申请或私信被拒绝：你与对方存在拉黑关系。');
    }

    public function blockedUsers(int $userId): array
    {
        return DB::table('im_blocks')->where('user_id', $userId)->orderByDesc('created_at')->orderBy('blocked_id')->pluck('blocked_id')->map(fn ($id) => $this->user($id))->all();
    }

    /** Keep past private conversations discoverable after friendship removal. */
    public function recentContacts(int $userId): array
    {
        return DB::table('im_messages')->whereNull('group_id')
            ->where(fn ($query) => $query->where('sender_id', $userId)->orWhere('recipient_id', $userId))
            ->selectRaw('CASE WHEN sender_id = ? THEN recipient_id ELSE sender_id END AS peer_id, MAX(id) AS last_id', [$userId])
            ->groupBy('peer_id')->orderBy('last_id')->get()
            ->map(fn ($row) => array_merge($this->user($row->peer_id), ['type' => 'friend']))->all();
    }

    public function friend(int $userId, int $friendId): void
    {
        $this->assertNotBlocked($userId, $friendId);
        abort_unless(DB::table('im_friends')->where(['user_id' => $userId, 'friend_id' => $friendId])->exists(), 403, 'This user is not a friend.');
    }

    public function group(int $userId, int $id, bool $lock = false): object
    {
        $query = DB::table('im_groups')->where('id', $id);
        $group = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($group, 404, 'Group not found.');
        abort_unless(DB::table('im_group_members')->where(['group_id' => $id, 'user_id' => $userId])->exists(), 403, 'Group membership required.');

        return $group;
    }

    public function manager(int $userId, object $group): void
    {
        abort_unless($group->owner_id === $userId || DB::table('im_group_members')->where(['group_id' => $group->id, 'user_id' => $userId, 'admin' => true])->exists(), 403, 'Group management permission required.');
    }

    public function manageableMember(int $userId, object $group, int $memberId): object
    {
        $this->manager($userId, $group);
        $member = DB::table('im_group_members')->where(['group_id' => $group->id, 'user_id' => $memberId])->first();
        abort_unless($member, 404, 'Member not found.');
        abort_if($memberId === $group->owner_id || $memberId === $userId || ($member->admin && $group->owner_id !== $userId), 403, 'Cannot manage this member.');

        return $member;
    }

    public function groupContact(int $id, ?int $viewer = null): array
    {
        $group = DB::table('im_groups')->where('id', $id)->first();
        abort_unless($group, 404);

        return array_merge([
            'id' => $id, 'type' => 'group', 'groupname' => $group->groupname, 'avatar' => $group->avatar,
            'ownerId' => $group->owner_id, 'muteAll' => (bool) $group->mute_all,
            'adminIds' => DB::table('im_group_members')->where(['group_id' => $id, 'admin' => true])->pluck('user_id')->all(),
            'members' => DB::table('im_group_members')->where('group_id', $id)->count(),
        ], $viewer === null ? [] : [
            'notificationsMuted' => (bool) DB::table('im_group_members')->where(['group_id' => $id, 'user_id' => $viewer])->value('notifications_muted'),
        ]);
    }

    public function members(int $id): array
    {
        return DB::table('im_group_members')->where('group_id', $id)->orderBy('user_id')->get()->map(fn ($member) => array_merge(
            $this->user($member->user_id), ['muted' => (bool) $member->muted]
        ))->all();
    }

    public function notice(object $notice): array
    {
        return array_merge([
            'id' => $notice->id, 'type' => $notice->type, 'title' => $notice->title, 'content' => $notice->content,
            'timestamp' => Carbon::parse($notice->created_at)->getTimestampMs(),
            'read' => $notice->read_at !== null, 'resolved' => $notice->resolved_at !== null,
        ], $notice->sender_id ? ['contact' => $this->user($notice->sender_id)] : []);
    }

    public function event(array $users, string $type, array $data): void
    {
        $users = array_unique($users);
        sort($users);
        foreach ($users as $id) {
            $this->profile($id);
            DB::table('im_profiles')->where('user_id', $id)->lockForUpdate()->first();
        }
        app(EventStore::class)->append($users, $type, $data);
    }

    public function groupEvent(int $id): void
    {
        $this->event(DB::table('im_group_members')->where('group_id', $id)->pluck('user_id')->all(), 'group.updated', [
            'contact' => $this->groupContact($id),
        ]);
    }

    public function audience(int $userId): array
    {
        return DB::table('im_friends')->where('friend_id', $userId)->pluck('user_id')->all();
    }

    public function messages(int $userId, string $type, int $id, bool $history = false): Builder
    {
        if ($type === 'group') {
            $this->group($userId, $id);
            $joined = DB::table('im_group_members')->where(['group_id' => $id, 'user_id' => $userId])->value('joined_at');

            return DB::table('im_messages')->where('group_id', $id)->where('created_at', '>=', $joined);
        }
        $query = DB::table('im_messages')->whereNull('group_id')->where(fn ($query) => $query
            ->where(fn ($query) => $query->where('sender_id', $userId)->where('recipient_id', $id))
            ->orWhere(fn ($query) => $query->where('sender_id', $id)->where('recipient_id', $userId)));
        // Only history is readable after deletion. Sending, receipts and typing still require friendship.
        if (! $history || ! $query->exists()) {
            $this->friend($userId, $id);
        }

        return $query;
    }

    public function message(object $message, int $viewer): array
    {
        $sender = $this->user($message->sender_id);
        $attachment = $message->attachment ? json_decode($message->attachment, true, 512, JSON_THROW_ON_ERROR) : null;
        $reference = ! empty($message->reply_to) ? json_decode($message->reply_to, true, 512, JSON_THROW_ON_ERROR) : null;
        $id = $message->group_id ?? ($message->sender_id === $viewer ? $message->recipient_id : $message->sender_id);
        if ($reference && ! isset($reference['attachment']) && ! isset($reference['attachments'])) {
            $original = $this->messages($viewer, $message->group_id ? 'group' : 'friend', $id, true)
                ->where(['client_mid' => $reference['mid'], 'sender_id' => $reference['fromid']])->first();
            if ($original) {
                $reference += $this->messageReference($original);
            }
        }

        return array_merge([
            'id' => $id,
            'type' => $message->group_id ? 'group' : 'friend', 'mid' => $message->client_mid,
            'fromid' => $message->sender_id, 'mine' => $message->sender_id === $viewer,
            'username' => $sender['username'], 'avatar' => $sender['avatar'], 'content' => $message->content,
            'timestamp' => Carbon::parse($message->created_at)->getTimestampMs(),
            'readBy' => DB::table('im_message_reads')->where('message_id', $message->id)->pluck('user_id')->all(),
        ], $attachment ? (isset($attachment[0]) ? ['attachments' => $attachment] : ['attachment' => $attachment]) : [],
            ! empty($message->mentions) ? ['mentions' => json_decode($message->mentions, true, 512, JSON_THROW_ON_ERROR)] : [],
            $reference ? ['replyTo' => $reference] : []);
    }

    /** Snapshot an authorised original without recursively copying its own quote. */
    public function messageReference(object $message): array
    {
        $attachment = $message->attachment ? json_decode($message->attachment, true, 512, JSON_THROW_ON_ERROR) : null;

        return array_merge(['mid' => $message->client_mid, 'fromid' => $message->sender_id,
            'username' => $this->user($message->sender_id)['username'], 'content' => $message->content],
            $attachment ? (isset($attachment[0]) ? ['attachments' => $attachment] : ['attachment' => $attachment]) : []);
    }
}
