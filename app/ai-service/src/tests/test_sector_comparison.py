"""Explicitly synthetic company/report/label fixtures."""
import copy, csv, hashlib, json, subprocess, sys, tempfile, unittest
from pathlib import Path
from services.sector_comparison import build_artifact, compare_topics, load_snapshot, write_artifact, TOPICS, TAXONOMY, VERSION
from services.datatypes import SectorArtifact
ROOT = Path(__file__).resolve().parents[3]


def synthetic_inputs(directory):
    directory=Path(directory)
    encoding=ROOT/'contracts/mappings/p5-gpt41-feature-encoding-v1.json'
    mapping=ROOT/'web/data/ar16_to_python_esrs_mapping_new_format_732_v1.json'
    rows=json.loads(mapping.read_text())['keys']; keys=[r['python_esrs_key'] for r in rows]
    topic_keys={t:[r['python_esrs_key'] for r in rows if r['status']=='approved' and int(t) in r['ar16_topic_ids']] for t in TOPICS}
    labels,masks,companies=[],[],[]
    for i in range(6):
        report=f'synthetic-report-{i}';label=dict(file=report,**{k:'0' for k in keys});mask=dict(file=report,**{k:'1' for k in keys})
        for k in topic_keys['1']: label[k]='1' if i<3 else '0'
        for k in topic_keys['2']: mask[k]='0' if i==0 else '1'
        for k in topic_keys['3']: mask[k]='0'
        for k in keys:
            if k.endswith('_summary'): label[k]='1'
        labels.append(label);masks.append(mask)
        companies.append(dict(company_id=f'synthetic-company-{i}',identity_verified=True,report_links_verified=True,sections_verified=True,
            report_ids=[report],selected_report_id=report,period=dict(start='2025-01-01',end='2025-12-31'),sections=['K','L'] if i<5 else ['M'],sector_taxonomy=TAXONOMY,verification_reference='synthetic-check'))
    extra=dict(labels[0],file='synthetic-extra')
    for k in topic_keys['1']:extra[k]='0'
    labels.append(extra);masks.append(dict(masks[0],file='synthetic-extra'));companies[0]['report_ids'].append('synthetic-extra')
    for name,data in [('labels',labels),('masks',masks)]:
        with (directory/f'{name}.csv').open('w',newline='') as stream:
            w=csv.DictWriter(stream,fieldnames=['file',*keys]);w.writeheader();w.writerows(data)
    manifest=dict(version=VERSION,sector_taxonomy=TAXONOMY,provenance=dict(label_kind='synthetic',source_reference='synthetic-fixture',authorization_reference='test-only'),companies=companies)
    (directory/'companies.json').write_text(json.dumps(manifest))
    return dict(labels=directory/'labels.csv',masks=directory/'masks.csv',companies=directory/'companies.json',encoding=encoding,topic_mapping=mapping)


class SectorComparisonTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup)
        self.inputs=synthetic_inputs(self.temp.name);self.artifact=build_artifact(**self.inputs)

    def mutate_manifest(self,fn):
        path=self.inputs['companies'];v=json.loads(path.read_text());fn(v);path.write_text(json.dumps(v))

    def comparison(self,sections=None):
        return compare_topics(SectorArtifact.model_validate(self.artifact),'a'*64,['K','L','M','J','V'] if sections is None else sections,['1','2','3','4'])

    def test_company_selection_and_multiactivity_do_not_duplicate_population(self):
        a=self.artifact
        self.assertEqual(a['excluded_additional_reports'],1);self.assertEqual(a['global_population']['population_companies'],6)
        self.assertEqual(a['sections']['K']['population_companies'],5);self.assertEqual(a['sections']['L']['population_companies'],5)
        self.assertEqual(a['sections']['K']['topics']['1'],dict(n_scored=5,n_material=3))
        self.assertEqual(a['global_population']['topics']['1'],dict(n_scored=6,n_material=3))
        self.mutate_manifest(lambda m:m['companies'][0].update(selected_report_id='synthetic-extra'))
        a=build_artifact(**self.inputs)
        self.assertEqual(a['global_population']['population_companies'],6);self.assertEqual(a['sections']['K']['topics']['1']['n_material'],2)

    def test_boundaries_per_topic_fallback_unknown_masks_summaries_zero_positive(self):
        cells=self.comparison()['comparisons'][0]['topics']
        self.assertEqual((cells[0]['source'],cells[0]['proportion']),('sector',.6))
        self.assertEqual(cells[1]['sector'],dict(n_scored=4,n_material=0));self.assertEqual(cells[1]['global_counts']['n_scored'],5)
        self.assertEqual((cells[1]['source'],cells[1]['proportion']),('global_fallback',0));self.assertTrue(cells[1]['low_confidence'])
        self.assertEqual((cells[2]['source'],cells[2]['proportion']),('insufficient_data',None))
        self.assertEqual(self.artifact['global_population']['topics']['3'],dict(n_scored=0,n_material=0))
        self.assertEqual((cells[3]['source'],cells[3]['proportion']),('sector',0))

    def test_j_k_l_m_v_semantics_and_missing_section(self):
        result=self.comparison();self.assertEqual([c['section'] for c in result['comparisons']],['K','L','M','J','V'])
        self.assertEqual([c['population_companies'] for c in result['comparisons']],[5,5,1,0,0]);self.assertEqual(result['global_population_companies'],6)
        c=self.comparison([])['comparisons'][0];self.assertIsNone(c['section']);self.assertEqual(c['population_companies'],0);self.assertEqual(c['topics'][0]['source'],'global_fallback')

    def test_selection_identity_conflicts_rev2_and_product_fields_rejected(self):
        original=self.inputs['companies'].read_text()
        mutations=[lambda m:m['companies'][0].update(identity_verified=False),lambda m:m['companies'][0].update(selected_report_id='absent'),
            lambda m:m['companies'][1].update(report_ids=['synthetic-report-0'],selected_report_id='synthetic-report-0'),
            lambda m:m['companies'].append(copy.deepcopy(m['companies'][0])),lambda m:m['companies'][0].update(sector_taxonomy='NACE Rev.2'),
            lambda m:m['companies'][0].update(sections=['finance_insurance']),lambda m:m['companies'][0].update(products_services=['L'])]
        for fn in mutations:
            self.inputs['companies'].write_text(original);self.mutate_manifest(fn)
            with self.assertRaises((ValueError,TypeError)):build_artifact(**self.inputs)

    def test_approved_any_positive_all_negative_without_double_count(self):
        v=json.loads(self.inputs['topic_mapping'].read_text());row=next(r for r in v['keys'] if r['status']=='approved' and r['ar16_topic_ids']==[2]);row['ar16_topic_ids']=[1,2]
        path=Path(self.temp.name)/'map.json';path.write_text(json.dumps(v));self.inputs['topic_mapping']=path
        a=build_artifact(**self.inputs);self.assertEqual(a['sections']['K']['topics']['1'],dict(n_scored=5,n_material=3))
        with self.inputs['masks'].open() as stream: rows=list(csv.DictReader(stream))
        rows[3][row['python_esrs_key']]='0'
        with self.inputs['masks'].open('w',newline='') as stream:
            w=csv.DictWriter(stream,fieldnames=rows[0]);w.writeheader();w.writerows(rows)
        a=build_artifact(**self.inputs);self.assertEqual(a['sections']['K']['topics']['1'],dict(n_scored=4,n_material=3))

    def test_csv_conflicts_unexpected_keys_and_strict_values(self):
        path=self.inputs['labels'];original=path.read_text();first=original.splitlines()[1]
        for bad in [original+first.replace(',1,',',0,',1)+'\n',original.replace('file,','file,unexpected,',1),original.replace(',1,',',true,',1)]:
            path.write_text(bad)
            with self.assertRaises(ValueError):build_artifact(**self.inputs)

    def test_cli_order_invariance_no_overwrite_and_no_individual_data(self):
        output=Path(self.temp.name)/'artifact.json';cmd=[sys.executable,str(ROOT/'ai-service/scripts/concepts/build_sector_comparison.py')]
        for k,v in self.inputs.items():cmd.extend(['--'+k.replace('_','-'),str(v)])
        cmd.extend(['--output',str(output)])
        self.assertEqual(subprocess.run(cmd,capture_output=True).returncode,0)
        raw=output.read_bytes();self.assertEqual(load_snapshot(output,hashlib.sha256(raw).hexdigest()).sections['K'].topics['1'].n_material,3)
        for private in ['synthetic-company-','synthetic-report-',self.temp.name]:self.assertNotIn(private.encode(),raw)
        self.assertNotEqual(subprocess.run(cmd,capture_output=True).returncode,0);self.assertEqual(output.read_bytes(),raw)
        for k in ['labels','masks']:
            lines=self.inputs[k].read_text().splitlines();self.inputs[k].write_text('\n'.join([lines[0],*reversed(lines[1:])])+'\n')
        other=build_artifact(**self.inputs);original=copy.deepcopy(self.artifact)
        for a in [other,original]:a.pop('built_at');a.pop('hashes')
        self.assertEqual(other,original)
        with self.assertRaises(FileExistsError):write_artifact(self.artifact,output)

    def test_shared_cross_language_fixture_matches_cli_snapshot(self):
        fixture = json.loads((ROOT/'web/tests/Fixtures/sector-comparison-response.json').read_text())
        self.assertEqual(fixture['hashes']['encoding'], self.artifact['hashes']['encoding'])
        self.assertEqual(fixture['hashes']['topic_mapping'], self.artifact['hashes']['topic_mapping'])
        result = compare_topics(SectorArtifact.model_validate(self.artifact), fixture['artifact_sha256'], ['K'], TOPICS)
        for key in ['comparisons', 'global_population_companies', 'provenance', 'coverage_period']:
            self.assertEqual(fixture[key], result[key])

    def test_corrupt_schema_hash_counts_and_size(self):
        path=Path(self.temp.name)/'artifact.json';write_artifact(self.artifact,path)
        with self.assertRaises(ValueError):load_snapshot(path,'0'*64)
        for fn in [lambda a:a.update(extra='x'),lambda a:a['sections']['K']['topics']['1'].update(n_material=6),lambda a:a['sections']['K']['topics']['1'].update(n_scored=True),lambda a:a.update(min_support=5.0),lambda a:a.update(built_at='2026-10-09T12:00:00+02:00')]:
            a=copy.deepcopy(self.artifact);fn(a)
            with self.assertRaises(ValueError):SectorArtifact.model_validate(a)
        path.write_bytes(b' '*(5*1024*1024+1))
        with self.assertRaises(ValueError):load_snapshot(path,hashlib.sha256(path.read_bytes()).hexdigest())
