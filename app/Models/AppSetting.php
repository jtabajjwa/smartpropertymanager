<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Platform-wide settings staff can change (see App\Support\Settings). */
class AppSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];
}
