import test from "node:test"
import assert from "node:assert/strict"
import { compactDrafts } from "../lib/esrs-datapoints-state.mjs"
import * as state from "../lib/esrs-datapoints-state.mjs"
test("explicit false choices survive draft compact and JSON recovery without becoming completed",()=>{
 const review={relevant:false,selected_to_answer:false,reason_codes:["other"],note:"Synthetic"}
 const drafts=JSON.parse(JSON.stringify({"BP-1_01":{...state.emptyDraft(),learning_review:review}}))
 assert.deepEqual(compactDrafts(drafts)[0]?.learning_review,review)
 assert.equal(compactDrafts(drafts)[0]?.status,"draft")
})
test("missing binary choices and default selection stay unobserved",()=>{
 assert.deepEqual(state.datapointFeedbackPacket({A:{...state.emptyDraft(),learning_review:{relevant:false}}},"a".repeat(64)).reviewed_datapoint_ids,[])
 assert.deepEqual(state.datapointFeedbackPacket({A:state.emptyDraft()},"a".repeat(64)).decisions,[])
})

test("hydration, current recovery and stale authority retain explanation without invented negatives",()=>{
 const feedback={decisions:[{datapoint_id:"A", relevant:false,selected_to_answer:false,reason_codes:["other"],note:"Synthetic"}]}
 const server=state.hydrateDatapointDrafts({},feedback)
 assert.equal(server.A.learning_review.relevant,false)
 const local={A:{...server.A, value:""},UNKNOWN:server.A}
 const fresh=state.mergeDatapointRecovery(server,local,["A"],"x","x")
 assert.equal(state.datapointFeedbackPacket(fresh,"x").decisions[0].selected_to_answer,false)
 assert.equal(fresh.UNKNOWN,undefined)
 const stale=state.mergeDatapointRecovery(server,local,["A"],"old","new")
 assert.deepEqual(state.datapointFeedbackPacket(stale,"new").decisions,[])
 assert.equal(stale.A.learning_review.note,"Synthetic")
})

test("unanswered review explanations require keeping the optional recovery mirror",()=>{
 assert.equal(state.hasUnansweredDatapointReview({A:{learning_review:{relevant:false,note:"Synthetic"}}}),true)
 assert.equal(state.hasUnansweredDatapointReview({A:{learning_review:{relevant:false,selected_to_answer:false}}}),false)
 assert.equal(state.hasUnansweredDatapointReview({A:state.emptyDraft()}),false)
})

import fs from "node:fs"
import vm from "node:vm"
import { createRequire } from "node:module"
const ts = createRequire(import.meta.url)("typescript")
function actualSource(name) {
 const tree=ts.createSourceFile("form.tsx",fs.readFileSync(new URL("../components/wizard/esrs-datapoints-form.tsx",import.meta.url),"utf8"),ts.ScriptTarget.Latest,true,ts.ScriptKind.TSX)
 let found; function visit(n){if((ts.isFunctionDeclaration(n)||ts.isVariableDeclaration(n))&&n.name?.getText(tree)===name)found=n;ts.forEachChild(n,visit)}visit(tree)
 assert.ok(found, name)
 return ts.transpileModule((ts.isVariableDeclaration(found)?"const ":"")+found.getText(tree),{compilerOptions:{target:ts.ScriptTarget.ES2022}}).outputText
}
function recoveryHarness() {
 const timers=[], cancelled=[], mirror=new Map(), sent=[]
 const c=vm.createContext({...state,window:{},mounted:true,rows:[],characterizationId:1,csrfToken:"synthetic",pendingRecoveryDrafts:null,
 loadGenerationRef:{current:0},workspaceContextRef:{current:null},recoveryTimerRef:{current:null},autoSaveTimerRef:{current:null},
 learningAuthorityRef:{current:""},draftsRef:{current:{}},dirtyRef:{current:false},editVersionRef:{current:0},saveQueueRef:{current:state.createResponseSaveQueue()},
 recoveryStorage:{getItem:k=>mirror.get(k),setItem:(k,v)=>mirror.set(k,v),removeItem:k=>mirror.delete(k)},preferenceStorage:{getItem:()=>null},
 setTimeout(fn){timers.push(fn);return timers.length},clearTimeout(id){cancelled.push(id)},scheduleAutoSave(){c.scheduled=(c.scheduled??0)+1},
 getLaravelSession:async()=>({data:{csrf_token:"synthetic"}}),getLaravelEsrsDatapointWorkspace:async()=>c.snapshot,
 updateLaravelEsrsDatapointResponses:async payload=>{sent.push(payload);return {data:{revision:3}}},router:{replace(){}},LaravelApiError:class extends Error{}})
 for(const name of ["Corpus","LoadingInitial","ErrorMessage","CsrfToken","CharacterizationId","ServerUpdatedAt","Orphaned","Drafts","ShowIntro","PendingRecoveryDrafts","ShowRecoveryPrompt","RecoveryIsConflict","IsDirty","AutoSaveError"])
 c["set"+name]=v=>{c[name[0].toLowerCase()+name.slice(1)]=v}
 // Install only real source declarations; missing new dependencies are never swallowed.
 const tree=ts.createSourceFile("form.tsx",fs.readFileSync(new URL("../components/wizard/esrs-datapoints-form.tsx",import.meta.url),"utf8"),ts.ScriptTarget.Latest,true,ts.ScriptKind.TSX)
 for(const name of ["invalidateWorkspace","clearPendingRecovery","isCurrentRecoveryContext"]){let exists=false;function visit(n){if(ts.isFunctionDeclaration(n)&&n.name?.text===name)exists=true;ts.forEachChild(n,visit)}visit(tree);if(exists)vm.runInContext(actualSource(name),c)}
 for(const name of ["clearLocalMirror","checkRecovery","enqueueCurrentSave","updateDrafts","reload"] )vm.runInContext(actualSource(name),c)
 c.setReloadCounter=()=>{}
 return {c,timers,cancelled,mirror,sent,
 async load(snapshot){c.snapshot=snapshot;await vm.runInContext(actualCallback("loadP9"),c);c.rows=state.flattenCorpus(c.corpus)},
 accept(pending=c.pendingRecoveryDrafts){return vm.runInContext("(()=>{const pendingRecoveryDrafts=__pending;"+actualSource("acceptRecovery")+";acceptRecovery()})()",Object.assign(c,{__pending:pending}))},
 decline(pending=c.pendingRecoveryDrafts){return vm.runInContext("(()=>{const pendingRecoveryDrafts=__pending;"+actualSource("declineRecovery")+";declineRecovery()})()",Object.assign(c,{__pending:pending}))}}
}
function recoveryMirror(h,authority=authorityA) {
 const draft={...state.emptyDraft(),value:"Synthetic value",note:"Synthetic response note",triage:"need_to_find",learning_review:{relevant:false,selected_to_answer:false,reason_codes:["other"],note:"Synthetic explanation"}}
 h.mirror.set(state.localStorageDraftKey(1),JSON.stringify({authority_digest:authority,drafts:{SHARED:draft},savedAt:Date.now()}))
}
function withoutFeedback(authority=authorityA){const s=workspace(authority);s.response_state.learning_feedback={...s.response_state.learning_feedback,reviewed_datapoint_ids:[],decisions:[]};return s}

test("actual pending A recovery cannot cross B reload or stale accept/decline closure",async()=>{
 const h=recoveryHarness();recoveryMirror(h);await h.load(withoutFeedback());h.timers[0]();const stale=h.c.pendingRecoveryDrafts;assert.ok(stale)
 const originalMirror=h.mirror.get(state.localStorageDraftKey(1));h.mirror.clear();await h.load(withoutFeedback(authorityB))
 assert.equal(h.c.showRecoveryPrompt,false);assert.equal(h.c.pendingRecoveryDrafts,null)
 h.mirror.set(state.localStorageDraftKey(1),originalMirror);h.accept(stale);h.decline(stale)
 assert.deepEqual(state.datapointFeedbackPacket(h.c.draftsRef.current,authorityB).decisions,[])
 assert.equal(h.c.dirtyRef.current,false);assert.equal(h.c.scheduled,undefined)
 assert.equal(h.mirror.get(state.localStorageDraftKey(1)),originalMirror)
})
test("actual obsolete recovery timer cannot publish after reload even if cancellation loses race",async()=>{
 const h=recoveryHarness();recoveryMirror(h);await h.load(withoutFeedback());const obsolete=h.timers[0];await h.load(withoutFeedback(authorityB));obsolete()
 assert.equal(h.c.showRecoveryPrompt,false);assert.equal(h.c.pendingRecoveryDrafts,null);assert.ok(h.cancelled.includes(1))
})
test("actual same-authority reload still invalidates the previous generation and timer ref",async()=>{
 const h=recoveryHarness();recoveryMirror(h);await h.load(withoutFeedback());const obsolete=h.timers[0];obsolete();const stale=h.c.pendingRecoveryDrafts;const generation=h.c.loadGenerationRef.current
 assert.equal(h.c.recoveryTimerRef.current,null);await h.load(withoutFeedback());assert.ok(h.c.loadGenerationRef.current>generation)
 obsolete();h.accept(stale);assert.equal(h.c.showRecoveryPrompt,false);assert.equal(h.c.dirtyRef.current,false)
 h.timers[1]();assert.equal(h.c.showRecoveryPrompt,true);h.accept();assert.equal(h.c.dirtyRef.current,true)
})
test("actual superseded async load cannot replace B or clear its loading/error state",async()=>{
 const h=recoveryHarness();let resolveA;h.c.getLaravelEsrsDatapointWorkspace=()=>new Promise(resolve=>{resolveA=resolve})
 const pending=vm.runInContext(actualCallback("loadP9"),h.c)
 h.c.getLaravelEsrsDatapointWorkspace=async()=>withoutFeedback(authorityB);await h.load(withoutFeedback(authorityB));const context=h.c.workspaceContextRef.current
 resolveA(withoutFeedback());await pending
 assert.equal(h.c.workspaceContextRef.current,context);assert.equal(h.c.learningAuthorityRef.current,authorityB);assert.equal(h.timers.length,1);assert.equal(h.c.errorMessage,null)
})
test("actual load effect cleanup cancels recovery and queued callback after unmount",async()=>{
 const h=recoveryHarness();recoveryMirror(h);h.c.snapshot=withoutFeedback()
 const tree=ts.createSourceFile("form.tsx",fs.readFileSync(new URL("../components/wizard/esrs-datapoints-form.tsx",import.meta.url),"utf8"),ts.ScriptTarget.Latest,true,ts.ScriptKind.TSX)
 let effect;function visit(n){if(ts.isCallExpression(n)&&n.expression.getText(tree)==="useEffect"&&n.arguments[0]?.getText(tree).includes("async function loadP9"))effect=n.arguments[0];ts.forEachChild(n,visit)}visit(tree);assert.ok(effect)
 const cleanup=vm.runInContext(ts.transpileModule("("+effect.getText(tree)+")()",{compilerOptions:{target:ts.ScriptTarget.ES2022}}).outputText,h.c)
 for(let i=0;i<8;i++)await Promise.resolve();assert.equal(h.timers.length,1);cleanup();h.timers[0]()
 assert.notEqual(h.c.showRecoveryPrompt,true);assert.equal(h.c.pendingRecoveryDrafts,null);assert.ok(h.cancelled.includes(1));assert.equal(h.c.workspaceContextRef.current,null)
})
test("actual recovery is inert during reload, invalid publication and characterization mismatch",async()=>{
 for(const mode of ["reload","missing","unsupported","mismatch","characterization"]){
 const h=recoveryHarness();recoveryMirror(h);await h.load(withoutFeedback());h.timers[0]();const stale=h.c.pendingRecoveryDrafts
 if(mode==="reload")vm.runInContext("reload()",h.c)
 else if(mode==="characterization")h.c.workspaceContextRef.current={...h.c.workspaceContextRef.current,characterizationId:2}
 else {const s=mode==="missing"?{}:withoutFeedback();if(mode==="unsupported")s.snapshot_version="unsupported";if(mode==="mismatch")s.response_state=workspace(authorityB).response_state;await h.load(s)}
 h.accept(stale);assert.equal(h.c.dirtyRef.current,false,mode);assert.equal(h.c.scheduled,undefined,mode)
 if(mode!=="characterization"){assert.throws(()=>vm.runInContext("enqueueCurrentSave()",h.c));const before=h.c.draftsRef.current;vm.runInContext("updateDrafts(['SHARED'],{value:'blocked'})",h.c);assert.equal(h.c.draftsRef.current,before)}
 }
})
test("actual same-context recovery keeps origin and explicit false false through real save",async()=>{
 const h=recoveryHarness();recoveryMirror(h);await h.load(withoutFeedback());h.timers[0]();const pending=h.c.pendingRecoveryDrafts
 assert.equal(pending.originAuthority,authorityA);assert.equal(pending.context.characterizationId,1);assert.equal(pending.context.generation,h.c.loadGenerationRef.current)
 h.accept();await vm.runInContext("enqueueCurrentSave()",h.c)
 assert.equal(h.sent.length,1);assert.equal(h.sent[0].learning_feedback.authority_digest,authorityA);assert.equal(h.sent[0].learning_feedback.decisions[0].relevant,false);assert.equal(h.sent[0].learning_feedback.decisions[0].selected_to_answer,false)
 assert.equal(h.c.draftsRef.current.SHARED.value,"Synthetic value");assert.equal(h.c.draftsRef.current.SHARED.triage,"need_to_find")
})
test("actual drift recovery retains explanations and mirror without fabricating negatives",async()=>{
 const h=recoveryHarness();recoveryMirror(h);const raw=h.mirror.get(state.localStorageDraftKey(1));await h.load(withoutFeedback(authorityB));h.timers[0]();h.accept()
 const d=h.c.draftsRef.current.SHARED;assert.equal(d.learning_review.note,"Synthetic explanation");assert.deepEqual(Array.from(d.learning_review.reason_codes),["other"])
 assert.equal(d.learning_review.relevant,undefined);assert.equal(d.learning_review.selected_to_answer,undefined);assert.equal(d.note,"Synthetic response note");assert.equal(d.status,"draft");assert.equal(d.value,"Synthetic value");assert.equal(d.triage,"need_to_find")
 assert.deepEqual(state.datapointFeedbackPacket(h.c.draftsRef.current,authorityB).decisions,[]);assert.equal(h.mirror.get(state.localStorageDraftKey(1)),raw)
})
function actualCallback(name) {
 const tree=ts.createSourceFile("form.tsx",fs.readFileSync(new URL("../components/wizard/esrs-datapoints-form.tsx",import.meta.url),"utf8"),ts.ScriptTarget.Latest,true,ts.ScriptKind.TSX)
 let found; function visit(n){if(ts.isFunctionDeclaration(n)&&n.name?.text===name)found=n;ts.forEachChild(n,visit)}visit(tree)
 assert.ok(found);return ts.transpileModule(found.getText(tree)+"\n"+name+"()",{compilerOptions:{target:ts.ScriptTarget.ES2022}}).outputText
}
const authorityA="a".repeat(64), authorityB="b".repeat(64)
function workspace(authority=authorityA) {
 const feedback={schema_version:"datapoint-feedback-v1",authority_digest:authority,reviewed_datapoint_ids:["SHARED"],decisions:[{datapoint_id:"SHARED",relevant:false,selected_to_answer:false,reason_codes:[],note:"Synthetic"}]}
 return {snapshot_version:"p9-workspace-v1",data:{characterization_id:1,learning_authority_digest:authority,mapping_snapshot_digest:authority,blocks:{always_required:{datapoints:[{id:"SHARED",context:authority}]}}},response_state:{characterization_id:1,revision:2,source_digest:authority,learning_authority_digest:authority,responses:{},learning_feedback:feedback}}
}
async function loadActual(snapshot) {
 const observed={};const a=workspace(),b=workspace(authorityB)
 const context={...state,mounted:true,window:undefined,setTimeout(){},clearTimeout(){},
 loadGenerationRef:{current:0},workspaceContextRef:{current:null},recoveryTimerRef:{current:null},
 saveQueueRef:{current:state.createResponseSaveQueue()},autoSaveTimerRef:{current:null},learningAuthorityRef:{current:""},editVersionRef:{current:0},draftsRef:{current:{}},
 getLaravelSession:async()=>({data:{csrf_token:"synthetic"}}),
 getLaravelEsrsDatapoints:async()=>({data:a.data}),getLaravelEsrsDatapointResponses:async()=>({data:b.response_state}),
 getLaravelEsrsDatapointWorkspace:async()=>snapshot,router:{replace(){}},LaravelApiError:class extends Error{}}
 for(const name of ["setCorpus","setLoadingInitial","setErrorMessage","setCsrfToken","setCharacterizationId","setServerUpdatedAt","setOrphaned","setDrafts","setShowIntro","setShowRecoveryPrompt","setPendingRecoveryDrafts","setRecoveryIsConflict"])context[name]=v=>{observed[name]=v}
 vm.createContext(context)
 for(const name of ["invalidateWorkspace","clearPendingRecovery","isCurrentRecoveryContext"])vm.runInContext(actualSource(name),context)
 await vm.runInNewContext(actualCallback("loadP9"),context)
 return {observed,context}
}
test("actual loadP9 publishes one snapshot instead of displayed A and attached B",async()=>{
 const {observed,context}=await loadActual(workspace())
 assert.equal(context.learningAuthorityRef.current,observed.setCorpus.learning_authority_digest)
 assert.equal(context.learningAuthorityRef.current,authorityA)
 assert.equal(observed.setDrafts.SHARED.learning_review.relevant,false)
})
test("actual loadP9 rejects unsupported and mismatched partial snapshots before publication",async()=>{
 for(const invalid of [{data:workspace().data},{...workspace(),response_state:workspace(authorityB).response_state}]){
 const {observed,context}=await loadActual(invalid)
 assert.equal(context.learningAuthorityRef.current,"");assert.equal(observed.setCorpus,null);assert.ok(observed.setErrorMessage)
 }
})

test("actual loadP9 reload renews authority and revision together with namespace labels",async()=>{
 const b=workspace(authorityB);b.response_state.revision=9
 const {observed,context}=await loadActual(b)
 assert.equal(context.saveQueueRef.current.revision(),9)
 assert.equal(context.learningAuthorityRef.current,authorityB)
 assert.equal(observed.setDrafts.SHARED.learning_review.selected_to_answer,false)
})
test("validated workspace denies malformed labels, unknown universe and partial revisions",()=>{
 for(const mutate of [s=>s.response_state.revision=-1,s=>s.response_state.learning_feedback.decisions[0].relevant="false",s=>s.response_state.learning_feedback.reviewed_datapoint_ids=[],s=>s.data.mapping_snapshot_digest=null,s=>s.response_state.characterization_id=2]) {
 const s=workspace();mutate(s);assert.throws(()=>state.validateDatapointWorkspace(s))
 }
})
test("actual 409 handler invalidates display and reloads, never installs standalone conflict authority",()=>{
 const source=actualCallback("installConflictRecovery").replace(/installConflictRecovery\(\);?\s*$/, "installConflictRecovery({status:409,payload:{code:'datapoint_responses_conflict',data:{revision:3,responses:{},learning_authority_digest:'"+authorityB+"'}}})")
 let reloaded=false,display="A",mirror
 const queue=state.createResponseSaveQueue(2);const context={...state,characterizationId:1,learningAuthorityRef:{current:authorityA},draftsRef:{current:{SHARED:{learning_review:{relevant:false,selected_to_answer:false,note:"Synthetic",reason_codes:[]}}}},saveQueueRef:{current:queue},recoveryStorage:{setItem(k,v){mirror=JSON.parse(v)}},setCorpus(v){display=v},reload(){reloaded=true},setAutoSaveError(){}}
 assert.equal(vm.runInNewContext(source,context),true);assert.equal(display,null);assert.equal(context.learningAuthorityRef.current,"");assert.equal(queue.isActive(),false);assert.ok(reloaded);assert.equal(mirror.drafts.SHARED.learning_review.relevant,false)
})

test("coherent initial Laravel publication accepts empty PHP response collections only",()=>{
 const s=workspace();s.response_state.responses=[]
 assert.equal(state.validateDatapointWorkspace(s),s)
 s.response_state.responses=[{status:"draft"}]
 assert.throws(()=>state.validateDatapointWorkspace(s))
})
