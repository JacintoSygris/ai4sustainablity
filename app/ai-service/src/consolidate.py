from argparse import ArgumentParser
import os
import csv
import re
from enum import Enum


class ConsolidationOperation(Enum):
    MAJORITY = 1      # whichever there is more of, 0s or 1s
    AVERAGE = 2       # the average of the sum of 0s and 1s
    AT_LEAST_ONE = 3  # 1 if there is any 1; 0 if all are 0s

    def run(self, binary_vector: list[int]):
        if self == ConsolidationOperation.MAJORITY:
            if binary_vector.count(0) > binary_vector.count(1):
                return 0  # 0s > 1s
            else:
                return 1  # 1s >= 0s
        elif self == ConsolidationOperation.AVERAGE:
            return sum(binary_vector) / len(binary_vector)
        elif self == ConsolidationOperation.AT_LEAST_ONE:
            if binary_vector.count(1) > 0:
                return 1
            else:
                return 0
        else:
            raise Exception(f'Unsupported consolidation operation {self.name}.')


def is_csv(file_name: str) -> bool:
    _, extension = os.path.splitext(file_name)
    return extension.lower() == '.csv'


def get_csv_files(path: str) -> list[str]:
    csv_files = []
    if os.path.isdir(path):
        for file_name in os.listdir(path):
            if is_csv(file_name):
                csv_files.append(os.path.join(path, file_name))
    else:
        if is_csv(path):
            csv_files.append(path)
    return csv_files


def create_derived_folder(original_path: str, added_suffix: str) -> str:
    if not os.path.exists(original_path):
        raise Exception(f'Path not found: {original_path}.')
    elif os.path.isdir(original_path):
        output_folder = f'{original_path}{added_suffix}'
    else:
        output_folder = f'{os.path.dirname(original_path)}{added_suffix}'
    if not os.path.exists(output_folder):
        os.makedirs(output_folder, exist_ok=True)
    return output_folder


def binarize(path: str, csv_delimiter: str = ';'):
    output_folder = create_derived_folder(path, '-binary')
    for csv_file in get_csv_files(path):
        # read
        with (open(csv_file, mode='r', encoding='utf-8', newline='') as infile):
            reader = csv.reader(infile, delimiter=csv_delimiter)
            with open(f'{output_folder}/{os.path.basename(csv_file)}', mode='w', encoding='utf-8', newline='') as outfile:
                writer = csv.writer(outfile, delimiter=csv_delimiter)
                for row_count, row in enumerate(reader):
                    if row_count == 0:
                        writer.writerow(row)
                    else:
                        for cell_count, cell in enumerate(row):
                            # binarize
                            if cell_count > 0:
                                if (re.search(r'^None', cell, re.IGNORECASE) or
                                   re.search("this section is marked as 'None'", cell, re.IGNORECASE) or
                                   re.search("the response is 'None'", cell, re.IGNORECASE) or
                                   re.search("null", cell, re.IGNORECASE) or
                                   cell.upper() in ['FALSE', 'N/A']):
                                    row[cell_count] = 0
                                else:
                                    row[cell_count] = 1
                        # write
                        writer.writerow(row)
    print(f'[Finished] Output data: {output_folder}')


def consolidate(path: str, operation: ConsolidationOperation, csv_delimiter: str = ';'):
    # read binary data
    binary_matrix = {}
    for csv_file in get_csv_files(path):
        with open(csv_file, mode='r', encoding='utf-8', newline='') as infile:
            reader = csv.DictReader(infile, delimiter=csv_delimiter)
            # build binary matrix
            # { file1 = { esrs1 = [1,0,1,...], esrs2= [0,0,0...], ...},
            #   file2 = { esrs1 = [1,0,1,...], esrs2= [0,0,0...], ...},
            #   ... }
            for row in reader:
                file_name = row['file']
                file_data = binary_matrix.get(file_name, {})
                if file_name not in binary_matrix:
                    binary_matrix[file_name] = file_data
                for key, value in row.items():
                    if key != 'file' and value is not None:
                        esrs_data = file_data.get(key, [])
                        esrs_data.append(int(value))
                        if key not in file_data:
                            file_data[key] = esrs_data

    # consolidate binary values
    consolidated_matrix = []
    consolidated_header = ['file']
    for file_name, esrss in binary_matrix.items():
        consolidated_row = {'file': file_name}
        for esrs_name, esrs_data in esrss.items():
            consolidated_row[esrs_name] = operation.run(esrs_data)
            if esrs_name not in consolidated_header:
                consolidated_header.append(esrs_name)
        consolidated_matrix.append(consolidated_row)

    # write consolidated values
    output_folder = create_derived_folder(path, '-consolidated')
    with open(f'{output_folder}/consolidated.csv', mode='w', newline='', encoding='utf-8') as outfile:
        writer = csv.DictWriter(outfile, delimiter=csv_delimiter, fieldnames=consolidated_header)
        writer.writeheader()
        writer.writerows(consolidated_matrix)
    print(f'[Finished] Output data: {output_folder}')


if __name__ == '__main__':
    argument_parser = ArgumentParser(description='Data binarization and consolidation')
    argument_parser.add_argument('--path', required=True, default='./data', help='path to folder with csv files, or specific csv file')
    group = argument_parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--binarize", action='store_true', help="binarize the file(s) in <path>")
    group.add_argument("--consolidate", required=False, choices=ConsolidationOperation.__members__.keys(), help="consolidate the file(s) in <path>")

    args = argument_parser.parse_args()

    if args.binarize:
        binarize(args.path)
    elif args.consolidate:
        consolidate(args.path, ConsolidationOperation[args.consolidate])
