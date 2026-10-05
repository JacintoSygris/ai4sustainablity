<?php
namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class LearningBatch extends Model
{
    public $timestamps=false;
    protected $guarded=[];
    protected function casts(): array { return ['fence'=>'integer','lease_until'=>'integer','heartbeat_at'=>'integer','issuer_state'=>'array','dataset_state'=>'array','receipt'=>'array']; }

    public static function admit(): void {
        if (!app()->environment('testing') || config('services.learning_batch.enabled')!==true
            || config('services.learning_batch.namespace')!=='test-namespace:t07-export'
            || config('services.learning_batch.synthetic_only')!==true || config('services.learning_batch.trusted_launcher')!==true) {
            throw new DomainException('learning_batch.disabled');
        }
        $c=DB::connection();
        if (! \App\Services\CharacterizationStateTransaction::admitsIsolatedConnections([$c])) { throw new DomainException('learning_batch.isolation_required'); }
        if ($c->getDriverName() !== 'sqlite') { return; }
        $pdo=$c->getPdo();
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)!=='sqlite') { throw new DomainException('learning_batch.isolation_required'); }
        $databases=$pdo->query('PRAGMA database_list')->fetchAll(\PDO::FETCH_ASSOC);
        if (!$databases || $databases[0]['name']!=='main' || array_filter($databases,fn($d)=>!in_array($d['name'],['main','temp'],true) || $d['file']!=='')) {
            throw new DomainException('learning_batch.isolation_required');
        }

    }
    public static function assertStored(self $row): void {
        foreach (['fence','lease_until','heartbeat_at'] as $key) {
            $value=$row->getRawOriginal($key);
            if ($key==='lease_until' && $value===null && $row->status!=='running') { continue; }
            if (!is_int($value) || $value<1 || $value>9007199254740991) { throw new DomainException('learning_batch.counter_invalid'); }
        }
        if ($row->issuer_state!==null) {
            $generation=$row->issuer_state['generation'] ?? null;
            if (!is_int($generation) || $generation<0 || $generation>9007199254740991) { throw new DomainException('learning_batch.counter_invalid'); }
        }
    }
    public static function claim(): ?array {
        self::admit();
        return DB::transaction(function () {
            $row=self::query()->lockForUpdate()->find(1);
            if ($row) { self::assertStored($row); }
            $now=now()->getTimestamp();
            if ($row && $row->status==='running' && $row->lease_until>$now) { return null; }
            if (!$row) { $row=new self(['id'=>1,'fence'=>0]); }
            if ($row->fence>=9007199254740991) { throw new DomainException('learning_batch.fence_exhausted'); }
            if ($row->issuer_state!==null) { $cursor=$row->issuer_state; unset($cursor['dataset_witness']); $row->issuer_state=$cursor; }
            $row->fence++; $row->batch_id=bin2hex(random_bytes(16)); $row->status='running';
            $row->lease_until=$now+60; $row->heartbeat_at=$now; $row->receipt=null; $row->context_digest=null; $row->save();
            return ['batch_id'=>$row->batch_id,'fence'=>$row->fence];
        });
    }
    public static function assertToken(array $token): void {
        self::admit();
        if (array_keys($token)!==['batch_id','fence'] || !is_string($token['batch_id']) || !preg_match('/\A[a-f0-9]{32}\z/',$token['batch_id'])
            || !is_int($token['fence']) || $token['fence']<1 || $token['fence']>9007199254740991) { throw new DomainException('learning_batch.token_invalid'); }
    }
    public static function locked(array $token, callable $work): mixed {
        self::assertToken($token);
        return DB::transaction(function () use ($token,$work) {
            $row=self::query()->lockForUpdate()->find(1);
            if ($row) { self::assertStored($row); }
            if (!$row || $row->batch_id!==$token['batch_id'] || $row->fence!==$token['fence'] || $row->status!=='running'
                || $row->lease_until<=now()->getTimestamp()) { throw new DomainException('learning_batch.stale_owner'); }
            return $work($row);
        });
    }
    public static function heartbeat(array $token): void {
        self::locked($token,function ($row) { $row->heartbeat_at=now()->getTimestamp(); $row->lease_until=$row->heartbeat_at+60; $row->save(); });
    }
    public static function abort(array $token): void {
        self::locked($token,function ($row) { $row->status='aborted'; $row->lease_until=null; $row->save(); });
    }
}
