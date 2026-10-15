import importlib.util
import json
from pathlib import Path
import shutil
import tempfile
import unittest
from services.dma_processing import ROOT, LEVELS, load_model, process, digest

spec = importlib.util.spec_from_file_location("dma_model_builder", ROOT / "ai-service/scripts/dma_processing/build_model.py")
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)

class DmaProcessingTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        temporary = tempfile.TemporaryDirectory(prefix='dma-model-test-')
        cls.addClassCleanup(temporary.cleanup)
        cls.package = Path(temporary.name)
        builder.build(cls.package)
        cls.model = load_model(cls.package)

    def infer(self, text, locale='es'):
        request = {'version':'dma-processing-v1','locale':locale,'iros':[{'iro_id':'a'*64,'description':text,'source_sha256':'b'*64}]}
        return process(request, self.model)

    def ids(self, result, level='datapoint'):
        return [c['id'] for c in result['iros'][0]['levels'][level]['candidates']]

    def test_water_text_contrast_water_description_changes_with_fixed_external_topic_and_scores(self):
        one = self.infer('El consumo total de agua genera impacto.'); two = self.infer('Los vertidos de agua generan impacto.')
        self.assertIn('E3-4_01', self.ids(one)); self.assertIn('E3-4_12', self.ids(two)); self.assertNotEqual(self.ids(one), self.ids(two))
        self.assertNotEqual(self.ids(one,'topic'), self.ids(two,'topic'))

    def test_direct_indicator_ranking_direct_indicators_with_fixed_E1_scope(self):
        energy=self.infer('Total energy consumption related to own operations.', 'en')
        ghg=self.infer('Gross Scope 1 greenhouse gas emissions.', 'en')
        self.assertIn('E1-5_01',self.ids(energy)); self.assertIn('E1-6_07',self.ids(ghg)); self.assertNotEqual(self.ids(energy),self.ids(ghg))
        # Mutating topic retrieval cannot manufacture or remove datapoint candidates.
        model = dict(self.model); model['matrices'] = dict(model['matrices'])
        for kind in ('word','char'):
            vec,matrix=model['matrices']['topic','en',kind]; model['matrices']['topic','en',kind]=(vec,matrix*0)
        request={'version':'dma-processing-v1','locale':'en','iros':[{'iro_id':'a'*64,'source_sha256':'b'*64,'description':'Gross Scope 1 greenhouse gas emissions.'}]}
        self.assertEqual(self.ids(ghg),self.ids(process(request,model)))

    def test_bilingual_text_inference_unseen_bilingual_paraphrases_and_abstention(self):
        for lang,text in [('es','Evaluamos la reparabilidad de nuestros productos.'),('en','We evaluate the reparability of our products.')]:
            self.assertIn('E5-5_03',self.ids(self.infer(text,lang)))
        for text in ['El violinista toca una sonata.', 'No generamos residuos radiactivos.', 'Generamos residuos radiactivos. No generamos residuos radiactivos.', 'Quizás haya residuos peligrosos o bien radiactivos.']:
            result=self.infer(text)
            self.assertTrue(all(not result['iros'][0]['levels'][level]['candidates'] for level in LEVELS))

    def test_e5_indicator_contrasts_all_e5_contrasts(self):
        for lang,text,id in [('es','Reparabilidad de los productos','E5-5_03'),('es','Cantidad total de residuos peligrosos','E5-5_15'),('es','Cantidad total de residuos radiactivos','E5-5_16'),('en','Reparability of products','E5-5_03'),('en','Total amount of hazardous waste','E5-5_15'),('en','Total amount of radioactive waste','E5-5_16')]:
            with self.subTest(lang=lang,id=id): self.assertEqual(self.ids(self.infer(text,lang))[0],id)

    def test_english_contractions_and_absence_abstain(self):
        for text in ["We don't generate radioactive waste", "We don’t generate radioactive waste",
                     "Our operations aren't generating hazardous waste.",
                     'We generate zero radioactive waste.', 'Radioactive waste is absent.',
                     'Absence of hazardous waste in our operations.', 'We generate 0 radioactive waste.',
                     'Our operations are free of radioactive waste.']:
            with self.subTest(text=text):
                levels = self.infer(text, 'en')['iros'][0]['levels']
                self.assertTrue(all(not value['candidates'] and value['abstention'] == 'insufficient_evidence'
                                    for value in levels.values()))

    def test_spanish_zero_and_absence_abstain(self):
        for text in ['Generamos cero residuos radiactivos.', 'Ausencia de residuos peligrosos.',
                     'Los residuos radiactivos son inexistentes.', 'Generamos 0 residuos radiactivos.',
                     'Generamos 0,0 toneladas de residuos radiactivos.', 'Carecemos de residuos peligrosos.']:
            with self.subTest(text=text):
                levels = self.infer(text, 'es')['iros'][0]['levels']
                self.assertTrue(all(not value['candidates'] and value['abstention'] == 'insufficient_evidence'
                                    for value in levels.values()))

    def test_unresolved_questions_abstain_in_both_languages(self):
        for lang, text in [('es', '¿Generamos residuos peligrosos?'),
                           ('es', '¿Generamos residuos radiactivos'),
                           ('en', 'Do we generate radioactive waste?'),
                           ('en', 'Hazardous waste is generated?')]:
            with self.subTest(lang=lang, text=text):
                levels = self.infer(text, lang)['iros'][0]['levels']
                self.assertTrue(all(not value['candidates'] and value['abstention'] == 'ambiguous'
                                    for value in levels.values()))

    def test_zero_quantities_before_and_after_object_es_en(self):
        cases = {
            'es': ['Los residuos radiactivos generados son 0.', 'Residuos peligrosos: 0,00 toneladas.',
                   'Se generan 0 kg de residuos peligrosos.', '0 toneladas de residuos radiactivos.'],
            'en': ['Radioactive waste generated: 0 tonnes.', 'Hazardous waste generated is 0.00 kg.',
                   'We generate 0 kg of hazardous waste.', '0 tonnes of radioactive waste.'],
        }
        for lang, texts in cases.items():
            for text in texts:
                with self.subTest(lang=lang, text=text):
                    levels = self.infer(text, lang)['iros'][0]['levels']
                    self.assertTrue(all(not v['candidates'] and v['abstention'] == 'insufficient_evidence'
                                        for v in levels.values()))
        # A decimal fraction, nonzero quantity or identifier must not become zero.
        for lang, text in [('es', 'Residuos radiactivos generados: 0,5 toneladas.'),
                           ('en', 'Radioactive waste generated: 0.5 tonnes.'),
                           ('en', 'Radioactive waste generated: 10 tonnes.')]:
            with self.subTest(text=text):
                self.assertIn('E5-5_16', self.ids(self.infer(text, lang)))

    def test_pending_determination_es_en_abstains(self):
        cases = {'es': ['Pendiente determinar si generamos residuos peligrosos.',
                        'Pendiente de confirmar la generación de residuos radiactivos.'],
                 'en': ['Whether we generate hazardous waste is yet to be determined.',
                        'Radioactive waste generation is pending confirmation.',
                        'Hazardous waste generation is to be determined.']}
        for lang, texts in cases.items():
            for text in texts:
                with self.subTest(lang=lang, text=text):
                    levels = self.infer(text, lang)['iros'][0]['levels']
                    self.assertTrue(all(not v['candidates'] and v['abstention'] == 'ambiguous'
                                        for v in levels.values()))

    def test_import_provenance_import_digest_identity_and_original_text(self):
        result=self.infer('💧 El consumo total de agua afecta al río.'); iro=result['iros'][0]
        self.assertEqual(iro['iro_id'],'a'*64)
        self.assertEqual(iro['description_sha256'],digest('💧 El consumo total de agua afecta al río.'.encode()))

    def test_unicode_spans_determinism_and_finite_scores(self):
        text='🧪 e\u0301: residuos radiactivos de la instalación.'
        one=self.infer(text); self.assertEqual(one,self.infer(text))
        emitted=0
        for level in one['iros'][0]['levels'].values():
            for candidate in level['candidates']:
                emitted+=1; self.assertGreaterEqual(candidate['score'],0);self.assertLessEqual(candidate['score'],1)
                for span in candidate['spans']:
                    self.assertEqual(text[span['start']:span['end']],span['quote'])
        self.assertGreater(emitted,0)

    def test_catalogue_and_model_tampering_fail_closed(self):
        # tempfile honours TMPDIR; no private-session path or repository writes.
        with tempfile.TemporaryDirectory(prefix='dma-integrity-') as tmp:
            root=Path(tmp); package=root/'package'; shutil.copytree(self.package,package)
            (package/'model.json').write_text('{}')
            with self.assertRaisesRegex(ValueError,'model_mismatch'): load_model(package)
            shutil.copyfile(self.package/'model.json',package/'model.json')
            (root/'web/data').mkdir(parents=True)
            for path in (ROOT/'web/data').glob('esrs*.json'): shutil.copyfile(path,root/'web/data'/path.name)
            (root/'web/data/esrs_topics.json').write_text('[]')
            with self.assertRaisesRegex(ValueError,'catalogue_mismatch'):load_model(package,root)
            (package/'manifest.json').write_text('{}')
            with self.assertRaisesRegex(ValueError,'model_manifest_mismatch'):load_model(package)

if __name__ == '__main__':unittest.main()
