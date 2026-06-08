import csv
import argparse
import glob
import os

import pandas as pd


def filter_columns(input_file, output_file):
    with open(input_file, newline='', encoding='utf-8') as f_in:
        reader = csv.DictReader(f_in, delimiter=';', quotechar='"')
        # Select columns ending with '_context'
        filtered_columns = [col for col in reader.fieldnames if not col.endswith('_context')]

        with open(output_file, 'w', newline='', encoding='utf-8') as f_out:
            writer = csv.DictWriter(f_out, fieldnames=filtered_columns, delimiter=';', quoting=csv.QUOTE_MINIMAL, quotechar='"')
            writer.writeheader()
            for row in reader:
                filtered_row = {col: row[col] for col in filtered_columns}
                writer.writerow(filtered_row)
    print(f"Filtered columns ending with '_context' and saved to {output_file}")

# def concatenate_rows(input_files, output_file):
#     if not input_files:
#         print("No input files found for concatenation.")
#         return
#
#     first = True
#     with open(output_file, 'w', newline='', encoding='utf-8') as f_out:
#         writer = None
#
#         for file in input_files:
#             try:
#                 with open(file, newline='', encoding='utf-8') as f_in:
#                     reader = csv.reader(f_in, delimiter=';', quotechar='"', quoting=csv.QUOTE_MINIMAL)
#                     header = next(reader)
#                     if first:
#                         writer = csv.writer(f_out, delimiter=';', quoting=csv.QUOTE_MINIMAL)
#                         writer.writerow(header)
#                         first = False
#                     for row in reader:
#                         writer.writerow(row)
#             except Exception as e:
#                 print(f"Skipping file {file} due to error: {e}")
#
#     print(f"Concatenated rows and saved to {output_file}")

def __unquote(rows):
    for i, row in enumerate(rows):
        for j, cell in enumerate(row):
            if isinstance(cell, str):
                cell = cell.strip()
                if cell.startswith('"') and cell.endswith('"'):
                    rows[i][j] = cell[1:-1]


def concatenate_rows(input_files, output_file):
    # rows = []
    # header = None
    #
    # for file in input_files:
    #      with open(file, newline='', encoding='utf-8-sig') as f:
    #          reader = csv.reader(f, delimiter=';', quotechar='"')
    #          file_header = next(reader)
    #          if header is None:
    #              header = file_header
    #          elif file_header != header:
    #              print(f"Header in {file} does not match. Skipping.")
    #              continue
    #          rows.extend(list(reader))
    #
    # if not rows:
    #     print("No valid rows to write.")
    #     return
    #
    # __unquote(rows)
    #
    # with open(output_file, 'w', newline='', encoding='utf-8') as f_out:
    #     writer = csv.writer(f_out, delimiter=';', quotechar='"', quoting=csv.QUOTE_MINIMAL)
    #     writer.writerow(header)
    #     writer.writerows(rows)
    header = None
    all_lines = []

    for i, file in enumerate(input_files):
        with open(file, encoding='utf-8-sig') as f:
            lines = f.readlines()
            if i == 0:
                header = lines[0].rstrip('\n')  # keep first file header
            # skip first line (header) for every file
            content_lines = lines[1:]
            all_lines.extend(line.rstrip('\n') for line in content_lines)

    print("Total data lines:", len(all_lines))
    with open(output_file, 'w', encoding='utf-8') as f:
        # Write the header first
        f.write(header + '\n')
        # Write all the data lines
        for line in all_lines:
            f.write(line + '\n')

    print(f"Concatenated rows and saved to {output_file}")

def concatenate_columns(input_files, output_file):
    if not input_files:
        print("No input files found for concatenation.")
        return

    data_dict = {}
    headers = ['file_name']  # start with the key column

    for file in input_files:
        try:
            with open(file, newline='', encoding='utf-8') as f_in:
                reader = csv.DictReader(f_in, delimiter=';', quoting=csv.QUOTE_MINIMAL)
                if 'file_name' not in reader.fieldnames:
                    print(f"File {file} does not have 'file_name' column. Skipping.")
                    continue

                # Append new columns except 'file_name'
                new_cols = [col for col in reader.fieldnames if col != 'file_name']
                for col in new_cols:
                    if col not in headers:
                        headers.append(col)

                for row in reader:
                    key = row['file_name']
                    if key not in data_dict:
                        data_dict[key] = {'file_name': key}
                    data_dict[key].update({col: row[col] for col in new_cols})
        except Exception as e:
            print(f"Skipping file {file} due to error: {e}")

    with open(output_file, 'w', newline='', encoding='utf-8') as f_out:
        writer = csv.DictWriter(f_out, fieldnames=headers, delimiter=';', quoting=csv.QUOTE_MINIMAL)
        writer.writeheader()
        for row in data_dict.values():
            # Fill missing keys with empty string
            for col in headers:
                if col not in row:
                    row[col] = ''
            writer.writerow(row)

    print(f"Concatenated columns (on 'file_name') and saved to {output_file}")

def main():
    parser = argparse.ArgumentParser(description="CSV utility script with 3 options.")
    subparsers = parser.add_subparsers(dest='command', required=True)

    parser_filter = subparsers.add_parser('filter', help="Filter columns ending with '_context'")
    parser_filter.add_argument('input_file', help="Input CSV file")
    parser_filter.add_argument('output_file', help="Output CSV file")

    parser_concat_rows = subparsers.add_parser('concat_rows', help="Concatenate multiple CSV files by rows")
    parser_concat_rows.add_argument('pattern', help="Glob pattern for input CSV files (e.g., 'data/*.csv')")
    parser_concat_rows.add_argument('output_file', help="Output CSV file")

    parser_concat_cols = subparsers.add_parser('concat_cols', help="Concatenate multiple CSV files by columns using 'file_name'")
    parser_concat_cols.add_argument('pattern', help="Glob pattern for input CSV files (e.g., 'data/*.csv')")
    parser_concat_cols.add_argument('output_file', help="Output CSV file")

    args = parser.parse_args()

    if args.command == 'filter':
        filter_columns(args.input_file, args.output_file)
    elif args.command == 'concat_rows':
        input_files = glob.glob(args.pattern)
        concatenate_rows(input_files, args.output_file)
    elif args.command == 'concat_cols':
        input_files = glob.glob(args.pattern)
        concatenate_columns(input_files, args.output_file)

if __name__ == "__main__":
    main()
