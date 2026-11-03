<?php

namespace GiorgioImSocket\Services;

use GiorgioSocket\Services\EventStore;
use Illuminate\Support\Facades\DB;

class SocketEvents
{
    public function __invoke(string $id, int $cursor): array
    {
        // Share the writer lock so a later committed event cannot skip an earlier one.
        return DB::transaction(function () use ($id, $cursor) {
            app(ImService::class)->profile((int) $id);
            DB::table('im_profiles')->where('user_id', $id)->lockForUpdate()->first();

            return app(EventStore::class)->after($id, $cursor);
        }, 3);
    }
}
