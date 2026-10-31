<?php

namespace GiorgioImSocket\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Resolve the application's existing Eloquent user model. User IDs must be positive integers. */
class Users
{
    public function query(): Builder
    {
        $model = config('im.user_model') ?? config('auth.providers.users.model');
        if (! is_string($model) || ! is_subclass_of($model, Model::class)) {
            throw new \LogicException('Configure im.user_model with an Eloquent user model.');
        }

        return (new $model)->newQuery();
    }
}
