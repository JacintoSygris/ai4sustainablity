import pandas as pd

# Load the CSV file
input_file = 'C:\\proyectos\\sygris\\IASR-code\\extracted_data\\new_dataset\\companies_new_dataset_cleaned_3.csv'
output_file = 'C:\\proyectos\\sygris\\IASR-code\\extracted_data\\new_dataset\\companies_new_dataset_cleaned_4.csv'

# Read CSV with ';' separator
df = pd.read_csv(input_file, sep=';', encoding='utf8')
df.columns = df.columns.str.strip()

# Define a function to count countries
def count_countries(cell):
    if pd.isna(cell) or cell.strip() == '':
        return 0
    return len([c for c in cell.split(',') if c.strip() != ''])

def remove_spacing(cell):
    return cell.strip()

def boolean_to_english(cell):
    if cell == 'VERDADERO':
        return 'True'
    else:
        return 'False'

# Apply the function to the subsidiaries_countries column
df['num_subsidiaries_countries'] = df['num_subsidiaries_countries'].apply(count_countries)
# for name in ['company_name', 'headquarters_country', 'sector', 'reporting_currency']:
#    df[name] = df[name].apply(remove_spacing)
# df['stock_listed'] = df['stock_listed'].apply(boolean_to_english)

# Save the updated CSV
df.to_csv(output_file, sep=';', index=False, encoding='utf-8')

print(f"Transformed CSV saved to {output_file}")
