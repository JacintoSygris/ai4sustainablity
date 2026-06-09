import os
import configparser
import tiktoken
from openai import OpenAI

from features.feature_description import CompanyFeatures
from utils import llm_utils


def check_keys(key_list: list):
    missing_keys = [key for key in key_list if not os.environ.get(key)]
    if missing_keys and os.path.exists('keys.properties'):
        _load_flat_keys_properties('keys.properties', missing_keys)
        missing_keys = [key for key in key_list if not os.environ.get(key)]

    if missing_keys and os.path.exists('keys.properties'):
        config = configparser.ConfigParser()
        try:
            config.read('keys.properties')
        except configparser.Error:
            config = None

        if config is not None and config.has_section('keys'):
            for key, value in config['keys'].items():
                normalized_key = key.upper()
                if normalized_key in missing_keys:
                    os.environ[normalized_key] = value

    for k in key_list:
        if not os.environ.get(k):
            raise llm_utils.UnknownApiKeyException(k)


def _load_flat_keys_properties(filepath: str, key_list: list):
    with open(filepath, encoding='utf-8') as handle:
        for line in handle:
            if "=" not in line or line.strip().startswith("#"):
                continue
            key, value = line.split("=", 1)
            normalized_key = key.strip().upper()
            if normalized_key in key_list:
                os.environ[normalized_key] = value.strip()

def print_progress_bar(iteration, total, prefix='', suffix='', decimals=1, length=100, fill='█', printEnd=''):
    """
    Call in a loop to create terminal progress bar
    @params:
        iteration   - Required  : current iteration (Int)
        total       - Required  : total iterations (Int)
        prefix      - Optional  : prefix string (Str)
        suffix      - Optional  : suffix string (Str)
        decimals    - Optional  : positive number of decimals in percent complete (Int)
        length      - Optional  : character length of bar (Int)
        fill        - Optional  : bar fill character (Str)
        printEnd    - Optional  : end character (e.g. "\r", "\r\n") (Str)
    """
    percent = ("{0:." + str(decimals) + "f}").format(100 * (iteration / float(total)))
    filled_length = int(length * iteration // total)
    bar = fill * filled_length + '-' * (length - filled_length)
    print(f'\r{prefix} |{bar}| {percent}% {suffix}', end=printEnd)
    # Print New Line on Complete
    if iteration == total:
        print()


def call_openai(openai_key: str, prompt: str, data: str, model: str, output_structure) -> str:
    """
    Send a message to OpenAI and returns the answer.
    :param str message: The message to send
    :param str openai_key: OpenAI key
    :return The answer to the message
    """
    # to-do: dictionary with max number of tokens
    #model = "gpt-4o-mini"
    client = OpenAI(api_key=openai_key)
    print("[Report trimmer] Trimming input to max size")
    trimmed_prompt = trim_prompt_to_max_tokens(prompt+"\n\n"+data, 125500, model)
    print(f"[Extract Assistant] Invoking {model}")
    completion = client.beta.chat.completions.parse(
        # model="gpt-3.5-turbo",
        # model="gpt-4o",
        model = model,
        messages=[{"role": "user", "content": trimmed_prompt}],
        temperature= .7,
        response_format=output_structure)
    event = completion.choices[0].message.parsed
    #print(f" {event}\n\n")
    return event

def upload_file(openai_key: str, file_path: str, vector_store_id: str):
    empty_store(openai_key, vector_store_id)
    client = OpenAI(api_key=openai_key)
    with open(file_path, "rb") as file_handle:
        response = client.files.create(file=file_handle, purpose="assistants")
    attach_response = client.vector_stores.files.create(
        vector_store_id=vector_store_id,
        file_id=response.id
    )
    print("Uploaded File ID:", response)
    files = client.files.list()
    print("Files:", files)

    file_id = response.id
    file_info = client.files.retrieve(file_id)
    print("File Info:", file_info)


def create_vector_store(openai_key: str, store_name: str) -> dict:
    client = OpenAI(api_key=openai_key)

    print(f"Creating vector store {store_name}")
    try:
        vector_store = client.vector_stores.create(name=store_name)
        details = {
            "id": vector_store.id,
            "name": vector_store.name,
            "created_at": vector_store.created_at,
            "file_count": vector_store.file_counts.completed
        }
        print("Vector store created:", details)
        return details
    except Exception as e:
        print(f"Error creating vector store: {e}")
        return {}
    print(f"{store_name} created!")


def vector_store_exists(openai_key: str, vector_store_name: str) -> bool:
    client = OpenAI(api_key=openai_key)
    response = client.vector_stores.list()
    for vs in response.data:
        if vs.name == vector_store_name:
            return True
    return False

def empty_store(openai_key: str, vector_store_id: str | None = None):
    client = OpenAI(api_key=openai_key)
    if vector_store_id is None:
        print("[Vector store] No vector_store_id provided; account-wide cleanup is disabled.")
        return

    files = client.vector_stores.files.list(vector_store_id=vector_store_id)
    print("Files:", files)
    try:
        for file in files:
            print(f"removing file {file.id}")
            client.vector_stores.files.delete(file_id=file.id, vector_store_id=vector_store_id)
        print("All empty!")
    except Exception as e:
        print(f"Exception: {e}")

def trim_prompt_to_max_tokens(prompt, max_tokens, model="gpt-4o-mini"):
    # Initialize the tokenizer for the given model
    try:
        tokenizer = tiktoken.encoding_for_model(model)
    except KeyError:
        tokenizer = tiktoken.get_encoding("cl100k_base")

    # Tokenize the prompt
    tokens = tokenizer.encode(prompt)
    print(f"[Token trimmer] Document size is {len(tokens)} tokens")

    # Trim tokens if they exceed the maximum allowed
    if len(tokens) > max_tokens:
        size = len(tokens)
        tokens = tokens[:max_tokens]
        print(f"[Token trimmer] Trimming to {max_tokens} tokens, document size was {size} tokens")
        trimmed_prompt = tokenizer.decode(tokens)
        return trimmed_prompt
    else:
        return prompt
