<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class LearningCaseP5Snapshot extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'learning_case_id' => 'integer', 'actor_id' => 'integer', 'group_id' => 'integer', 'authorization_generation' => 'integer'];
    }

    protected function performUpdate(Builder $query): bool
    {
        throw new LogicException('learning_p5.snapshot_immutable');
    }

    // Quiet, static and unsaved dispatch also reach this seam. Reject before
    // Laravel changes in-memory attributes or forwards an UPDATE to the builder.
    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        throw new LogicException('learning_p5.snapshot_immutable');
    }

    public function newEloquentBuilder($query)
    {
        return new class($query) extends Builder {
            public function update(array $values) { throw new LogicException('learning_p5.snapshot_immutable'); }
            public function upsert(array $values, $uniqueBy, $update = null) { throw new LogicException('learning_p5.snapshot_immutable'); }
            public function increment($column, $amount = 1, array $extra = []) { throw new LogicException('learning_p5.snapshot_immutable'); }
            public function decrement($column, $amount = 1, array $extra = []) { throw new LogicException('learning_p5.snapshot_immutable'); }
            public function touch($column = null) { throw new LogicException('learning_p5.snapshot_immutable'); }

            public function __call($method, $parameters)
            {
                // Insert-only calls and existing deletion/FK behavior remain
                // inherited. Raw DB/getQuery/toBase access is privileged.
                if (in_array(strtolower($method), [
                    'updatefrom', 'updateorinsert', 'incrementeach', 'decrementeach',
                    'incrementquietly', 'decrementquietly',
                ], true)) {
                    throw new LogicException('learning_p5.snapshot_immutable');
                }

                return parent::__call($method, $parameters);
            }
        };
    }
}
