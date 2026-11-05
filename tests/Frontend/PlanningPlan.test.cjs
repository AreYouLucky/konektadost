const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const ts=require('typescript');
const modules=new Map();
function load(file){file=path.resolve(file);if(modules.has(file))return modules.get(file).exports;const mod={exports:{}};modules.set(file,mod);const code=ts.transpileModule(fs.readFileSync(file,'utf8'),{compilerOptions:{module:ts.ModuleKind.CommonJS,target:ts.ScriptTarget.ES2022}}).outputText;const local=id=>id.endsWith('?url')?{default:id.slice(0,-4)}:id.startsWith('.')?load(path.resolve(path.dirname(file),id+'.ts')):require(id);new Function('module','exports','require',code)(mod,mod.exports,local);return mod.exports}
const root=path.resolve(__dirname,'../..');
const {parsePlanningPlan,splitPlanCells,isPlanningActionPlan,planningPlanSources,planningPlanFilename}=load(path.join(root,'resources/js/lib/planning-plan.ts'));
const {createPlanningPlanPdf}=load(path.join(root,'resources/js/lib/planning-plan-pdf.ts'));
const content=`Plan title: Laboratory Referral Plan for Sample City
Draft pending local validation.

1. Priority Areas
Sample City; host sites require verification.
2. Critical Needs and Development Gaps
Verify current testing demand. [analyze_sti_laboratory_capacity]
3. Recommended STI Interventions
Recorded OneLab referral option; availability unconfirmed.
4. Investment and Resource Priorities
User ceiling: PHP 200,000 for six months; not an approved allocation.
5. Expected Outcomes and Performance
Agree test volume and turnaround targets with the service partner.
6. Action Plan Matrix
| ACTIVITIES | OBJECTIVES | RESULTS | TIMELINE | BUDGET/RESOURCES | RESPONSIBLE (PERSON/OFFICE) |
| --- | --- | --- | --- | --- | --- |
| Confirm water \\| food tests | Verify service fit | Agreed testing requirements | Months 1-2 | User ceiling PHP 200,000; quotations pending | Proposed: LGU; recorded service partner |
| Review referral | Check turnaround | Review record | Month 6 | Staff time; to be costed | Proposed: LGU focal team |

Keep the six-month horizon requested by the user.`;
const fixture=()=>({content,location:'Sample City',province:'Sample Province',version:2,created_at:'2026-11-04T10:00:00+08:00',visuals:[{type:'bar',title:'Recorded match scores',unit:'points',maximum:100,items:[{label:'Recorded OneLab option',value:72}],caption:'Stored evidence; not recomputed.',source_tool:'match_dost_interventions'},{type:'assignment_diagram',title:'Recorded assignment path',caption:'Proposed assignment; validate the host site.',source_tool:'generate_action_plan',assignments:[{reference:'technologies:TEST-1',nodes:[{id:'location',kind:'location',label:'Sample City'},{id:'need',kind:'need',label:'Testing need'},{id:'technology',kind:'technology',label:'Recorded OneLab option'},{id:'deployment',kind:'deployment',label:'Validate referral'}],edges:[{from:'location',to:'need'},{from:'need',to:'technology'},{from:'technology',to:'deployment'}]}]}]});

test('matrix parsing preserves user budgets, timing, escaped pipes and text after the matrix',()=>{
    const snapshot=fixture(),before=JSON.stringify(snapshot),parsed=parsePlanningPlan(snapshot);
    assert.equal(parsed.title,'Laboratory Referral Plan for Sample City');
    const matrix=parsed.parts.find(part=>part.type==='matrix');
    assert.equal(matrix.rows[0][0],'Confirm water | food tests');
    assert.equal(matrix.rows[0][3],'Months 1-2');
    assert.equal(matrix.rows[0][4],'User ceiling PHP 200,000; quotations pending');
    assert.match(parsed.parts.at(-1).content,/six-month horizon/);
    assert.equal(JSON.stringify(snapshot),before);
    assert.equal(isPlanningActionPlan(content),true);
});

test('legacy plans and malformed tables keep original text and do not acquire invented matrix fields',()=>{
    const original='A saved reply with Month 9 and a user ceiling of PHP 70,000.';
    const parsed=parsePlanningPlan({content:original,location:'Original City'});
    assert.equal(parsed.hasMatrix,false);
    assert.equal(parsed.parts[0].content,original);
    assert.equal(isPlanningActionPlan(original),false);
    assert.equal(parsePlanningPlan({content:'| ACTIVITIES | OBJECTIVES |\n| --- | --- |\n| A | B |',location:'City'}).hasMatrix,false);
    assert.deepEqual(splitPlanCells('| A | water \\| food |'),['A','water | food']);
});

test('sources come only from the selected snapshot and stored versions have distinct filenames',()=>{
    const snapshot=fixture();snapshot.sources=[{name:'match_dost_interventions',location:'Sample City'}];
    snapshot.evidence_snapshot=JSON.stringify({tools:[{tool:'analyze_sti_laboratory_capacity',result:{}}]});
    assert.deepEqual(planningPlanSources(snapshot).map(row=>row.name).sort(),['analyze_sti_laboratory_capacity','generate_action_plan','match_dost_interventions']);
    assert.match(planningPlanFilename(snapshot),/-v2\.pdf$/);
    assert.notEqual(planningPlanFilename(snapshot),planningPlanFilename({...snapshot,version:1}));
    snapshot.evidence_snapshot='{invalid';assert.doesNotThrow(()=>planningPlanSources(snapshot));
});

test('PDF exports the selected narrative, matrix and recorded visuals in landscape with embedded fonts',()=>{
    const fonts={regular:fs.readFileSync(path.join(root,'resources/fonts/noto-sans/NotoSans-Regular.ttf')).toString('base64'),bold:fs.readFileSync(path.join(root,'resources/fonts/noto-sans/NotoSans-Bold.ttf')).toString('base64')};
    const snapshot=fixture(),before=JSON.stringify(snapshot),doc=createPlanningPlanPdf(snapshot,fonts);
    assert.equal(Buffer.from(doc.output('arraybuffer')).subarray(0,5).toString(),'%PDF-');
    assert.ok(doc.internal.pageSize.getWidth()>doc.internal.pageSize.getHeight());
    assert.ok(doc.getNumberOfPages()>=2);
    assert.equal(doc.getFont().fontName,'NotoSans');
    assert.equal(JSON.stringify(snapshot),before);
});
