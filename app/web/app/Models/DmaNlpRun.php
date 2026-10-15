<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class DmaNlpRun extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['iro_ids' => 'array', 'result' => 'array', 'p8_revision' => 'integer', 'created_at' => 'datetime'];
}
