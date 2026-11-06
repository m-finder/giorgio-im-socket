<?php

namespace GiorgioImSocket\Http\Controllers;

use GiorgioImSocket\Contracts\SocketIdentity;
use GiorgioImSocket\Services\ImService;
use GiorgioImSocket\Services\SensitiveWords;
use GiorgioImSocket\Services\Users;
use GiorgioSocket\Services\ConnectionTicket;
use GiorgioSocket\Services\EventStore;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ImController extends Controller
{
    public function __construct(private ImService $im)
    {
    }

    public function init(Request $request): array
    {
        return DB::transaction(function () use ($request) {
            $id = (int) $request->user()->getAuthIdentifier();
            $this->im->profile($id);
            DB::table('im_profiles')->where('user_id', $id)->lockForUpdate()->first();
            $cursor = app(EventStore::class)->latest($id);
            $this->im->category($id);

            return [
                'init' => [
                    'user' => $this->im->user($id, true),
                    'blocked' => $this->im->blockedUsers($id),
                    'recent' => $this->im->recentContacts($id),
                    'friend' => DB::table('im_friend_groups')->where('user_id', $id)->orderBy('id')->get()->map(fn ($group) => [
                        'id' => $group->id, 'groupname' => $group->groupname,
                        'list' => DB::table('im_friends')->where(['user_id' => $id, 'group_id' => $group->id])->pluck('friend_id')->map(fn ($friend) => $this->im->user($friend))->all(),
                    ])->all(),
                    'group' => DB::table('im_group_members')->where('user_id', $id)->pluck('group_id')->map(fn ($group) => $this->im->groupContact($group))->all(),
                ],
                'notices' => DB::table('im_notices')->where('user_id', $id)->orderByDesc('id')->limit(100)->get()->map(fn ($notice) => $this->im->notice($notice))->all(),
                'cursor' => $cursor, 'socketUrl' => config('im.socket_url'),
            ];
        }, 3);
    }

    public function ticket(Request $request): array
    {
        $claims = array_merge(app(SocketIdentity::class)->claims($request), ['user_id' => (int) $request->user()->getAuthIdentifier()]);
        abort_unless(app(SocketIdentity::class)->resolve($claims) === $claims['user_id'], 403, 'Socket identity is not authorised.');

        return ['token' => app(ConnectionTicket::class)->issue($claims, config('im.socket_ticket_ttl'))];
    }

    public function events(Request $request): array
    {
        $data = $request->validate(['after' => 'required|integer|min:0']);

        return DB::transaction(function () use ($request, $data) {
            $this->im->profile((int) $request->user()->getAuthIdentifier());
            DB::table('im_profiles')->where('user_id', (int) $request->user()->getAuthIdentifier())->lockForUpdate()->first();

            return app(EventStore::class)->after((int) $request->user()->getAuthIdentifier(), (int) $data['after']);
        }, 3);
    }

    public function profile(Request $request): array
    {
        $data = $request->validate(['username' => 'sometimes|required|string|max:80', 'sign' => 'sometimes|nullable|string|max:255', 'status' => 'sometimes|required|in:online,hide', 'avatar' => 'sometimes|required|string|max:2048']);

        return DB::transaction(function () use ($request, $data) {
            $id = (int) $request->user()->getAuthIdentifier();
            $this->im->profile($id);
            DB::table('im_profiles')->where('user_id', $id)->lockForUpdate()->first();
            $previous = $this->im->user($id);
            if (isset($data['avatar'])) {
                $this->image($id, $data['avatar']);
            }
            if (isset($data['username'])) {
                app(Users::class)->query()->findOrFail($id)->forceFill([config('im.user_name_column', 'name') => $data['username']])->save();
            }
            DB::table('im_profiles')->where('user_id', $id)->update(array_diff_key($data, ['username' => true, 'sign' => true]) + ['sign' => array_key_exists('sign', $data) ? ($data['sign'] ?? '') : $this->im->profile($id)->sign]);
            $this->im->event([$id], 'user.updated', $this->im->user($id, true));
            $contact = $this->im->user($id);
            $event = [
                'contact' => array_merge($contact, ['type' => 'friend']),
            ];
            if ($previous['sign'] !== $contact['sign'] && config('im.signature_notices', true)) {
                $event['notice'] = ['id' => $id, 'event' => 'signature', 'sign' => $contact['sign'], 'timestamp' => now()->getTimestampMs()];
            }
            if ($previous['status'] !== $contact['status']) {
                $event['presenceChanged'] = true;
            }
            $this->im->event($this->im->audience($id), 'contact.updated', $event);

            return $this->im->user($id, true);
        }, 3);
    }

    private function image(int $userId, string $url): void
    {
        abort_unless(DB::table('im_uploads')->where(['user_id' => $userId, 'url' => $url, 'kind' => 'image'])->exists(), 422, 'Upload an image before using it as an avatar.');
    }

    public function categories(Request $request): array
    {
        $data = $request->validate(['groupname' => 'required|string|max:80']);

        return DB::transaction(function () use ($request, $data) {
            $id = (int) $request->user()->getAuthIdentifier();
            $attributes = array_merge(['user_id' => $id], $data);
            DB::table('im_friend_groups')->insertOrIgnore($attributes);
            $group = DB::table('im_friend_groups')->where($attributes)->first();
            $result = ['id' => $group->id, 'groupname' => $group->groupname, 'list' => DB::table('im_friends')->where(['user_id' => $id, 'group_id' => $group->id])->pluck('friend_id')->map(fn ($friend) => $this->im->user($friend))->all()];
            $this->im->event([$id], 'category.updated', $result);

            return $result;
        }, 3);
    }

    public function moveFriend(Request $request, int $friend): array
    {
        $data = $request->validate(['groupId' => 'required|integer']);

        return DB::transaction(function () use ($request, $friend, $data) {
            $id = (int) $request->user()->getAuthIdentifier();
            $this->im->friend($id, $friend);
            $this->im->ownedCategory($id, $data['groupId']);
            DB::table('im_friends')->where(['user_id' => $id, 'friend_id' => $friend])->update(['group_id' => $data['groupId']]);
            $this->im->event([$id], 'friend.moved', array_merge(['id' => $friend], $data));

            return ['ok' => true];
        }, 3);
    }

    public function removeFriend(Request $request, int $friend): array
    {
        return DB::transaction(function () use ($request, $friend) {
            $id = (int) $request->user()->getAuthIdentifier();
            app(Users::class)->query()->whereKey([$id, $friend])->orderBy(app(Users::class)->query()->getModel()->getKeyName())->lockForUpdate()->get();
            $this->im->friend($id, $friend);
            DB::table('im_friends')->where(fn ($query) => $query->where(['user_id' => $id, 'friend_id' => $friend])->orWhere(['user_id' => $friend, 'friend_id' => $id]))->delete();
            $this->im->event([$id], 'friend.removed', ['id' => $friend]);
            $this->im->event([$friend], 'friend.removed', ['id' => $id]);

            return ['ok' => true];
        }, 3);
    }

    public function blocks(Request $request): array
    {
        return $this->im->blockedUsers((int) $request->user()->getAuthIdentifier());
    }

    /** Blocking deletes mutual friendship and resolves outstanding requests atomically. */
    public function block(Request $request, int $user): array
    {
        $id = (int) $request->user()->getAuthIdentifier();
        abort_if($id === $user, 422, 'Cannot block yourself.');

        return DB::transaction(function () use ($id, $user) {
            $users = app(Users::class)->query()->whereKey([$id, $user])->orderBy(app(Users::class)->query()->getModel()->getKeyName())->lockForUpdate()->get();
            abort_unless($users->count() === 2, 404, 'User not found.');
            DB::table('im_blocks')->insertOrIgnore(['user_id' => $id, 'blocked_id' => $user]);
            DB::table('im_friends')->where(fn ($query) => $query->where(['user_id' => $id, 'friend_id' => $user])->orWhere(['user_id' => $user, 'friend_id' => $id]))->delete();
            $notices = DB::table('im_notices')->where('type', 'friend-request')->whereNull('resolved_at')
                ->where(fn ($query) => $query->where(['user_id' => $id, 'sender_id' => $user])->orWhere(['user_id' => $user, 'sender_id' => $id]))->lockForUpdate()->get();
            foreach ($notices as $notice) {
                DB::table('im_notices')->where('id', $notice->id)->update(['read_at' => now(), 'resolved_at' => now(), 'resolution' => 'reject']);
                $this->im->event([$notice->user_id], 'notice.updated', $this->im->notice(DB::table('im_notices')->where('id', $notice->id)->first()));
            }
            $contact = $this->im->user($user);
            $this->im->event([$id], 'user.blocked', ['contact' => $contact]);
            $this->im->event([$user], 'friend.removed', ['id' => $id]);

            return $contact;
        }, 3);
    }

    /** Unblocking never recreates a friendship. */
    public function unblock(Request $request, int $user): array
    {
        return DB::transaction(function () use ($request, $user) {
            $id = (int) $request->user()->getAuthIdentifier();
            app(Users::class)->query()->whereKey([$id, $user])->orderBy(app(Users::class)->query()->getModel()->getKeyName())->lockForUpdate()->get();
            DB::table('im_blocks')->where(['user_id' => $id, 'blocked_id' => $user])->delete();
            $this->im->event([$id], 'user.unblocked', ['id' => $user]);

            return ['ok' => true];
        }, 3);
    }

    public function find(Request $request): array
    {
        $data = $request->validate(['q' => 'nullable|string|max:80']);

        return app(Users::class)->query()->whereKeyNot((int) $request->user()->getAuthIdentifier())->when($data['q'] ?? '', fn ($query, $keyword) => $query->where(config('im.user_name_column', 'name'), 'like', '%'.addcslashes($keyword, '%_\\').'%'))
            ->orderBy(app(Users::class)->query()->getModel()->getKeyName())->limit(30)->pluck(app(Users::class)->query()->getModel()->getKeyName())->map(fn ($id) => $this->im->user($id))->all();
    }

    public function requestFriend(Request $request): array
    {
        $data = $request->validate(['id' => ['required', 'integer', Rule::exists(app(Users::class)->query()->getModel()->getTable(), app(Users::class)->query()->getModel()->getKeyName())]]);
        $data['id'] = (int) $data['id'];
        abort_if($data['id'] === (int) $request->user()->getAuthIdentifier(), 422, 'Cannot add yourself.');

        return DB::transaction(function () use ($request, $data) {
            $sender = (int) $request->user()->getAuthIdentifier();
            app(Users::class)->query()->whereKey([$sender, $data['id']])->orderBy(app(Users::class)->query()->getModel()->getKeyName())->lockForUpdate()->get();
            abort_if(DB::table('im_friends')->where(['user_id' => $sender, 'friend_id' => $data['id']])->exists(), 409, 'Already friends.');
            $this->im->requestFriend($sender, $data['id']);

            return ['ok' => true];
        }, 3);
    }

    public function notice(Request $request, int $notice): array
    {
        $data = $request->validate(['action' => 'required|in:accept,reject,read', 'groupId' => 'required_if:action,accept|integer']);

        return DB::transaction(function () use ($request, $notice, $data) {
            $id = (int) $request->user()->getAuthIdentifier();
            $sender = DB::table('im_notices')->where(['id' => $notice, 'user_id' => $id])->value('sender_id');
            app(Users::class)->query()->whereKey(array_filter([$id, $sender]))->orderBy(app(Users::class)->query()->getModel()->getKeyName())->lockForUpdate()->get();
            $item = DB::table('im_notices')->where(['id' => $notice, 'user_id' => $id])->lockForUpdate()->first();
            abort_unless($item, 404);
            if ($data['action'] !== 'read') {
                abort_unless($item->type === 'friend-request', 422);
                if ($data['action'] === 'accept') {
                    $this->im->assertNotBlocked($id, (int) $item->sender_id);
                }
                abort_if($item->resolved_at && $item->resolution !== $data['action'], 409, 'Request already resolved.');
                if (! $item->resolved_at && $data['action'] === 'accept') {
                    $this->im->ownedCategory($id, $data['groupId']);
                    foreach ([[$id, $item->sender_id, $data['groupId']], [$item->sender_id, $id, $this->im->category($item->sender_id)]] as [$user, $friend, $group]) {
                        DB::table('im_friends')->updateOrInsert(['user_id' => $user, 'friend_id' => $friend], ['group_id' => $group]);
                        $this->im->event([$user], 'contact.updated', ['contact' => array_merge($this->im->user($friend), ['type' => 'friend']), 'groupId' => $group]);
                    }
                }
            }
            $noticeUpdate = ['read_at' => now()];
            if ($data['action'] !== 'read') {
                $noticeUpdate = array_merge($noticeUpdate, ['resolved_at' => now(), 'resolution' => $data['action']]);
            }
            DB::table('im_notices')->where('id', $notice)->update($noticeUpdate);
            $result = $this->im->notice(DB::table('im_notices')->where('id', $notice)->first());
            $this->im->event([$id], 'notice.updated', $result);

            return $result;
        }, 3);
    }

    public function createGroup(Request $request): array
    {
        $data = $request->validate(['groupname' => 'required|string|max:80', 'members' => 'required|array|min:1|max:200', 'members.*' => 'required|integer|distinct']);

        return DB::transaction(function () use ($request, $data) {
            $userId = (int) $request->user()->getAuthIdentifier();
            foreach ($data['members'] as $member) {
                $this->im->friend($userId, $member);
            }
            $id = DB::table('im_groups')->insertGetId(['owner_id' => $userId, 'groupname' => $data['groupname'], 'created_at' => now(), 'updated_at' => now()]);
            foreach (array_unique(array_merge([$userId], $data['members'])) as $member) {
                DB::table('im_group_members')->insert(['group_id' => $id, 'user_id' => $member, 'joined_at' => now()]);
            }
            $this->im->groupEvent($id);

            return $this->im->groupContact($id);
        }, 3);
    }

    public function members(Request $request, int $group): array
    {
        $this->im->group((int) $request->user()->getAuthIdentifier(), $group);

        return $this->im->members($group);
    }

    public function groupProfile(Request $request, int $group): array
    {
        $data = $request->validate(['groupname' => 'sometimes|required|string|max:80', 'avatar' => 'sometimes|required|string|max:2048']);

        return DB::transaction(function () use ($request, $group, $data) {
            $this->im->manager((int) $request->user()->getAuthIdentifier(), $this->im->group((int) $request->user()->getAuthIdentifier(), $group, true));
            if (isset($data['avatar'])) {
                $this->image((int) $request->user()->getAuthIdentifier(), $data['avatar']);
            }
            DB::table('im_groups')->where('id', $group)->update(array_merge($data, ['updated_at' => now()]));
            $this->im->groupEvent($group);

            return $this->im->groupContact($group);
        }, 3);
    }

    public function invite(Request $request, int $group): array
    {
        $data = $request->validate(['members' => 'required|array|min:1|max:200', 'members.*' => 'required|integer|distinct']);

        return DB::transaction(function () use ($request, $group, $data) {
            $this->im->group((int) $request->user()->getAuthIdentifier(), $group, true);
            foreach ($data['members'] as $member) {
                $this->im->friend((int) $request->user()->getAuthIdentifier(), $member);
                DB::table('im_group_members')->insertOrIgnore(['group_id' => $group, 'user_id' => $member, 'joined_at' => now()]);
            }
            $this->im->groupEvent($group);

            return ['ok' => true];
        }, 3);
    }

    public function leave(Request $request, int $group): array
    {
        return DB::transaction(function () use ($request, $group) {
            abort_if($this->im->group((int) $request->user()->getAuthIdentifier(), $group, true)->owner_id === (int) $request->user()->getAuthIdentifier(), 403, 'Owner must dismiss the group.');
            DB::table('im_group_members')->where(['group_id' => $group, 'user_id' => (int) $request->user()->getAuthIdentifier()])->delete();
            $this->im->event([(int) $request->user()->getAuthIdentifier()], 'group.removed', ['id' => $group]);
            $this->im->groupEvent($group);

            return ['ok' => true];
        }, 3);
    }

    public function dismiss(Request $request, int $group): array
    {
        return DB::transaction(function () use ($request, $group) {
            abort_unless($this->im->group((int) $request->user()->getAuthIdentifier(), $group, true)->owner_id === (int) $request->user()->getAuthIdentifier(), 403);
            $this->im->event(DB::table('im_group_members')->where('group_id', $group)->pluck('user_id')->all(), 'group.removed', ['id' => $group]);
            DB::table('im_groups')->where('id', $group)->delete();

            return ['ok' => true];
        }, 3);
    }

    public function removeMember(Request $request, int $group, int $member): array
    {
        return DB::transaction(function () use ($request, $group, $member) {
            $this->im->manageableMember((int) $request->user()->getAuthIdentifier(), $this->im->group((int) $request->user()->getAuthIdentifier(), $group, true), $member);
            DB::table('im_group_members')->where(['group_id' => $group, 'user_id' => $member])->delete();
            $this->im->event([$member], 'group.removed', ['id' => $group]);
            $this->im->groupEvent($group);

            return ['ok' => true];
        }, 3);
    }

    public function mute(Request $request, int $group, ?int $member = null): array
    {
        $data = $request->validate(['muted' => 'required|boolean']);

        return DB::transaction(function () use ($request, $group, $member, $data) {
            $info = $this->im->group((int) $request->user()->getAuthIdentifier(), $group, true);
            if ($member !== null) {
                $this->im->manageableMember((int) $request->user()->getAuthIdentifier(), $info, $member);
                DB::table('im_group_members')->where(['group_id' => $group, 'user_id' => $member])->update($data);
            } else {
                $this->im->manager((int) $request->user()->getAuthIdentifier(), $info);
                DB::table('im_groups')->where('id', $group)->update(['mute_all' => $data['muted']]);
            }
            $this->im->groupEvent($group);

            return ['ok' => true];
        }, 3);
    }

    public function admin(Request $request, int $group, int $member): array
    {
        $data = $request->validate(['admin' => 'required|boolean']);

        return DB::transaction(function () use ($request, $group, $member, $data) {
            $info = $this->im->group((int) $request->user()->getAuthIdentifier(), $group, true);
            abort_unless($info->owner_id === (int) $request->user()->getAuthIdentifier() && $member !== $info->owner_id, 403);
            abort_unless(DB::table('im_group_members')->where(['group_id' => $group, 'user_id' => $member])->exists(), 404);
            DB::table('im_group_members')->where(['group_id' => $group, 'user_id' => $member])->update($data);
            $this->im->groupEvent($group);

            return ['ok' => true];
        }, 3);
    }

    public function history(Request $request): array
    {
        $data = $request->validate(['type' => 'required|in:friend,group', 'id' => 'required|integer', 'before' => 'sometimes|integer|min:1']);
        $query = $this->im->messages((int) $request->user()->getAuthIdentifier(), $data['type'], $data['id'], true);
        $messages = $query->when($data['before'] ?? null, fn ($query, $before) => $query->where('id', '<', $before))
            ->orderByDesc('id')->limit(config('im.history_limit'))->get();

        return ['messages' => $messages->reverse()->values()->map(fn ($message) => $this->im->message($message, (int) $request->user()->getAuthIdentifier()))->all(), 'before' => $messages->last()?->id];
    }

    public function read(Request $request): array
    {
        $data = $request->validate([
            'type' => 'required|in:friend,group', 'id' => 'required|integer',
            'mids' => 'required|array|min:1|max:100', 'mids.*' => 'required|uuid|distinct',
        ]);

        return DB::transaction(function () use ($request, $data) {
            $reader = (int) $request->user()->getAuthIdentifier();
            app(Users::class)->query()->whereKey($reader)->lockForUpdate()->first();
            $messages = $this->im->messages($reader, $data['type'], (int) $data['id'])
                ->where('sender_id', '!=', $reader)->whereIn('client_mid', $data['mids'])->get();
            abort_unless($messages->pluck('client_mid')->unique()->count() === count($data['mids']), 422, 'Only received messages in this conversation can be marked read.');
            $receipts = [];
            foreach ($messages as $message) {
                if (DB::table('im_message_reads')->insertOrIgnore(['message_id' => $message->id, 'user_id' => $reader])) {
                    $receipts[$message->sender_id][] = $message->client_mid;
                }
            }
            foreach ($receipts as $sender => $mids) {
                $this->im->event([$sender], 'message.read', [
                    'id' => $data['type'] === 'group' ? (int) $data['id'] : $reader,
                    'type' => $data['type'], 'mids' => $mids, 'readerId' => $reader,
                ]);
            }

            return ['ok' => true];
        }, 3);
    }

    public function typing(Request $request): array
    {
        $data = $request->validate(['type' => 'required|in:friend,group', 'id' => 'required|integer', 'typing' => 'required|boolean']);

        return DB::transaction(function () use ($request, $data) {
            $sender = (int) $request->user()->getAuthIdentifier();
            $id = (int) $data['id'];
            if ($data['type'] === 'group') {
                $group = $this->im->group($sender, $id, true);
                $member = DB::table('im_group_members')->where(['group_id' => $id, 'user_id' => $sender])->first();
                abort_if($data['typing'] && $group->owner_id !== $sender && ! $member->admin && ($group->mute_all || $member->muted), 403, 'You are muted.');
                $recipients = DB::table('im_group_members')->where('group_id', $id)->where('user_id', '!=', $sender)->pluck('user_id')->all();
            } else {
                $this->im->friend($sender, $id);
                $recipients = [$id];
            }
            $this->im->event($recipients, 'typing', [
                'id' => $data['type'] === 'group' ? $id : $sender, 'type' => $data['type'],
                'fromid' => $sender, 'username' => $this->im->user($sender)['username'], 'typing' => (bool) $data['typing'],
                'expiresAt' => now()->addSeconds($data['typing'] ? 6 : 0)->getTimestampMs(),
            ]);

            return ['ok' => true];
        }, 3);
    }

    public function send(Request $request): array
    {
        $data = $request->validate([
            'type' => 'required|in:friend,group', 'id' => 'required|integer', 'mid' => 'required|uuid', 'content' => 'required|string|max:20000',
            'attachment' => 'sometimes|array:kind,url,name,duration|prohibits:attachments', 'attachment.kind' => 'required_with:attachment|in:image,file,audio',
            'attachment.url' => 'required_with:attachment|string|max:2048', 'attachment.name' => 'required_with:attachment|string|max:255',
            'attachment.duration' => 'sometimes|numeric|min:0|max:120',
            'attachments' => 'sometimes|array|min:1|prohibits:attachment', 'attachments.*' => 'required|array:kind,url,name',
            'attachments.*.kind' => 'required|in:image', 'attachments.*.url' => 'required|string|max:2048',
            'attachments.*.name' => 'required|string|max:255',
        ]);
        $data['id'] = (int) $data['id'];

        return DB::transaction(function () use ($request, $data) {
            $id = (int) $request->user()->getAuthIdentifier();
            app(Users::class)->query()->whereKey($data['type'] === 'friend' ? [$id, $data['id']] : [$id])->orderBy(app(Users::class)->query()->getModel()->getKeyName())->lockForUpdate()->get();
            $this->im->messages($id, $data['type'], $data['id']);
            if ($data['type'] === 'group') {
                $group = $this->im->group($id, $data['id'], true);
                $member = DB::table('im_group_members')->where(['group_id' => $group->id, 'user_id' => $id])->first();
                abort_if($group->owner_id !== $id && ! $member->admin && ($group->mute_all || $member->muted), 403, 'You are muted.');
            }
            $attachment = $data['attachment'] ?? $data['attachments'] ?? null;
            $message = DB::table('im_messages')->where(['sender_id' => $id, 'client_mid' => $data['mid']])->first();
            if ($message) {
                abort_if(($message->group_id ?? $message->recipient_id) !== $data['id'] || (bool) $message->group_id !== ($data['type'] === 'group') || $message->content !== $data['content'] || json_decode($message->attachment ?? 'null', true) !== $attachment, 409, 'Message ID already used.');

                return $this->im->message($message, $id);
            }
            foreach ($data['attachments'] ?? (isset($data['attachment']) ? [$data['attachment']] : []) as $item) {
                $kinds = $item['kind'] === 'file' ? ['file', 'image'] : [$item['kind']];
                abort_unless(DB::table('im_uploads')->where(['user_id' => $id, 'url' => $item['url']])->whereIn('kind', $kinds)->exists(), 422, 'Attachment must be uploaded first.');
            }
            app(SensitiveWords::class)->validate([$data['content'], ...array_column($data['attachments'] ?? [], 'name'), $data['attachment']['name'] ?? '']);
            $messageId = DB::table('im_messages')->insertGetId([
                'sender_id' => $id, 'recipient_id' => $data['type'] === 'friend' ? $data['id'] : null, 'group_id' => $data['type'] === 'group' ? $data['id'] : null,
                'client_mid' => $data['mid'], 'content' => $data['content'], 'attachment' => $attachment ? json_encode($attachment, JSON_THROW_ON_ERROR) : null,
                'created_at' => now(),
            ]);
            $message = DB::table('im_messages')->where('id', $messageId)->first();
            $users = $data['type'] === 'group' ? DB::table('im_group_members')->where('group_id', $data['id'])->pluck('user_id')->all() : [$id, $data['id']];
            foreach ($users as $user) {
                $this->im->event([$user], 'message', $this->im->message($message, $user));
            }

            return $this->im->message($message, $id);
        }, 3);
    }
}
