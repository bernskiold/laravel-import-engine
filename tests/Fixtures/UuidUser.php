<?php

namespace Bernskiold\LaravelImportEngine\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;

class UuidUser extends Authenticatable
{
    use HasUuids;

    protected $table = 'uuid_users';

    protected $guarded = [];
}
