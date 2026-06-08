from google import genai

from utils.llm_utils import LLMHandler
from utils.utils import trim_prompt_to_max_tokens


class GeminiHandler(LLMHandler):
    API_KEY_NAME = "GEMINI_API_KEY"
    max_tokens = {
        "gemini-2.0-flash": 936000,
        "gemini-2.5-flash-preview-04-17": 936000,
        "gemini-2.5-pro": 936000,
        "gemini-2.5-flash": 936000
    }
    default_tokens = 125500

    def __init__(self, gemini_key: str, model: str):
        super().__init__(gemini_key, model)
        self.client = genai.Client(api_key=gemini_key)

    @classmethod
    def valid_models(cls, api_key: str) -> list[str]:
        models = genai.Client(api_key=api_key).models.list()
        return [model.name[len('models/'):] for model in models]

    def __max_tokens(self) -> int:
        if self.model in GeminiHandler.max_tokens.keys():
            return GeminiHandler.max_tokens[self.model]
        return GeminiHandler.default_tokens

    def call_llm(self, prompt: str, data: str, output_structure) -> str:
        max_size = self.__max_tokens()
        print(f"[Report trimmer] Max window size is {max_size}")
        trimmed_prompt = trim_prompt_to_max_tokens(prompt + "\n\n" + data, max_size, self.model)
        print(f"[Extract Assistant] Invoking {self.model}")
        completion = self.client.models.generate_content(
            model=self.model,
            contents=trimmed_prompt,
            config={
                'temperature': 0,
                'response_mime_type': 'application/json',
                'response_schema': output_structure,
            },
        )
        event = completion.parsed
        # print(f" {event}\n\n")
        return event

    def name(self) -> str:
        return "Google AI"
