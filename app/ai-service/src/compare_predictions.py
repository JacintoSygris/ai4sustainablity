import pandas as pd
import argparse


def compare_csvs(file1, file2, verbose = False):
    # Load CSVs, using first column as index
    print(f"Comparing {file1} and {file2}")
    df1 = pd.read_csv(file1, index_col=0, sep=";")
    df2 = pd.read_csv(file2, index_col=0, sep=";")

    # Align columns and index for comparison (take intersection to avoid false diffs)
    common_index = df1.index.intersection(df2.index)
    common_columns = df1.columns.intersection(df2.columns)

    df1 = df1.loc[common_index, common_columns]
    df2 = df2.loc[common_index, common_columns]

    # Compute number of cells
    total_cells = df1.shape[0] * df1.shape[1]

    #Compute 1's
    ones_df1 = df1.sum().sum()
    ones_df2 = df2.sum().sum()

    # Find differences: create a boolean DataFrame where values differ
    diff_mask = df1 != df2

    # Count how many cells differ
    num_different_cells = diff_mask.sum().sum()

    # Count how many rows have at least one difference
    num_different_rows = (diff_mask.any(axis=1)).sum()

    print(f"\nTotal differing rows: {num_different_rows}")
    print(f"Total differing cells: {num_different_cells}\n")
    print(f"Total cells: {total_cells}\n")
    print(f"Percentage different: {100*num_different_cells/total_cells:.3f}%")
    print(f"Percentage 1's df1: {100 * ones_df1 / total_cells:.3f}%")
    print(f"Percentage 1's df2: {100 * ones_df2 / total_cells:.3f}%")

    # Iterate over all differences and print
    error_1_0 = 0
    error_0_1 = 0
    for row_id in diff_mask.index:
        for col in diff_mask.columns:
            if diff_mask.loc[row_id, col]:
                val1 = df1.loc[row_id, col]
                val2 = df2.loc[row_id, col]
                if val1 == 0:
                    error_0_1 = error_0_1 + 1
                else:
                    error_1_0 = error_1_0 + 1
                if verbose:
                    print(f"Difference in row '{row_id}', column '{col}': '{val1}' vs '{val2}'")
    print(f"0 vs 1 error: {100 * error_0_1 / num_different_cells:.3f}%")
    print(f"1 vs 0 error: {100 * error_1_0 / num_different_cells:.3f}%")

if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Compare two CSV files and show differences")
    parser.add_argument('--csv1', help='First CSV file path',
                        default='../training_data/all_gpt-4.1-nano_new_format.csv')
    parser.add_argument('--csv2', help='Second CSV file path',
                        #default='training_data/chunks/all_batch2_gpt-4.1-nano.csv')
                        default='../trained_classifier/predictions.csv')
    parser.add_argument('--verbose',action='store_true',help='Enable verbose output')

    args = parser.parse_args()

    compare_csvs(args.csv1, args.csv2, args.verbose)
