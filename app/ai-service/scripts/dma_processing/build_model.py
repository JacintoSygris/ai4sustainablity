"""Reproducible local model build; catalogue-only fit, never reads evaluation fixtures."""
from pathlib import Path
import argparse
import json
import shutil
from services.dma_processing import ROOT, SOURCES, LEVELS, VERSION, catalogues, vectorizer, digest, canonical


def build(directory, root=ROOT):
    docs = catalogues(root)
    model = {'version': VERSION, 'weights': {'word': .5, 'char_wb': .5},
             'thresholds': {level: {'minimum': .16 if level == 'datapoint' else .12, 'margin': .012, 'relative': .72} for level in LEVELS}, 'vectors': {}}
    for level in LEVELS:
        model['vectors'][level] = {}
        for lang in ('es', 'en'):
            model['vectors'][level][lang] = {}
            for kind in ('word', 'char'):
                vec = vectorizer(kind); vec.fit([item['text'][lang] for item in docs[level]])
                model['vectors'][level][lang][kind] = {'vocabulary': {k: int(v) for k,v in vec.vocabulary_.items()}, 'idf': vec.idf_.tolist()}
    raw = canonical(model)
    manifest = {'version': VERSION,
                'model_sha256': digest(raw), 'catalogue_version': 'ig3-2025-06-ar16-v1', 'catalogue_sha256': digest(canonical(docs)),
                'sources': {name: digest((root / 'web/data' / name).read_bytes()) for name in SOURCES},
                'permitted_text': {'topic': 'theme/subtheme/subtopic and examples es/en in seeded order',
                                   'dr': 'bilingual disclosure_requirements titles', 'datapoint': 'IG-3 name and Spanish labels only'},
                'training_exclusions': ['imports', 'user text', 'fixtures', 'AR16 mapping text', 'XBRL taxonomy']}
    directory = Path(directory)
    directory.mkdir(parents=True, exist_ok=True)
    (directory / 'model.json').write_bytes(raw)
    raw_manifest = canonical(manifest)
    (directory / 'manifest.json').write_bytes(raw_manifest)
    (directory / 'catalogues').mkdir(exist_ok=True)
    for name in SOURCES:
        shutil.copyfile(root / 'web/data' / name, directory / 'catalogues' / name)
    return manifest


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output-dir', type=Path, required=True,
                        help='Operator-owned directory outside the checkout')
    args = parser.parse_args()
    output = args.output_dir.resolve()
    if output.is_relative_to(ROOT.parent):
        parser.error('--output-dir must be outside the checkout')
    manifest = build(output)
    print(json.dumps({'model_sha256': manifest['model_sha256'],
                      'manifest_sha256': digest(canonical(manifest))}))
