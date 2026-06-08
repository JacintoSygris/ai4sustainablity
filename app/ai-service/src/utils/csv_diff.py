import sys
import pandas as pd

if len(sys.argv) != 3:
    print("Usage: python csv_diff.py <file1.csv> <file2.csv>")
    sys.exit(1)

file1 = sys.argv[1]
file2 = sys.argv[2]

# Load CSVs
df1 = pd.read_csv(file1, sep=";")
df2 = pd.read_csv(file2, sep=";")

# Extract key column
set1 = set(df1["file"])
set2 = set(df2["file"])

# Rows present in file1 but not file2
only_in_file1 = df1[df1["file"].isin(set1 - set2)]

# Rows present in file2 but not file1
only_in_file2 = df2[df2["file"].isin(set2 - set1)]

print(f"Rows only in {file1}:")
print(only_in_file1)

print(f"\nRows only in {file2}:")
print(only_in_file2)
