import time
from argparse import ArgumentParser

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

    # Merge on the 'name' column
    df = pd.merge(df_input, df_output, on='file')
    return df

# Train model
def train_model(df, output_path, verbose, base_model, wrapper, split, optimiser):
    if verbose:
        print("[building pipeline]")
    # Split sector strings into lists
    df['sector_list'] = df['sector'].str.split(',')

    # Create sector flags
    mlb = MultiLabelBinarizer()
    sector_flags = pd.DataFrame(mlb.fit_transform(df['sector_list']), columns=mlb.classes_, index=df.index)

    # Other features
    X_numeric_categorical = df[['headquarters_country',
                                'employees_total',
                                'annual_turnover_million_euro',
                                'num_subsidiaries_countries',
                                'stock_listed',
                                'reporting_currency']]
    X = pd.concat([sector_flags, X_numeric_categorical], axis=1)

    # Target columns (ESRS columns start from 9)
    esrs_columns = [col for col in df.columns if col.startswith("esrs_")]
    y = df[esrs_columns]

    num_features = ['employees_total', 'annual_turnover_million_euro', 'num_subsidiaries_countries', 'stock_listed']
    cat_features = ['headquarters_country', 'reporting_currency']

    # Preprocessing: encode country, and currency, scale numeric
    preprocessor = ColumnTransformer(transformers=[
        ('cat', Pipeline([
            ('imputer', SimpleImputer(strategy='most_frequent')),
            ('onehot', OneHotEncoder(handle_unknown='ignore'))
        ]), cat_features),
        ('num', Pipeline([
            ('imputer', SimpleImputer(strategy='mean')),
            ('scaler', StandardScaler())
        ]), num_features),
    ], remainder='passthrough')

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

    # Split
    splitter = splitters[split]()
    if verbose:
        print(f"[Splitting] with {splitter.name()}")
    X_train, X_test, y_train, y_test = splitter.split(X, y)

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

    # Save
    joblib.dump(clf, output_path+'/esrs_classifier.pkl')
    joblib.dump(y.columns.tolist(), output_path+'/esrs_columns.pkl')
    joblib.dump(mlb.classes_.tolist(), output_path+'/sector_columns.pkl')

    return clf


def main(company_data, esrs_data, output_path, verbose, base_model_builder, wrapper_builder, splitter, optimiser):
    print(f"Loading data {company_data} and {esrs_data}")
    df = load_data(company_data, esrs_data)
    print(f"Start training")
    start = time.time()
    if verbose:
        train_model(df, output_path, 1, base_model_builder, wrapper_builder, splitter, optimiser)
    else:
        train_model(df, output_path, 0, base_model_builder, wrapper_builder, splitter, optimiser)
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
    argument_parser.add_argument('--verbose',action='store_true',help='Enable verbose output')
    argument_parser.add_argument('--base_model', required=False, default='RF',
                                 choices=base_classifiers.keys(), help=f'Base model to use for training (valid values: {base_classifiers.keys()})')
    argument_parser.add_argument('--wrapper', required=False, default='chain',
                                 choices=wrapper_models.keys(), help=f'Wrapper model for base classifier (valid values: {wrapper_models.keys()})')
    argument_parser.add_argument('--split', required=False, default='iterative',
                                 choices=splitters.keys(), help=f'Type of data splitting (valid values: {splitters.keys()})')
    argument_parser.add_argument('--optimise', required=False, default='none',
                                 choices=optimisers.keys(),
                                 help=f'Hyperparameter optimisation (valid values: {splitters.keys()})')

    args = argument_parser.parse_args()
    main(args.company_data, args.esrs_data, args.output_path, args.verbose,
         args.base_model, args.wrapper, args.split, args.optimise)
