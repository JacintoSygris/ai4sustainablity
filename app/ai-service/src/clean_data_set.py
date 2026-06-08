import csv
import re
import time
import json
import sys
from openai import OpenAI

MODEL = "gpt-4.1-mini"
KEYS_FILE = "keys.properties"


# --- Load API key from properties file ---
def load_api_key(filepath):
    props = {}
    with open(filepath) as f:
        for line in f:
            if "=" in line and not line.strip().startswith("#"):
                k, v = line.split("=", 1)
                props[k.strip()] = v.strip()

    if "OPENAI_API_KEY" not in props:
        raise ValueError("OPENAI_API_KEY not found in keys.properties")

    return props["OPENAI_API_KEY"]


# --- Validate CLI arguments ---
if len(sys.argv) != 3:
    print("Usage: python script.py <input_csv> <output_csv>")
    sys.exit(1)

INPUT_FILE = sys.argv[1]
OUTPUT_FILE = sys.argv[2]


api_key = load_api_key(KEYS_FILE)
client = OpenAI(api_key=api_key)


# --- Approximate FX rates to EUR ---
FX_RATES = {
    "EUR": 1.0,
    "USD": 0.92,
    "NOK": 0.086,
    "SEK": 0.087,
    "MSEK": 0.087,
    "DKK": 0.134,
    "GBP": 1.17,
    "PLN": 0.23,
    "RON": 0.20,
    "CZK": 0.040,
    "CHF": 1.04,
    "JPY": 0.0062,
    "SGD": 0.68
}

def extract_json_block(text):
    import re
    match = re.search(r"\{.*\}", text, re.DOTALL)
    if match:
        return match.group(0)
    raise ValueError("No JSON object found")

def extract_structured_data(raw_value):
    prompt = f"""
Extract structured financial data from this string.

Return ONLY JSON:
{{
  "value": number,
  "unit": "million" or "billion",
  "currency": "EUR/USD/NOK/SEK/MSEK/DKK/GBP/PLN/RON/CZK/CHF/JPY/SGD"
}}

Return ONLY raw JSON.
DO NOT use markdown.
DO NOT wrap in ```.

Rules:
- Convert commas/dots correctly
- Detect "MSEK" as million SEK
- If no unit → assume million

Input:
{raw_value}
"""

    response = client.responses.create(
        model=MODEL,
        input=prompt,
        temperature=0
    )

    text = response.output[0].content[0].text.strip()
    return json.loads(extract_json_block(text))


def convert_to_million_eur(structured):
    value = structured["value"]
    unit = structured["unit"]
    currency = structured["currency"]

    if unit == "billion":
        value *= 1000

    if currency == "MSEK":
        currency = "SEK"

    rate = FX_RATES.get(currency)
    if rate is None:
        raise ValueError(f"Unsupported currency: {currency}")

    return value * rate


def process_csv():
    with open(INPUT_FILE, newline='', encoding='utf-8') as infile, \
         open(OUTPUT_FILE, 'w', newline='', encoding='utf-8') as outfile:

        reader = csv.DictReader(infile, delimiter=';')
        fieldnames = reader.fieldnames

        writer = csv.DictWriter(outfile, fieldnames=fieldnames, delimiter=';')
        writer.writeheader()

        for row in reader:
            raw_value = row["company_data_annual_turnover"]

            try:
                print(f"Reading data of: {row['file']}")
                structured = extract_structured_data(raw_value)
                eur_value = convert_to_million_eur(structured)
                print(f"Converted to: {eur_value}")

            except Exception as e:
                print(f"Error: {raw_value} -> {e}")
                eur_value = None

            row["company_data_annual_turnover"] = eur_value
            print(f"Writing row: {row}")
            writer.writerow(row)

            time.sleep(0.3)


if __name__ == "__main__":
    process_csv()
