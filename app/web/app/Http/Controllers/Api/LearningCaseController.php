<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LearningCaseClosure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use DomainException;
use InvalidArgumentException;

final class LearningCaseController extends Controller
{
    public function show(Request $r,LearningCaseClosure $closure) { return $this->respond(fn()=>$closure->draft($this->actor($r))); }
    public function update(Request $r,LearningCaseClosure $closure) { return $this->command($r,$closure,'saveDraft'); }
    public function close(Request $r,LearningCaseClosure $closure) { return $this->command($r,$closure,'close'); }
    public function withdraw(Request $r,LearningCaseClosure $closure) { return $this->command($r,$closure,'withdraw'); }
    private function actor(Request $r): int { $id=$r->user()?->getAuthIdentifier(); if(!is_int($id)||$id<1){abort(401);}return $id; }
    private function respond(\Closure $operation) {
        try { return response()->json(['data'=>$operation()]); }
        catch(DomainException|InvalidArgumentException $e) {
            $forbidden=in_array($e->getMessage(),['learning_closure.disabled','learning_closure.isolation_required','learning_closure.forbidden','learning_closure.rights_denied'],true);
            return response()->json(['code'=>$forbidden?'learning_case_blocked':'learning_case_conflict','message'=>$forbidden?'Mecanismo local no disponible.':'El estado cambió. Relea antes de decidir; no reenvíe automáticamente.'], $forbidden?403:409);
        }
    }
    private function command(Request $r,LearningCaseClosure $closure,string $method) {
        $actor=$this->actor($r);
        return $this->respond(function()use($r,$closure,$method,$actor){
            $closure->assertAvailable();
            $raw=$r->getContent(); if(strlen($raw)>65536){abort(413);}
            try { $obj=json_decode($raw,false,32,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING); }
            catch(\JsonException){throw ValidationException::withMessages(['json'=>'JSON inválido.']);}
            if(!$obj instanceof \stdClass){throw ValidationException::withMessages(['json'=>'Se requiere un objeto JSON.']);}
            $this->duplicates($raw);
            $keys=$method==='withdraw'?['expected_authorization_generation','idempotency_key']:['expected_revisions','expected_authorization_generation','source_token','idempotency_key','reviewed_universe','final_for_period_scope','declaration_version'];
            $actual=array_keys(get_object_vars($obj)); sort($actual);sort($keys);
            if($actual!==$keys){throw ValidationException::withMessages(['json'=>'Campos cerrados: no se admiten extras ni campos ausentes.']);}
            if($method!=='withdraw') {
                if(!($obj->expected_revisions instanceof \stdClass)){throw ValidationException::withMessages(['expected_revisions'=>'Se requiere la tupla completa.']);}
                $parents=array_keys(get_object_vars($obj->expected_revisions));sort($parents);
                if($parents!==['p5','p6_base','p8','p9']){throw ValidationException::withMessages(['expected_revisions'=>'Se requiere la tupla completa.']);}
                foreach($obj->expected_revisions as $header){if(!$header instanceof \stdClass){throw ValidationException::withMessages(['expected_revisions'=>'Cabecera inválida.']);}}
            }
            $input=json_decode($raw,true,32,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
            $rules=['expected_authorization_generation'=>['required','integer:strict','min:0','max:9007199254740991'],'idempotency_key'=>['required','string','regex:/\A[A-Za-z0-9:._-]{1,128}\z/D']];
            if($method!=='withdraw'){$rules+=['source_token'=>['required','string','regex:/\A[a-f0-9]{64}\z/D'],'reviewed_universe'=>['required','boolean:strict'],'final_for_period_scope'=>['required','boolean:strict'],'declaration_version'=>['required','in:local-synthetic-closure-v1'],'expected_revisions.*'=>['required','array:characterization_id,generation,revision,epoch,digest'],'expected_revisions.*.characterization_id'=>['required','integer:strict','min:1','max:9007199254740991'],'expected_revisions.*.generation'=>['required','integer:strict','min:0','max:9007199254740991'],'expected_revisions.*.revision'=>['required','integer:strict','min:0','max:9007199254740991'],'expected_revisions.*.epoch'=>['required','string','regex:/\A[a-f0-9]{64}\z/D'],'expected_revisions.*.digest'=>['required','string','regex:/\A[a-f0-9]{64}\z/D']];}
            \Illuminate\Support\Facades\Validator::make($input,$rules)->validate();
            return $closure->{$method}($actor,$input);
        });
    }
    /** Valid JSON already parsed. Track decoded member names per object scope, including escaped aliases. */
    private function duplicates(string $raw): void {
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\[\],:]|[^\s{}\[\],:]+/s',$raw,$matches);
        $tokens=$matches[0];$stack=[];
        foreach($tokens as $i=>$token){
            if($token==='{'||$token==='['){$stack[]=['object'=>$token==='{','seen'=>[]];}
            elseif($token==='}'||$token===']'){array_pop($stack);}
            elseif(str_starts_with($token,'"')&&($tokens[$i+1]??null)===':'){
                $last=count($stack)-1;$key="\0".json_decode($token,true,32,JSON_THROW_ON_ERROR);
                if(isset($stack[$last]['seen'][$key])){throw ValidationException::withMessages(['json'=>'Miembro JSON duplicado.']);}
                $stack[$last]['seen'][$key]=true;
            }
        }
    }
}
