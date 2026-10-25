<?php

namespace Bernskiold\LaravelImportEngine\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class Sector extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
