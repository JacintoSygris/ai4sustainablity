import re
import time

from openai import OpenAI
from openai.types.responses import ResponseOutputMessage

from features.feature_description import BooleanResponse
from .utils import trim_prompt_to_max_tokens
from utils.llm_utils import LLMHandler


class OpenAIHandler(LLMHandler):
    API_KEY_NAME = "OPENAI_API_KEY"
    max_tokens = {
        "gpt-4o-mini"   : 125500,
        "gpt-4.1-mini"  : 1000000,
        "gpt-4.1"       : 1000000,
        "gpt-4.1-nano"  : 1000000,
        "gpt-5-mini"    : 272000,
        "gpt-5"         : 272000
    }
    unsupported_temperature = ['o1', 'o1-preview', 'o2', 'o3', 'gpt-5', 'gpt-5-mini']
    default_tokens = 125500

    def __init__(self, openai_key: str, model: str, vector_store_name: str, assistant_name: str):
        super().__init__(openai_key, model)
        self.client = OpenAI(api_key=openai_key)
        self.vector_store_name = vector_store_name
        self.create_vector_store()
        self.assistant_name = assistant_name
        self.assistant = None
        self.max_tries = 3

    def supports_temperature(self):
        return self.model not in self.unsupported_temperature

    @classmethod
    def valid_models(cls, api_key: str) -> list[str]:
        models = OpenAI(api_key=api_key).models.list()
        return [model.id for model in models]

    def with_model(self, model: str):
        self.model = model

    def create_assistant(self):
        assistants = self.client.beta.assistants.list()
        for assistant in assistants.data:
            if assistant.name == self.assistant_name:
                self.assistant = assistant
                break
        if self.assistant is None:
            self.assistant = self.client.beta.assistants.create(
                name=self.assistant_name,
                instructions="You are an assistant for extracting sustainability data out of reports",
                model=self.model,
                tools=[{"type": "file_search"}]
            )

    def __run_assistant(self, thread):
        run = self.client.beta.threads.runs.create_and_poll(
            thread_id=thread.id,
            assistant_id=self.assistant.id,
            instructions="Please extract the requested data from the file in the vector store."
        )

        text = ''
        while True:
            if run.status == 'completed':
                messages = self.client.beta.threads.messages.list(thread_id=thread.id)
                text = messages.data[0].content[0].text.value
                print(f'[Extraction agent] Assistant extracted: {text}')
                break
            elif run.status == 'failed':
                print(f"[Extraction agent] Running assistant: {run.status}")
                return "[Extraction agent] Extraction failed"
            else:
                print(f'[Extraction agent] Running assistant: {run.status}')
                time.sleep(3)
        return text

    def call_with_prompt(self, file_path: str, prompt: str, output_structure, process_trials=2) -> dict:
        self.create_assistant()
        self.assistant = self.client.beta.assistants.update(
            assistant_id=self.assistant.id,
            tool_resources={"file_search": {"vector_store_ids": [self.vector_store_id]}},
        )
        thread = self.client.beta.threads.create()

        message = self.client.beta.threads.messages.create(
            thread_id=thread.id,
            role="user",
            content=prompt
        )

        assistant_tries = self.max_tries
        while True:
            assistant_tries -= 1
            text = self.__run_assistant(thread)
            if self.__is_response(text):
                return self.__extract_with_format(text, prompt, output_structure)
            if assistant_tries == 0:
                if process_trials > 0:  # retry whole process
                    self.upload_file(file_path)
                    return self.call_with_prompt(file_path, prompt, output_structure, process_trials - 1)
                else:
                    return None

    def __max_tokens(self) -> int:
        if self.model in OpenAIHandler.max_tokens.keys():
            return OpenAIHandler.max_tokens[self.model]
        return OpenAIHandler.default_tokens

    def call_llm(self, prompt: str, data: str, output_structure) -> str:
        max_size = self.__max_tokens()
        print(f"[Report trimmer] Max window size is {max_size} tokens")
        trimmed_prompt = trim_prompt_to_max_tokens(prompt + "\n\n" + data, max_size, self.model)
        print(f"[Extract Assistant] Invoking {self.model}")

        trials = 3
        while trials>0:
            trials = trials - 1
            try:
                completion = self.client.beta.chat.completions.parse(
                    model=self.model,
                    messages=[{"role": "user", "content": trimmed_prompt}],
                    **({"temperature": 0.0} if self.supports_temperature() else {}),
                    response_format=output_structure)
                event = completion.choices[0].message.parsed
                return event
            except Exception as e:
                print(f"[Extract Assistant Warning] Structured parse failed: {e}")
                print(f"[Extract Assistant Warning] Retrying parsing... (n={trials})")

        print("[Extract Assistant] Could not rely on structured output...")
        print("[Extract Assistant] Falling back to manual completion and parsing...")

        # Fallback to normal completion
        raw_response = self.client.responses.create(
            model=self.model,
            input=trimmed_prompt,
            **({"temperature": 0.0} if self.supports_temperature() else {})
        )

        raw_content = raw_response.output_text
        print(f"[Extract Assistant] extracted {raw_content}")

        n = 3
        while n > 0:
            n = n-1
            try:
                # clean and try parsing with the Pydantic model
                raw_content = self.__clean_json(raw_content)
                parsed = output_structure.model_validate_json(raw_content)
                return parsed
            except Exception as parse_err:
                print(f"[Extract Assistant Warning] Manual parsing failed: {parse_err}")
                print(f"[Extract Assistant Warning] Retrying parsing... (n={n})")
                raw_content = self.__fix_json(raw_content, str(parse_err))
    # print(f" {event}\n\n")

    def __clean_json(self, json_str: str) -> str:
        json_str = json_str.strip()
        json_str = re.sub(r"^```json\s*|^```\s*", "", json_str.strip(), flags=re.IGNORECASE)
        json_str = re.sub(r"\s*```$", "", json_str.strip())
        return json_str

    def __fix_json(self, json:str, error:str):
        fix_prompt = f"""
        The following JSON dictionary has parsing errors:
        - JSON Dictionary:
        {json}
        - Errors:
        {error}

        Your task is to fix the dictionary, preserving the data. Please ONLY return the JSON dictionary, nothing else.
        """
        raw_response = self.client.responses.create(
            model=self.model,
            input=fix_prompt,
            **({"temperature": 0.0} if self.supports_temperature() else {}),
        )
        return raw_response.output_text

    def __extract_with_format(self, text: str, prompt: str, output_structure) -> dict:
        text_input = \
            f"""You are an assistant that extracts sustainability data from summaries of sustainability reports.
            The report summary is the following:
            {text}
            These are additional extraction instructions:
            {prompt}
            """
        completion = self.client.beta.chat.completions.parse(
             model=self.model,
             messages=[{"role": "user", "content": text_input}],
             **({"temperature": 0.0} if self.supports_temperature() else {}),
             response_format=output_structure)
        return completion.choices[0].message.parsed

    def upload_file(self, file_path: str):
        self.__empty_store()
        print(f"[Vector store] Uploading file: {file_path}")
        file_batch = self.client.vector_stores.file_batches.upload_and_poll(
            vector_store_id=self.vector_store_id, files=[open(file_path, "rb")]
        )
        self.__show_files_in_vector_store()

    def __show_files_in_vector_store(self):
        files = self.client.vector_stores.files.list(vector_store_id=self.vector_store_id)
        list_files = [file.id for file in files]
        print(f"[Vector store] Files in {self.vector_store_id}: {list_files}")

    def __empty_store(self):
        self.__show_files_in_vector_store()
        files = self.client.vector_stores.files.list(vector_store_id=self.vector_store_id)
        try:
            for file in files:
                print(f"[Vector store] Removing file {file.id}")
                self.client.vector_stores.files.delete(file_id=file.id, vector_store_id=self.vector_store_id)
            files = self.client.vector_stores.files.list(vector_store_id=self.vector_store_id)
            list_files = [file.id for file in files]
            print(f"[Vector store] Files remaining {list_files}")
        except Exception as e:
            print(f"Exception: {e}")

        files = self.client.files.list()
        list_files = [file.filename for file in files]
        print(f"[File storage] Files: {list_files}")
        try:
            for file in files:
                print(f"[File storage] Removing file {file.filename} (id: {file.id})")
                self.client.files.delete(file.id)
            files = self.client.files.list()
            list_files = [file.filename for file in files]
            print(f"[File storage] Files remaining: {list_files}")
        except Exception as e:
            print(f"Exception: {e}")

    def create_vector_store(self) -> dict:
        vs_dict = self.__vector_store_exists(self.vector_store_name)
        if vs_dict is not None:
            self.vector_store_id = vs_dict['id']
            return vs_dict
        print(f"[Vector store] Creating vector store {self.vector_store_name}")
        try:
            vector_store = self.client.vector_stores.create(name=self.vector_store_name)
            details = {
                "id": vector_store.id,
                "name": vector_store.name,
                "created_at": vector_store.created_at,
                "file_count": vector_store.file_counts.completed
            }
            print("[Vector store] Vector store created:", details)
            self.vector_store_id = vector_store.id
            return details
        except Exception as e:
            print(f"[Vector store] Error creating vector store: {e}")
            return {}
        print(f"{store_name} created!")

    def __vector_store_exists(self, vector_store_name: str) -> dict:
        response = self.client.vector_stores.list()
        for vs in response.data:
            if vs.name == vector_store_name:
                details = {
                    "id": vs.id,
                    "name": vs.name,
                    "created_at": vs.created_at,
                    "file_count": vs.file_counts.completed
                }
                print(f'[Vector store] Vector store found: {vs}')
                return details
        return None

    def __get_vector_store_id(self) -> bool:
        response = self.client.vector_stores.list()
        for vs in response.data:
            if vs.name == self.vector_store_name:
                return vs.id
        return None

    def __is_response(self, text) -> bool:
        text_input = \
            f"""Your task is to identify whether an assistant succeeded in extracting a summary with data from a
              sustainability report. If the assistant did not succeed, it signals there are no files uploaded, and
              does not even extract the company name. The assistant extracted the following summary:

              {text}

              Please respond True or False.
            """
        completion = self.client.beta.chat.completions.parse(
            model=self.model,
            messages=[{"role": "user", "content": text_input}],
            **({"temperature": 0.0} if self.supports_temperature() else {}),
            response_format=BooleanResponse)

        message = completion.choices[0].message
        if message.parsed:
            print("[Summary analyser] extracted succeeded?: ", message.parsed)
            print("[Summary analyser] extracted succeeded?: ", message.parsed.is_True())
            return message.parsed.is_True()
        print("[Summary analyser] extracted succeeded?: (not able to parse)")
        return False

    def name(self) -> str:
        return "OpenAI"
