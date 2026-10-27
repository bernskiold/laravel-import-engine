<?php

namespace Bernskiold\LaravelImportEngine\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (Contact $contact): void {
            $contact->created_by_id ??= auth()->id();
        });
    }
}
