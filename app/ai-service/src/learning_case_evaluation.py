"""Raw masked evaluation only; no operational policy gates."""
import pandas as pd
from learning_case_dataset import validate_matrices

class EvaluationError(ValueError):
    pass

def evaluate_raw(values, masks, predictions, case_ids, topic_ids, label_status):
    """None means unavailable; NaN is rejected, including on masked rows."""
    values,masks=validate_matrices(values,masks,case_ids,topic_ids)
    if (type(label_status) is not dict or list(label_status) != topic_ids
            or any(s not in ('fitted','not_evaluable:no_observations','not_evaluable:single_class') for s in label_status.values())
            or not isinstance(predictions,pd.DataFrame) or predictions.index.tolist() != case_ids
            or predictions.columns.tolist() != topic_ids or any(t != object for t in predictions.dtypes)):
        raise EvaluationError('learning_evaluation.axis_invalid')
    labels={}; aggregate=dict(tp=0,fn=0,fp=0,support=0,positive_support=0,negative_support=0,unscored_observations=0)
    for topic in topic_ids:
        fitted=label_status[topic] == 'fitted'
        pred=predictions[topic].tolist()
        if any((type(v) is not int or v not in (0,1)) if fitted else v is not None for v in pred):
            raise EvaluationError('learning_evaluation.prediction_invalid')
        observed=[(v,p) for v,m,p in zip(values[topic],masks[topic],pred) if m == 1]
        positive=sum(v == 1 for v,_ in observed); negative=sum(v == 0 for v,_ in observed)
        row=dict(status='evaluable' if fitted and observed else 'not_evaluable', model_status=label_status[topic],
                 support=len(observed),positive_support=positive,negative_support=negative,
                 tp=None,fn=None,fp=None,recall=None,precision=None)
        if fitted and observed:
            row.update(tp=sum(v == 1 and p == 1 for v,p in observed),
                       fn=sum(v == 1 and p == 0 for v,p in observed),fp=sum(v == 0 and p == 1 for v,p in observed))
            row['recall']=row['tp']/positive if positive else None
            proposed=row['tp']+row['fp']; row['precision']=row['tp']/proposed if proposed else None
            for key in ['tp','fn','fp','support','positive_support','negative_support']: aggregate[key]+=row[key]
        else:
            aggregate['unscored_observations']+=len(observed)
        labels[topic]=row
    aggregate['status']='evaluable' if aggregate['support'] else 'not_evaluable'
    aggregate['recall']=aggregate['tp']/aggregate['positive_support'] if aggregate['positive_support'] else None
    proposed=aggregate['tp']+aggregate['fp']; aggregate['precision']=aggregate['tp']/proposed if proposed else None
    if not aggregate['support']:
        for key in ['tp','fn','fp']: aggregate[key]=None
    return dict(labels=labels,aggregate=aggregate,policy_gates='OPEN',evaluation='raw_masked_only')
