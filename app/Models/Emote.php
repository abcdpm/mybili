<?php

namespace App\Models;

use App\Traits\UsesCommentsDatabase;
use Illuminate\Database\Eloquent\Model;

class Emote extends Model
{
    use UsesCommentsDatabase;

    protected $guarded = [];
}
