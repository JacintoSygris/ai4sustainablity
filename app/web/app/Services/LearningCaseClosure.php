<?php

namespace App\Services;

use App\Models\{Characterization, User, LearningCase, LearningCaseState, LearningCaseP5Snapshot, LearningCompanyMembership, LearningCompanyGroup, LearningAuthorizationRecord};
use App\Http\Controllers\Api\MaterialityConfirmationController;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use DomainException;

/** Local synthetic closure mechanism; default construction has no positive authority. */
final class LearningCaseClosure
{
    private array $context = [];
    private LearningAuthorizationLedger $ledger;
    private LearningCaseP5Storage $storage;
    private ApiCharacterizationGateway $gateway;
    private EsrsDatapointCorpusBuilder $builder;

    public static function forSyntheticTests(array $context, LearningAuthorizationLedger $ledger, LearningCaseP5Storage $storage, ApiCharacterizationGateway $gateway, EsrsDatapointCorpusBuilder $builder): self {
        $self = new self; $self->context=$context; $self->ledger=$ledger; $self->storage=$storage; $self->gateway=$gateway; $self->builder=$builder;
        return $self;
    }

    private function enabled(): bool {
        return app()->environment('testing') && config('services.learning_case_closure.enabled') === true
            && ($this->context['synthetic_only'] ?? null) === true && ($this->context['promotion_allowed'] ?? null) === false
            && is_string($this->context['namespace'] ?? null) && str_starts_with($this->context['namespace'],'test-namespace:');
    }

    private function connection(): \Illuminate\Database\Connection {
        if (! $this->enabled()) { throw new DomainException('learning_closure.disabled'); }
        foreach (['learning_source_clock','learning_p6_base_source_clock','learning_p8_source_clock','learning_p9_source_clock','learning_p5_live_capture','learning_p6_prepared_request','learning_p6_job_parent_fence','learning_p6_interpretation_context'] as $flag) {
            if (config('services.'.$flag.'.enabled') !== true) { throw new DomainException('learning_closure.disabled'); }
        }
        $c=DB::connection();
        foreach ([User::class,Characterization::class,LearningCase::class,LearningCaseState::class,LearningCaseP5Snapshot::class,LearningCompanyMembership::class,LearningCompanyGroup::class,LearningAuthorizationRecord::class,\App\Models\LearningAuthorizationState::class,\App\Models\CharacterizationDocument::class,\App\Models\EsrsTopic::class,\App\Models\NaceCode::class] as $class) {
            $mc=(new $class)->getConnection();
            if ($mc !== $c) { throw new DomainException('learning_closure.isolation_required'); }
        }
        if (! CharacterizationStateTransaction::admitsIsolatedConnections([$c])) { throw new DomainException('learning_closure.isolation_required'); }
        return $c;
    }
    public function assertAvailable(): void { $this->connection(); }

    private function membership(int $actor): array {
        $u=User::query()->whereKey($actor)->lockForUpdate()->first();
        if ($u===null || ! $u->hasVerifiedEmail()) { throw new DomainException('learning_closure.forbidden'); }
        $m=LearningCompanyMembership::query()->where('user_id',$actor)->where('subject_type','account')->where('subject_identifier',(string)$actor)
            ->where('verification_status','verified')->whereNotNull('verified_at')->whereNull('revoked_at')->lockForUpdate()->first();
        $g=$m===null ? null : LearningCompanyGroup::query()->whereKey($m->learning_company_group_id)->lockForUpdate()->first();
        if ($m===null || $g===null) { throw new DomainException('learning_closure.forbidden'); }
        return [$u,$m,$g];
    }

    private function frame(int $actor): array {
        $this->connection(); [$u,$m,$g]=$this->membership($actor);
        $source=Characterization::query()->where('user_id',$actor)->lockForUpdate()->first();
        if ($source===null) { throw new DomainException('learning_closure.source_missing'); }
        $copy=new Characterization; $raw=$source->getRawOriginal(); $copy->setRawAttributes($raw,true);
        $identity=LearningP6JobParentFence::identity($raw);
        if ($identity['user_id']!==$actor) { throw new DomainException('learning_closure.forbidden'); }
        if (($raw['status']??null)!==Characterization::STATUS_COMPLETED) { throw new DomainException('learning_closure.stale'); }
        $headers=[]; $clocks=['p5'=>new LearningP5SourceRevisionClock,'p6_base'=>new LearningP6BaseSourceRevisionClock,'p8'=>new LearningP8SourceRevisionClock,'p9'=>new LearningP9SourceRevisionClock];
        foreach($clocks as $k=>$clock) { $headers[$k]=$clock->current($identity['id']); if ($headers[$k]===null) { throw new DomainException('learning_closure.source_missing'); } }
        $p5=(new LearningP5Snapshot)->project($copy);
        if (in_array(null,$p5['values'],true)) { throw new DomainException('learning_closure.p5_missing'); }
        $p6=(new LearningP6Snapshot)->projectInputsForAccount($actor,array_intersect_key($headers,array_flip(['p5','p6_base'])),$this->gateway);
        $request=Request::create('/api/materiality-confirmation'); $request->setUserResolver(fn()=>$u);
        $p8=app(MaterialityConfirmationController::class)->show($request,$this->builder)->getData(true)['data'];
        if ($p8===null || $p8['is_confirmed']!==true || $p8['is_stale']!==false || !is_array($p8['learning_topic_labels']) || $p8['learning_topic_labels']===[]) { throw new DomainException('learning_closure.review_missing'); }
        $p9=app(EsrsDatapointResponseState::class)->projectLearningFeedbackForAccount($actor,$headers,$this->builder);
        if ($p9['learning_feedback']['reviewed_datapoint_ids']===[]) { throw new DomainException('learning_closure.review_missing'); }
        $rights=$this->ledger->current($actor,$g->id,$this->context['purpose']);
        if ($rights['status']!=='granted' || $rights['provenance']!=='synthetic-only' || $rights['promotion_allowed']!==false) { throw new DomainException('learning_closure.rights_denied'); }
        $record=LearningAuthorizationRecord::query()->where('event_digest',$rights['current_digest'])->firstOrFail();
        $witness=json_decode($record->payload_text,true,32,JSON_THROW_ON_ERROR)['witness'];
        $labels=[]; foreach($p8['learning_topic_labels'] as $id=>$value) { $labels[]=['topic_id'=>(string)$id,'value'=>$value,'observed_mask'=>1]; }
        $frame=['source_headers'=>$headers,'raw_digest'=>hash('sha256',$this->encode($raw)), 'p5'=>$p5,'p6'=>$p6,
            'topic_labels'=>$labels,'p8_confirmation'=>$p8['confirmation'],'p9'=>$p9,'rights'=>$rights,'witness'=>$witness,
            'membership'=>$m->getRawOriginal(),'group_key'=>$g->company_group_key,'group_id'=>$g->id,'context'=>$this->context];
        // Bounded composed witness, after all called producers/Common finalizers.
        foreach($clocks as $k=>$clock) {
            if ($clock->current($identity['id'])!==$headers[$k]) { throw new DomainException('learning_closure.stale'); }
            if ($k!=='p5' && $clock->finalizationWitness(fn()=>Characterization::query()->where('user_id',$actor)->first())['header']!==$headers[$k]) { throw new DomainException('learning_closure.stale'); }
        }
        [$fu,$fm,$fg]=$this->membership($actor);
        $fresh=Characterization::query()->where('user_id',$actor)->first();
        if ($fresh===null || $fresh->getRawOriginal()!==$raw || $fm->getRawOriginal()!==$frame['membership'] || $fg->company_group_key!==$frame['group_key']) { throw new DomainException('learning_closure.stale'); }
        $this->connection(); return $frame;
    }

    private function token(array $frame): string { return hash_hmac('sha256',$this->encode($frame),(string)config('app.key')); }
    private function encode(mixed $v): string { return json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_LINE_TERMINATORS|JSON_PRESERVE_ZERO_FRACTION); }
    private function safe(string $status): array { return ['schema_version'=>'learning-case-closure-v1','status'=>$status,'can_close'=>false,'can_withdraw'=>false,'expected_revisions'=>null,'expected_authorization_generation'=>null,'source_token'=>null,'draft'=>null,'case_hash'=>null,'receipt'=>null,'provenance'=>'synthetic-only','promotion_allowed'=>false]; }

    public function draft(int $actor): array {
        if (! $this->enabled()) { return $this->safe('disabled'); }
        $c=$this->connection();
        return $c->transaction(function()use($actor){
            $this->membership($actor); // Ownership before querying any case/receipt.
            $receipt=DB::table('learning_case_closure_receipts')->where('user_id',$actor)->orderByDesc('id')->first();
            $draft=DB::table('learning_case_closure_drafts')->where('user_id',$actor)->value('draft_text');
            $out=$this->safe('blocked'); $out['draft']=$draft===null ? null : json_decode($draft,true);
            if ($receipt!==null && LearningCaseState::query()->where('learning_case_id',$receipt->learning_case_id)->value('status')==='withdrawn') { $out['status']='withdrawn'; return $out; }
            try { $frame=$this->frame($actor); }
            catch (DomainException|\InvalidArgumentException $e) { $out['status']=$receipt===null ? 'blocked' : 'stale'; $out['can_withdraw']=$receipt!==null; $out['expected_authorization_generation']=$this->ledger->current($actor,$this->membership($actor)[2]->id,$this->context['purpose'])['generation']; return $out; }
            $out['expected_revisions']=$frame['source_headers']; $out['expected_authorization_generation']=$frame['rights']['generation']; $out['source_token']=$this->token($frame);
            $out['status']=$receipt===null ? 'ready' : ($receipt->source_token===$out['source_token'] ? 'closed' : 'stale');
            if ($out['status']==='closed') {
                try { $this->completed($actor,$receipt,$frame); }
                catch (DomainException|\InvalidArgumentException) { $out['status']='stale'; }
            }
            $out['can_close']=$receipt===null || $out['status']==='stale'; $out['can_withdraw']=$receipt!==null;
            if ($receipt!==null) { $out['case_hash']=LearningCase::query()->whereKey($receipt->learning_case_id)->value('case_hash'); $out['receipt']=json_decode($receipt->receipt_text,true); }
            return $out;
        });
    }

    private function cas(array $input,array $frame): void {
        if ($this->sorted($input['expected_revisions']??null)!==$this->sorted($frame['source_headers']) || ($input['expected_authorization_generation']??null)!==$frame['rights']['generation'] || ($input['source_token']??null)!==$this->token($frame)) { throw new DomainException('learning_closure.stale'); }
    }
    private function sorted(mixed $v): mixed { if(is_array($v)){if(!array_is_list($v)){ksort($v,SORT_STRING);}foreach($v as &$x){$x=$this->sorted($x);}} return $v; }

    public function saveDraft(int $actor,array $input): array {
        $c=$this->connection(); return $c->transaction(function()use($actor,$input){
            $frame=$this->frame($actor); $this->cas($input,$frame);
            DB::table('learning_case_closure_drafts')->updateOrInsert(['user_id'=>$actor],['draft_text'=>$this->encode($input)]);
            if ($this->frame($actor)!==$frame) { throw new DomainException('learning_closure.stale'); }
            return $this->draft($actor);
        });
    }

    public function close(int $actor,array $input): array {
        $c=$this->connection(); $pdo=$c->getPdo();
        return $c->transaction(function()use($actor,$input,$c,$pdo){
            $frame=null;
            $out=app(CharacterizationStateTransaction::class)->runForUser($actor,function()use($actor,$input,&$frame){
                $frame=$this->frame($actor); $this->cas($input,$frame);
                if (($input['reviewed_universe']??null)!==true || ($input['final_for_period_scope']??null)!==true || ($input['declaration_version']??null)!=='local-synthetic-closure-v1' || !is_string($input['idempotency_key']??null) || preg_match('/\A[A-Za-z0-9:._-]{1,128}\z/D',$input['idempotency_key'])!==1) { throw new DomainException('learning_closure.review_missing'); }
                $intent=hash('sha256',$this->encode($this->sorted($input)));
                $existing=DB::table('learning_case_closure_receipts')->where('user_id',$actor)->where('idempotency_key',$input['idempotency_key'])->lockForUpdate()->first();
                if ($existing!==null) {
                    if ($existing->intent_digest!==$intent || $existing->source_token!==$this->token($frame)) { throw new DomainException('learning_closure.idempotency_conflict'); }
                    return $this->completed($actor,$existing,$frame);
                }
                $payload=$this->payload($actor,$frame);
                $payload['case_hash']=LearningCaseContract::learningCaseDigest($this->encode($payload));
                $case=$this->storage->captureForAccount($actor,$frame['group_id'],$this->context['purpose'],$this->encode($payload),$this->encode($this->context['authority']));
                if (DB::table('learning_case_closure_receipts')->where('learning_case_id',$case->id)->exists() || $case->state->status!=='stored' || $case->state->state_version!==0) { throw new DomainException('learning_closure.legacy_conflict'); }
                $receipt=['schema_version'=>'learning-case-closure-receipt-v1','case_id'=>$case->case_id,'case_hash'=>$case->case_hash,'source_token'=>$this->token($frame),
                    'p5_completion_reference'=>DB::table('learning_case_p5_storage_receipts')->where('learning_case_id',$case->id)->value('completion_reference'),
                    'actor_id'=>$actor,'authorization_generation'=>$frame['rights']['generation'],'authorization_digest'=>$frame['rights']['current_digest'],
                    'recorded_at'=>$payload['closure_evidence']['recorded_at'],'provenance'=>'synthetic-only','promotion_allowed'=>false];
                $text=$this->encode($receipt); $receipt['receipt_hash']=hash('sha256',$text);
                DB::table('learning_case_closure_receipts')->insert(['user_id'=>$actor,'learning_case_id'=>$case->id,'idempotency_key'=>$input['idempotency_key'],'intent_digest'=>$intent,'source_token'=>$this->token($frame),'source_text'=>$this->encode($frame),'receipt_text'=>$this->encode($receipt),'receipt_hash'=>$receipt['receipt_hash'],'created_at'=>now()]);
                app(LearningCaseSnapshot::class)->updateOperationalStateForUser($actor,$case->id,'closed');
                return $this->completed($actor,DB::table('learning_case_closure_receipts')->where('learning_case_id',$case->id)->first(),$frame);
            });
            // AFTER Common, BEFORE outer commit, on the same PDO. No unbounded SELECT loop.
            if ($this->connection()!==$c || $c->getPdo()!==$pdo || $this->frame($actor)!==$frame) { throw new DomainException('learning_closure.stale'); }
            $receipt=DB::table('learning_case_closure_receipts')->where('user_id',$actor)->where('idempotency_key',$input['idempotency_key'])->lockForUpdate()->first();
            if ($receipt===null || $receipt->intent_digest!==hash('sha256',$this->encode($this->sorted($input)))) { throw new DomainException('learning_closure.receipt_invalid'); }
            $completed=$this->completed($actor,$receipt,$frame);
            if ($completed!==$out) { throw new DomainException('learning_closure.receipt_invalid'); }
            return $completed;
        });
    }

    private function completed(int $actor,?object $row,array $frame): array {
        if ($row===null) { throw new DomainException('learning_closure.receipt_invalid'); }
        try { $case=app(LearningCaseSnapshot::class)->findForUser($actor,$row->learning_case_id); }
        catch (\InvalidArgumentException $e) { throw new DomainException('learning_closure.receipt_invalid',0,$e); }
        $p5=LearningCaseP5Snapshot::query()->where('learning_case_id',$case->id)->first();
        $p5Receipt=DB::table('learning_case_p5_storage_receipts')->where('learning_case_id',$case->id)->first();
        try { $receipt=json_decode($row->receipt_text,true,32,JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { throw new DomainException('learning_closure.receipt_invalid',0,$e); }
        if (!is_array($receipt)) { throw new DomainException('learning_closure.receipt_invalid'); }
        $hash=$receipt['receipt_hash']??null; unset($receipt['receipt_hash']);
        if ($case->state?->status!=='closed' || $p5===null || $p5Receipt===null || $p5->digest!==$frame['p5']['digest'] || $p5->values_text!==$this->encode($frame['p5']['values'])
            || $p5->actor_id!==$actor || $p5->group_id!==$frame['group_id'] || $p5->purpose!==$this->context['purpose']
            || $p5->authorization_generation!==$frame['rights']['generation'] || $p5->authorization_digest!==$frame['rights']['current_digest']
            || $p5->schema_version!==$frame['p5']['schema_version'] || $p5->feature_schema_version!==LearningP5Snapshot::FEATURE_SCHEMA_VERSION || $p5->transform_version!==LearningP5Snapshot::TRANSFORM_VERSION
            || $p5Receipt->source_header_text!==$this->encode($frame['source_headers']['p5'])
            || $p5Receipt->snapshot_id!==$p5->id || $p5Receipt->completion_reference!==($receipt['p5_completion_reference']??null)
            || $row->source_text!==$this->encode($frame) || $row->source_token!==$this->token($frame) || $hash!==$row->receipt_hash || hash('sha256',$this->encode($receipt))!==$hash || ($receipt['case_hash']??null)!==$case->case_hash) { throw new DomainException('learning_closure.receipt_invalid'); }
        (new LearningCaseContract)->assertLearningCaseHash($case->payload_text);
        return ['schema_version'=>'learning-case-closure-v1','status'=>'closed','case_hash'=>$case->case_hash,'receipt'=>$receipt+['receipt_hash'=>$hash],'provenance'=>'synthetic-only','promotion_allowed'=>false];
    }

    private function payload(int $actor,array $f): array {
        $revisions=[]; foreach(['p5'=>'p5','p6'=>'p6_base','p8'=>'p8','p9'=>'p9'] as $dest=>$src){$revisions[$dest]=array_intersect_key($f['source_headers'][$src],array_flip(['generation','revision','digest']));}
        $revisions['p6']['digest']=hash('sha256',$this->encode([$f['source_headers']['p6_base'],$f['p6'],$f['context']]));
        $reviewed=array_column($f['topic_labels'],'topic_id'); $dp=$f['p9']['learning_feedback']; $a=$this->context['authority'];
        return ['schema_version'=>'learning-case-v1','case_id'=>'synthetic-close:'.$this->token($f),'case_hash'=>str_repeat('0',64),'company_group_key'=>$f['group_key'],
            'period_scope'=>$this->context['period_scope'],'authority'=>array_intersect_key($a,array_flip(['framework_version','catalog_version','catalog_digest','mapping_version','mapping_digest'])),
            'provenance'=>['source_kind'=>'human_product','source_record_digest'=>hash('sha256',$this->encode([$this->context['namespace'],$actor,$f['source_headers']])),'source_revision'=>$this->token($f)],
            'source_revisions'=>$revisions,'p5_snapshot'=>array_intersect_key($f['p5'],array_flip(['schema_version','digest'])),'p6_snapshot'=>$f['p6']['projection']['p6_snapshot'],
            'topic_universe'=>['reviewed_topic_ids'=>$reviewed,'outside_scope_topic_ids'=>array_values(array_diff($a['topic_ids'],$reviewed))],'topic_labels'=>$f['topic_labels'],
            'datapoint_universe'=>['reviewed_datapoint_ids'=>$dp['reviewed_datapoint_ids'],'outside_scope_datapoint_ids'=>array_values(array_diff($a['datapoint_ids'],$dp['reviewed_datapoint_ids']))],'datapoint_decisions'=>$dp['decisions'],
            // Closed legacy contract vector ONLY in the explicit synthetic namespace, never a real legal approval.
            'rights'=>['policy_version'=>$f['witness']['policy_version'],'policy_digest'=>$f['witness']['policy_digest'],'policy_status'=>'approved','state'=>'granted','authorization_generation'=>$f['rights']['generation']],
            'closure_evidence'=>['declaration_version'=>'local-synthetic-closure-v1','declaration_status'=>'accepted','reviewed_universe'=>true,'final_for_period_scope'=>true,'server_actor_id'=>(string)$actor,'recorded_at'=>now()->utc()->format('Y-m-d\TH:i:s.u\Z')]];
    }

    public function withdraw(int $actor,array $input): array {
        $c=$this->connection(); $pdo=$c->getPdo();
        return $c->transaction(function()use($actor,$input,$c,$pdo){
            $expected=null; $caseIds=[]; $group=null;
            app(CharacterizationStateTransaction::class)->runForUser($actor,function()use($actor,$input,&$expected,&$caseIds,&$group){
                [,,$g]=$this->membership($actor); $group=$g->id;
                $rights=$this->ledger->current($actor,$group,$this->context['purpose']);
                if (($input['expected_authorization_generation']??null)!==$rights['generation'] || !is_string($input['idempotency_key']??null) || preg_match('/\A[A-Za-z0-9:._-]{1,128}\z/D',$input['idempotency_key'])!==1) { throw new DomainException('learning_closure.stale'); }
                $command=hash('sha256',$this->encode(['withdraw',$actor,$group,$input]));
                if ($rights['stored_status']==='granted') { $rights=$this->ledger->revoke($actor,$group,$this->context['purpose'],$rights['generation'],$command); }
                if (in_array($rights['stored_status'],['granted','revoked'],true)) { $rights=$this->ledger->tombstone($actor,$group,$this->context['purpose'],$rights['generation'],hash('sha256','tombstone:'.$command)); }
                if ($rights['stored_status']!=='deleted') { throw new DomainException('learning_closure.rights_denied'); }
                $caseIds=DB::table('learning_case_closure_receipts')->where('user_id',$actor)->pluck('learning_case_id')->all();
                foreach($caseIds as $id) { if (LearningCaseState::query()->where('learning_case_id',$id)->value('status')!=='withdrawn') { app(LearningCaseSnapshot::class)->updateOperationalStateForUser($actor,$id,'withdrawn'); } }
                $expected=$this->ledger->current($actor,$group,$this->context['purpose']);
            });
            $this->membership($actor);
            if ($this->connection()!==$c || $c->getPdo()!==$pdo || $this->ledger->current($actor,$group,$this->context['purpose'])!==$expected) { throw new DomainException('learning_closure.stale'); }
            foreach($caseIds as $id) { if(LearningCaseState::query()->where('learning_case_id',$id)->value('status')!=='withdrawn'){throw new DomainException('learning_closure.stale');} }
            return $this->safe('withdrawn');
        });
    }
}
