from abc import ABC, abstractmethod


class LLMHandler(ABC):
    def __init__(self, api_key: str, model: str):
        self.api_key = api_key
        self.model = model

    @classmethod
    @abstractmethod
    def valid_models(cls, api_key: str) -> list[str]:
        pass

    @classmethod
    def accepts_model(cls, api_key: str, model: str) -> bool:
        return model in cls.valid_models(api_key=api_key)

    @abstractmethod
    def call_llm(self, prompt: str, data: str, output_structure) -> str:
        pass

    @abstractmethod
    def name(self) -> str:
        pass


class UnknownAiModelException(Exception):
    def __init__(self, model: str):
        super().__init__(f"AI model {model} is not supported")


class UnknownApiKeyException(Exception):
    def __init__(self, api_key: str):
        super().__init__(f"Unable to find {api_key} in section 'keys'")
