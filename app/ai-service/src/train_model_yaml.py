import time
from argparse import ArgumentParser
from pathlib import Path

import pandas as pd
from sklearn.preprocessing import OneHotEncoder, StandardScaler
from sklearn.compose import ColumnTransformer
from sklearn.pipeline import Pipeline
from sklearn.dummy import DummyClassifier
from sklearn.impute import SimpleImputer
from sklearn.metrics import classification_report, f1_score
from sklearn.preprocessing import MultiLabelBinarizer
import numpy as np

from train import *
from train.optimisation import *
import joblib

# Load and merge data
def load_data(input_file, output_file):
    df_input = pd.read_csv(input_file, sep=';')
    df_output = pd.read_csv(output_file, sep=';')

    # Rename company_data_* columns to the internal names expected by the pipeline
    df_input = df_input.rename(columns={
        'company_data_sector':                'sector',
        'company_data_headquarters_country':  'headquarters_country',
        'company_data_company_size':          'company_size',
        'company_data_annual_turnover':       'annual_turnover_million_euro',
        'company_data_subsidiaries_countries':'subsidiaries_regions',
        'company_data_stock_listed':          'stock_listed',
        'company_data_reporting_currency':    'reporting_currency',
        'company_data_juridic_form':          'juridic_form',
        'company_data_products_services':     'products_services',
    })

    # Merge on the 'file' column
    df = pd.merge(df_input, df_output, on='file')
    return df


# Train model
def train_model(df, output_path, verbose, base_model, wrapper, split, optimiser):
    if verbose:
        print("[building pipeline]")

    # --- Sector flags (unchanged logic, single-letter codes) ---
    # fillna guards against NaN rows that would make str.split return float NaN
    df['sector_list'] = df['sector'].fillna('').str.split(',').apply(
        lambda x: [s.strip() for s in x if s.strip()]
    )
    mlb_sector = MultiLabelBinarizer()
    sector_flags = pd.DataFrame(
        mlb_sector.fit_transform(df['sector_list']),
        columns=[f"sector_{c}" for c in mlb_sector.classes_],
        index=df.index
    )

    # --- Subsidiary region flags (was a numeric count; now a multi-label region list) ---
    df['subsidiaries_region_list'] = df['subsidiaries_regions'].fillna('').str.split(',').apply(
        lambda x: [r.strip() for r in x if r.strip()]
    )
    mlb_regions = MultiLabelBinarizer()
    region_flags = pd.DataFrame(
        mlb_regions.fit_transform(df['subsidiaries_region_list']),
        columns=[f"region_{c}" for c in mlb_regions.classes_],
        index=df.index
    )

    # --- Products/services flags (multi-label codes like A, B, C) ---
    df['products_list'] = df['products_services'].fillna('').str.split(',').apply(
        lambda x: [p.strip() for p in x if p.strip()]
    )
    mlb_products = MultiLabelBinarizer()
    product_flags = pd.DataFrame(
        mlb_products.fit_transform(df['products_list']),
        columns=[f"product_{c}" for c in mlb_products.classes_],
        index=df.index
    )

    # --- Scalar / categorical features ---
    # company_size is now categorical (LARGE / HUGE / ULTRA), not numeric
    # annual_turnover is heavily right-skewed; log1p compresses the range without
    # requiring strictly positive values (log1p(0) = 0 rather than -inf)
    df['annual_turnover_log'] = np.log1p(
        df['annual_turnover_million_euro'].fillna(0).clip(lower=0)
    )
    # stock_listed is already binary (0/1); scaling it is unnecessary and misleading
    df['stock_listed_flag'] = df['stock_listed'].map({True: 1, False: 0, 'True': 1, 'False': 0}).fillna(0).astype(int)

    X_scalar = df[['headquarters_country',
                   'annual_turnover_log',
                   'company_size',
                   'juridic_form',
                   'stock_listed_flag',
                   'reporting_currency']]

    X = pd.concat([sector_flags, region_flags, product_flags, X_scalar], axis=1)

    # --- Target columns ---
    esrs_columns = [col for col in df.columns if col.startswith("esrs_")]
    # NaN in ESRS labels means the topic was not reported; treat as 0
    y = df[esrs_columns].fillna(0).astype(int)

    # --- Feature lists for the preprocessor ---
    # Only the log-transformed turnover needs scaling; stock_listed is now a plain binary flag
    num_features  = ['annual_turnover_log']
    cat_features  = ['headquarters_country', 'reporting_currency', 'company_size', 'juridic_form']
    # Binary flags (sector_*, region_*, product_*, stock_listed_flag) pass through unchanged
    pass_features = [c for c in X.columns if c not in num_features + cat_features]

    # Preprocessing: encode categoricals, scale numerics; binary flags pass through
    preprocessor = ColumnTransformer(transformers=[
        ('cat', Pipeline([
            ('imputer', SimpleImputer(strategy='most_frequent')),
            ('onehot', OneHotEncoder(handle_unknown='ignore'))
        ]), cat_features),
        ('num', Pipeline([
            ('imputer', SimpleImputer(strategy='mean')),
            ('scaler', StandardScaler())
        ]), num_features),
        ('bin', 'passthrough', pass_features),
    ])

    model_builder = base_classifiers[base_model]()
    model_wrapper = wrapper_models[wrapper]()

    # Build classifier
    if verbose:
        print(f"[building classifier] {model_wrapper.name()} on {model_builder.name()}")

    base_clf = model_builder.build()
    wrapped_clf = model_wrapper.build(base_clf)

    # Pipeline
    clf = Pipeline(steps=[
        ('preprocessor', preprocessor),
        ('classifier', wrapped_clf)
    ])

    # Split. When training with multiple reports for the same company, keep the
    # same company out of both train and test to avoid optimistic leakage.
    splitter = splitters[split]()
    if verbose:
        print(f"[Splitting] with {splitter.name()}")
    if "company_data_company_name" in df.columns:
        groups = df["company_data_company_name"].fillna(df["file"]).astype(str)
    else:
        groups = df["file"].astype(str)
    X_train, X_test, y_train, y_test = splitter.split(X, y, groups=groups)

    # Optimize
    optimiser_object = optimisers[optimiser]
    if verbose:
        print(f"[Optimising] with {optimiser_object.name()}")
    clf = optimiser_object.optimise(base_classifiers[base_model], wrapper_models[wrapper], clf, preprocessor, X_train, y_train)
    print("=================> Optimized!")

    # Train
    clf.fit(X_train, y_train)
    print("Training accuracy:", clf.score(X_train, y_train))
    y_pred = clf.predict(X_test)
    print("Test classification report:")
    print(classification_report(y_test, y_pred, zero_division=0))

    f1s = f1_score(y_test, y_pred, average=None, zero_division=0)
    worst_labels = np.argsort(f1s)[:10]
    print("Worst performing labels:", worst_labels)

    dummy = MultiOutputClassifier(DummyClassifier(strategy='most_frequent'))
    dummy.fit(X_train, y_train)
    print("Baseline dummy accuracy:", dummy.score(X_test, y_test))

    # Save model and vocabulary artifacts
    output_dir = Path(output_path)
    output_dir.mkdir(parents=True, exist_ok=True)
    joblib.dump(clf, output_dir / 'esrs_classifier.pkl')
    joblib.dump(y.columns.tolist(), output_dir / 'esrs_columns.pkl')
    joblib.dump(mlb_sector.classes_.tolist(), output_dir / 'sector_columns.pkl')
    joblib.dump(mlb_regions.classes_.tolist(), output_dir / 'region_columns.pkl')
    joblib.dump(mlb_products.classes_.tolist(), output_dir / 'product_columns.pkl')

    return clf


def main(company_data, esrs_data, output_path, verbose, base_model_builder, wrapper_builder, splitter, optimiser):
    print(f"Loading data {company_data} and {esrs_data}")
    df = load_data(company_data, esrs_data)
    print(f"Start training")
    Path(output_path).mkdir(parents=True, exist_ok=True)
    start = time.time()
    train_model(df, output_path, int(verbose), base_model_builder, wrapper_builder, splitter, optimiser)
    end = time.time()
    print(f"Training completed in {end - start:.2f} seconds")
    print(f"Classifier stored at {output_path}")


# Example usage
if __name__ == "__main__":
    argument_parser = ArgumentParser(description='Extract sustainability data from documents')
    argument_parser.add_argument('--company_data', required=True, default='./training_data/company_data_prev.csv',
                                 help='path to csv file with company data')
    argument_parser.add_argument('--esrs_data', required=True, default='./training_data/esrs_data.csv',
                                 help='path to csv file with esrs characterisation')
    argument_parser.add_argument('--output_path', required=True, default='./trained_classifier/',
                                 help='path where classifier parameters are to be persisted')
    argument_parser.add_argument('--verbose', action='store_true', help='Enable verbose output')
    argument_parser.add_argument('--base_model', required=False, default='RF',
                                 choices=base_classifiers.keys(),
                                 help=f'Base model to use for training (valid values: {base_classifiers.keys()})')
    argument_parser.add_argument('--wrapper', required=False, default='chain',
                                 choices=wrapper_models.keys(),
                                 help=f'Wrapper model for base classifier (valid values: {wrapper_models.keys()})')
    argument_parser.add_argument('--split', required=False, default='iterative',
                                 choices=splitters.keys(),
                                 help=f'Type of data splitting (valid values: {splitters.keys()})')
    argument_parser.add_argument('--optimise', required=False, default='none',
                                 choices=optimisers.keys(),
                                 help=f'Hyperparameter optimisation (valid values: {optimisers.keys()})')

    args = argument_parser.parse_args()
    main(args.company_data, args.esrs_data, args.output_path, args.verbose,
         args.base_model, args.wrapper, args.split, args.optimise)
