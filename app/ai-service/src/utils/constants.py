from pathlib import Path


PATH_DATA = str(Path(__file__).resolve().parents[2] / "data")
# data ..........................................
PATH_COMPANY_DATA = f"{PATH_DATA}/company_data.csv"
PATH_COMPANY_ESRS = f"{PATH_DATA}/company_esrs.csv"
# models ........................................
PATH_ESRS_CLASSIFIER = f"{PATH_DATA}/esrs_classifier.pkl"
PATH_ESRS_COLUMNS = f"{PATH_DATA}/esrs_columns.pkl"
PATH_SECTOR_COLUMNS = f"{PATH_DATA}/sector_columns.pkl"
