<?php

namespace GiorgioImSocket\Services;

use Illuminate\Support\Facades\DB;

class SocketPresence
{
    public function __invoke(int $id, bool $online): void
    {
        try {
            DB::transaction(function () use ($id, $online) {
                $im = app(ImService::class);
                $im->profile($id);
                DB::table('im_profiles')->where('user_id', $id)->lockForUpdate()->first();
                $previous = $im->user($id)['status'];
                DB::table('im_profiles')->where('user_id', $id)->update(['presence_at' => $online ? now() : null]);
                if ($previous !== $im->user($id)['status']) {
                    $im->event($im->audience($id), 'presence', ['id' => $id, 'status' => $im->user($id)['status'], 'timestamp' => now()->getTimestampMs()]);
                }
            }, 3);
        } catch (\Throwable $error) {
            report($error);
        }
    }
}
