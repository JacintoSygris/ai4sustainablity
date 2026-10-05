<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Application-level append-only events, not database administrator-proof WORM. */
class LearningAuthorizationRecord extends Model
{
    public $timestamps = false;
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['learning_authorization_state_id' => 'integer', 'generation' => 'integer',
            'previous_generation' => 'integer', 'promotion_allowed' => 'boolean'];
    }

    protected function performUpdate(Builder $query): bool
    {
        throw new LogicException('learning_ledger.event_immutable');
    }

    // Includes quiet and unsaved/static dispatch; reject before attributes change.
    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        throw new LogicException('learning_ledger.event_immutable');
    }

    public function delete()
    {
        throw new LogicException('learning_ledger.event_immutable');
    }

    public function newEloquentBuilder($query)
    {
        return new class($query) extends Builder {
            public function update(array $values) { throw new LogicException('learning_ledger.event_immutable'); }
            public function delete() { throw new LogicException('learning_ledger.event_immutable'); }
            public function forceDelete() { throw new LogicException('learning_ledger.event_immutable'); }
            public function upsert(array $values, $uniqueBy, $update = null) { throw new LogicException('learning_ledger.event_immutable'); }
            public function increment($column, $amount = 1, array $extra = []) { throw new LogicException('learning_ledger.event_immutable'); }
            public function decrement($column, $amount = 1, array $extra = []) { throw new LogicException('learning_ledger.event_immutable'); }
            public function touch($column = null) { throw new LogicException('learning_ledger.event_immutable'); }

            public function __call($method, $parameters)
            {
                // Query-builder mutations otherwise bypass Eloquent overrides.
                // Insert-only methods remain available for new events. Explicit
                // getQuery/toBase/connection access is a privileged raw boundary.
                if (in_array(strtolower($method), [
                    'updatefrom', 'updateorinsert', 'incrementeach', 'decrementeach',
                    'truncate', 'incrementquietly', 'decrementquietly',
                ], true)) {
                    throw new LogicException('learning_ledger.event_immutable');
                }

                return parent::__call($method, $parameters);
            }
        };
    }
}
