from abc import ABC, abstractmethod
from typing import List

import processors


class Processor(ABC):
    @abstractmethod
    def extract(self, path: str) -> List[str]:
        pass

    @abstractmethod
    def extension(self) -> str:
        pass

    @classmethod
    def get_processor(cls, extension: str):
        extension = extension.lower()
        for p in processors.processors:
            if extension in map(str.lower, p.extension()):
                return p
        return None
