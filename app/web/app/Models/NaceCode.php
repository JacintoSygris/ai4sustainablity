<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NaceCode extends Model
{
    protected $fillable = [
        'code',
        'level',
        'parent_code',
        'title_en',
        'title_es',
    ];

    protected $casts = [
        'level' => 'integer',
    ];

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_code', 'code');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_code', 'code');
    }
}
