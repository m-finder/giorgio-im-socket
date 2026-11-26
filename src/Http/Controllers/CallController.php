<?php

namespace GiorgioImSocket\Http\Controllers;

use GiorgioImSocket\Services\ImService;
use GiorgioImSocket\Services\Users;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CallController extends Controller
{
    public function signal(Request $request, ImService $im): array
    {
        $data = $request->validate([
            'id' => 'required|integer|min:1', 'callId' => 'required|uuid',
            'action' => 'required|in:'.($request->has('groupId')
                ? 'invite,join,leave,offer,answer,end,reject,keepalive'
                : 'offer,answer,end,reject,keepalive'),
            'groupId' => 'sometimes|required|integer|min:1',
            'type' => 'required_if:action,invite|filled|in:voice,video',
            'sdp' => 'required_if:action,offer,answer|string|max:30000',
        ]);
        $sender = (int) $request->user()->getAuthIdentifier();
        if (isset($data['groupId'])) {
            return $this->groupSignal($data, $sender, $im);
        }
        $recipient = (int) $data['id'];
        abort_if($sender === $recipient, 422, 'Cannot call yourself.');

        return DB::transaction(function () use ($data, $sender, $recipient, $im) {
            app(Users::class)->query()->whereKey([$sender, $recipient])->orderBy(app(Users::class)->query()->getModel()->getKeyName())->lockForUpdate()->get();
            $call = DB::table('im_calls')->where('id', $data['callId'])->lockForUpdate()->first();
            if ($data['action'] === 'offer') {
                $im->friend($sender, $recipient);
                abort_if($call !== null, 409, 'Call already exists.');

                $this->assertAvailable([$sender, $recipient]);
                DB::table('im_calls')->insert([
                    'id' => $data['callId'], 'caller_id' => $sender, 'recipient_id' => $recipient,
                    'state' => 'ringing', 'expires_at' => now()->addSeconds(60),
                ]);
                $expiresAt = now()->addSeconds(60)->getTimestampMs();
            } else {
                abort_unless($call && (($call->caller_id === $sender && $call->recipient_id === $recipient)
                    || ($call->recipient_id === $sender && $call->caller_id === $recipient)), 404, 'Call not found.');
                if ($call->state === 'ended' && $data['action'] === 'end') {
                    return ['ok' => true];
                }
                abort_if($call->state === 'ended' || Carbon::parse($call->expires_at)->lte(now()), 409, 'Call has ended.');
                if ($data['action'] === 'answer' || $data['action'] === 'reject') {
                    abort_unless($call->recipient_id === $sender && $call->state === 'ringing', 409, 'Call is no longer ringing.');
                }
                if (in_array($data['action'], ['answer', 'keepalive'])) {
                    $im->friend($sender, $recipient);
                }
                if ($data['action'] === 'keepalive') {
                    abort_unless($call->state === 'accepted', 409, 'Call is not connected.');
                    DB::table('im_calls')->where('id', $data['callId'])->update(['expires_at' => now()->addSeconds(90)]);

                    return ['ok' => true];
                }
                DB::table('im_calls')->where('id', $data['callId'])->update([
                    'state' => $data['action'] === 'answer' ? 'accepted' : 'ended',
                    'expires_at' => $data['action'] === 'answer' ? now()->addSeconds(90) : now(),
                ]);
                $expiresAt = now()->addSeconds(30)->getTimestampMs();
            }
            $signal = ['callId' => $data['callId'], 'id' => $sender, 'action' => $data['action'], 'expiresAt' => $expiresAt];
            if (in_array($data['action'], ['offer', 'answer'])) {
                $signal['sdp'] = rtrim($data['sdp'], "\r\n")."\r\n";
                if (isset($data['type'])) {
                    $signal['type'] = $data['type'];
                }
            }
            if ($data['action'] === 'offer') {
                $signal['contact'] = $im->user($sender);
            }
            $im->event([$recipient], 'call.signal', $signal);
            if ($data['action'] !== 'offer') {
                $im->event([$sender], 'call.signal', [
                    'callId' => $data['callId'], 'id' => $recipient,
                    'action' => $data['action'] === 'answer' ? 'answered' : $data['action'],
                ]);
            }

            return ['ok' => true];
        }, 3);
    }

    private function groupSignal(array $data, int $sender, ImService $im): array
    {
        return DB::transaction(function () use ($data, $sender, $im) {
            $group = $im->group($sender, (int) $data['groupId'], true);
            $memberIds = DB::table('im_group_members')->where('group_id', $group->id)->orderBy('user_id')->pluck('user_id')->all();
            app(Users::class)->query()->whereKey($memberIds)->orderBy(app(Users::class)->query()->getModel()->getKeyName())->lockForUpdate()->get();
            $room = DB::table('im_group_calls')->where('id', $data['callId'])->lockForUpdate()->first();
            if ($data['action'] === 'invite') {
                abort_if($room !== null, 409, 'Call already exists.');
                abort_if(DB::table('im_group_calls')->where('group_id', $group->id)->where('state', 'active')
                    ->where('expires_at', '>', now())->exists(), 423, 'Group call already exists.');
                $this->assertAvailable([$sender]);
                DB::table('im_group_calls')->insert([
                    'id' => $data['callId'], 'group_id' => $group->id, 'creator_id' => $sender,
                    'type' => $data['type'], 'state' => 'active', 'expires_at' => now()->addSeconds(90),
                ]);
                foreach ($memberIds as $id) {
                    DB::table('im_group_call_members')->insert([
                        'call_id' => $data['callId'], 'user_id' => $id, 'state' => $id === $sender ? 'joined' : 'invited',
                        'expires_at' => now()->addSeconds($id === $sender ? 90 : 60),
                    ]);
                }
                $room = DB::table('im_group_calls')->where('id', $data['callId'])->first();
            } else {
                abort_unless($room && $room->group_id === $group->id, 404, 'Call not found.');
                $ended = $room->state !== 'active' || Carbon::parse($room->expires_at)->lte(now());
                DB::table('im_group_call_members')->where('call_id', $room->id)->whereIn('state', ['invited', 'joined'])
                    ->where(fn ($query) => $query->where('expires_at', '<=', now())->orWhereNotIn('user_id', $memberIds))
                    ->update(['state' => 'left', 'expires_at' => now()]);
                $member = DB::table('im_group_call_members')->where(['call_id' => $room->id, 'user_id' => $sender])->first();
                if ($member && in_array($data['action'], ['leave', 'reject', 'end']) && ($ended || $member->state === 'left')) {
                    return ['ok' => true];
                }
                abort_if($ended, 409, 'Call has ended.');
                abort_unless($member && in_array($member->state, ['invited', 'joined']), 409, 'Call membership required.');
                if ($data['action'] === 'join') {
                    $this->assertAvailable([$sender], $room->id);
                    DB::table('im_group_call_members')->where(['call_id' => $room->id, 'user_id' => $sender])
                        ->update(['state' => 'joined', 'expires_at' => now()->addSeconds(90)]);
                } elseif (in_array($data['action'], ['offer', 'answer', 'keepalive'])) {
                    abort_unless($member->state === 'joined', 409, 'Join the call first.');
                    if ($data['action'] !== 'keepalive') {
                        abort_if((int) $data['id'] === $sender, 422, 'Cannot call yourself.');
                        $recipient = DB::table('im_group_call_members')->where(['call_id' => $room->id, 'user_id' => $data['id']])->first();
                        abort_unless($recipient, 404, 'Call participant not found.');
                        abort_unless($recipient->state === 'joined', 409, 'Peer has not joined.');
                    }
                    DB::table('im_group_call_members')->where(['call_id' => $room->id, 'user_id' => $sender])
                        ->update(['expires_at' => now()->addSeconds(90)]);
                } else {
                    $remaining = DB::table('im_group_call_members')->where(['call_id' => $room->id, 'state' => 'joined'])
                        ->where('user_id', '!=', $sender)->count();
                    $end = $remaining === 0 || ($remaining === 1 && $member->state === 'joined');
                    DB::table('im_group_call_members')->where('call_id', $room->id)
                        ->when(! $end, fn ($query) => $query->where('user_id', $sender))
                        ->update(['state' => 'left', 'expires_at' => now()]);
                    if ($end) {
                        DB::table('im_group_calls')->where('id', $room->id)->update(['state' => 'ended', 'expires_at' => now()]);
                    }
                    $data['action'] = $end ? 'end' : 'leave';
                }
                if (in_array($data['action'], ['join', 'offer', 'answer', 'keepalive'])) {
                    DB::table('im_group_calls')->where('id', $room->id)->update(['expires_at' => now()->addSeconds(90)]);
                }
            }
            if ($data['action'] === 'keepalive') {
                return ['ok' => true];
            }
            $participants = DB::table('im_group_call_members')->where(['call_id' => $room->id, 'state' => 'joined'])
                ->orderBy('user_id')->pluck('user_id')->map(fn ($id) => $im->user($id))->all();
            $signal = [
                'callId' => $room->id, 'groupId' => $group->id, 'id' => $sender,
                'action' => $data['action'],
                'type' => $room->type, 'contact' => $im->user($sender), 'group' => $im->groupContact($group->id),
                'participants' => $participants, 'expiresAt' => now()->addSeconds($data['action'] === 'invite' ? 60 : 90)->getTimestampMs(),
            ];
            if (in_array($data['action'], ['offer', 'answer'])) {
                $signal['sdp'] = rtrim($data['sdp'], "\r\n")."\r\n";
                $recipients = [(int) $data['id']];
            } elseif ($data['action'] === 'invite') {
                $recipients = array_values(array_diff($memberIds, [$sender]));
            } else {
                $recipients = $memberIds;
            }
            $im->event($recipients, 'call.signal', $signal);

            return ['ok' => true];
        }, 3);
    }

    private function assertAvailable(array $users, ?string $except = null): void
    {
        abort_if(DB::table('im_calls')->where('state', '!=', 'ended')->where('expires_at', '>', now())
            ->where(fn ($query) => $query->whereIn('caller_id', $users)->orWhereIn('recipient_id', $users))->exists(), 423, 'User is busy.');
        abort_if(DB::table('im_group_call_members')->whereIn('user_id', $users)->where('state', 'joined')->where('expires_at', '>', now())
            ->when($except !== null, fn ($query) => $query->where('call_id', '!=', $except))->exists(), 423, 'User is busy.');
    }
}
