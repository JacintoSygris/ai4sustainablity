"""Standalone synthetic 01b fixtures; never operational export authority."""
from learning_case_synthetic_helper import standalone_fixture, digest, seal


def fixture():
    records = []
    for partition, count, date in [('train', 12, '2025-01-01'), ('calibration', 4, '2025-06-01'), ('holdout', 4, '2025-09-01')]:
        for i in range(count):
            r = standalone_fixture('report')
            c = r['case']; c['case_id'] = f'synthetic-{partition}-{i}'
            c['company_group_key'] = f'synthetic-group-{partition}-{i}'
            c['closure_evidence']['recorded_at'] = date + 'T00:00:00Z'
            c['topic_universe']['reviewed_topic_ids'] = ['101','102','103']
            c['topic_universe']['outside_scope_topic_ids'] = []
            c['topic_labels'] = [dict(topic_id='101',value=i%2,observed_mask=1),
                                 dict(topic_id='102',value=1-i%2,observed_mask=1),
                                 dict(topic_id='103',value=None,observed_mask=0)]
            values = dict(headquarters_country='ES' if partition != 'holdout' else 'UNSEEN',
                          employee_count_range='1_9' if i%2 == 0 else '50_249', stock_listed=bool(i%2))
            c['p5_snapshot']['digest'] = digest(values); seal(c)
            r['features'] = dict(schema_version='learning-p5-features-v1', transform_version='p5-small-categorical-v1',
                                 case_id=c['case_id'],case_hash=c['case_hash'],snapshot_digest=digest(values),values=values)
            records.append(r)
    return records


CUTOFFS = dict(train_end='2025-04-01T00:00:00Z', calibration_end='2025-08-01T00:00:00Z',
               holdout_end='2025-12-01T00:00:00Z')
