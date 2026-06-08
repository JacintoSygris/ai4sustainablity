import time
import warnings
import pandas as pd
import joblib

from argparse import ArgumentParser
from pathlib import Path
from sklearn.preprocessing import MultiLabelBinarizer
from utils.constants import PATH_ESRS_CLASSIFIER, PATH_ESRS_COLUMNS, PATH_SECTOR_COLUMNS
from .datatypes import CompanyData, Prediction

LIGHTGBM_FEATURE_NAME_WARNING = (
    "X does not have valid feature names, but LGBMClassifier was fitted with feature names"
)
MODEL_ARTIFACTS = {
    "sector_columns.pkl": PATH_SECTOR_COLUMNS,
    "esrs_classifier.pkl": PATH_ESRS_CLASSIFIER,
    "esrs_columns.pkl": PATH_ESRS_COLUMNS,
}


def filter_known_labels(labels: list[str], known_labels) -> list[str]:
    known_label_set = set(known_labels)

    return [label for label in labels if label in known_label_set]


def validate_model_artifacts(data_dir: str | Path | None = None):
    if data_dir is None:
        paths = [Path(path) for path in MODEL_ARTIFACTS.values()]
    else:
        base_path = Path(data_dir)
        paths = [base_path / filename for filename in MODEL_ARTIFACTS.keys()]

    for path in paths:
        if not path.is_file():
            raise FileNotFoundError(2, "No such file", str(path))


# ----------------------------------------------------------------
#  SERVICE version
# ----------------------------------------------------------------

# Load company data
def load_data(company_data: CompanyData):
    df = pd.DataFrame([company_data.model_dump()])

    # load sector columns from training
    sector_columns = joblib.load(PATH_SECTOR_COLUMNS)
    mlb = MultiLabelBinarizer(classes=sector_columns)
    mlb.fit([])  # Needed to initialize

    # create sector flags
    df['sector_list'] = df['sector_list'].apply(lambda labels: filter_known_labels(labels, sector_columns))
    sector_flags = pd.DataFrame(mlb.transform(df['sector_list']), columns=mlb.classes_, index=df.index)

    # other features
    x_numeric_categorical = df[['headquarters_country',
                                'employees_total',
                                'annual_turnover_million_euro',
                                'num_subsidiaries_countries',
                                'stock_listed',
                                'reporting_currency']]

    return pd.concat([sector_flags, x_numeric_categorical], axis=1)


# Predict esrs of a company
def predict_esrs(company_data: CompanyData):
    # load company data and ML classifier
    print("Loading company data...")
    df = load_data(company_data)
    clf = joblib.load(PATH_ESRS_CLASSIFIER)
    esrs_columns = joblib.load(PATH_ESRS_COLUMNS)

    # conduct prediction
    print("Start prediction...")
    start = time.time()
    with warnings.catch_warnings():
        warnings.filterwarnings(
            "ignore",
            message=LIGHTGBM_FEATURE_NAME_WARNING,
            category=UserWarning,
        )
        predictions = clf.predict(df)
    end = time.time()
    print(f"End prediction {end - start:.2f} seconds")

    # return prediction
    prediction = Prediction(esrs={})
    if len(predictions) == 1:
        prediction.esrs.update(dict(zip(esrs_columns, [int(p) for p in predictions[0]])))
    return prediction


# ----------------------------------------------------------------
#  MAIN version
# ----------------------------------------------------------------

# Load company data
def main_load_data(input_file, classifier_path):
    df = pd.read_csv(input_file, sep=';')
    file_values = df['file'].tolist()   # Save 'file' column values as a list
    df.drop(columns='file', inplace=True)  # Drop the column from DataFrame
    # Split sector strings into lists
    df['sector_list'] = df['sector'].str.split(',')

    # Load sector columns from training
    sector_columns = joblib.load(classifier_path + '/sector_columns.pkl')
    mlb = MultiLabelBinarizer(classes=sector_columns)
    mlb.fit([])  # Needed to initialize

    # Create sector flags
    df['sector_list'] = df['sector_list'].apply(lambda labels: filter_known_labels(labels, sector_columns))
    sector_flags = pd.DataFrame(mlb.transform(df['sector_list']), columns=mlb.classes_, index=df.index)

    # Other features
    X_numeric_categorical = df[['headquarters_country',
                                'employees_total',
                                'annual_turnover_million_euro',
                                'num_subsidiaries_countries',
                                'stock_listed',
                                'reporting_currency']]

    X = pd.concat([sector_flags, X_numeric_categorical], axis=1)

    return X, file_values


def main(company_data, classifier_path, output_path):
    print(f"Loading data {company_data}")
    df, file_names = main_load_data(company_data, classifier_path)
    clf = joblib.load(classifier_path+'/esrs_classifier.pkl')
    esrs_columns = joblib.load(classifier_path+'/esrs_columns.pkl')

    print(f"Start prediction.")
    start = time.time()
    with warnings.catch_warnings():
        warnings.filterwarnings(
            "ignore",
            message=LIGHTGBM_FEATURE_NAME_WARNING,
            category=UserWarning,
        )
        predictions = clf.predict(df)
    end = time.time()
    print(f"End prediction {end - start:.2f} seconds")

    decoded_predictions = {}
    index = 0
    for row in predictions:
        # Get ESRS names where prediction == 1
        decoded_predictions[file_names[index]] = [esrs for esrs, val in zip(esrs_columns, row) if val == 1]
        index = index + 1

    for key, value in decoded_predictions.items():
        print(f"name: {key}.\nESRs: {value}")
        print("-------------------------------------")

    if output_path:
        predictions_df = pd.DataFrame(predictions, columns=esrs_columns)
        predictions_df.insert(0, 'file', file_names)
        predictions_df.to_csv(output_path, index=False, sep=";")


# Example usage
if __name__ == "__main__":
    argument_parser = ArgumentParser(description='Predict sustainability data out of company data')
    argument_parser.add_argument('--company_data', default='./training_data/company_data_prev.csv',
                                 help='path to csv file with company data')
    argument_parser.add_argument('--classifier_path', default='./trained_classifier/',
                                 help='path where classifier parameters are stored')
    argument_parser.add_argument('--store_prediction', default='./trained_classifier/predictions.csv',
                                 help='path where classifier predictions are stored')
    args = argument_parser.parse_args()
    main(args.company_data, args.classifier_path, args.store_prediction)
