from typing import List
from utils import utils
from .processor import *
import PyPDF2


class PDFProcessor(Processor):
    def extension(self) -> List[str]:
        return [".pdf"]

    def extract(self, path: str) -> str:
        print(f"[PDF] Processing file {path}")
        text = ""
        with open(path, 'rb') as file:
            reader = PyPDF2.PdfReader(file)
            for i, page in enumerate(reader.pages):
                text += page.extract_text()
                utils.print_progress_bar(i+1, len(reader.pages), prefix='Progress:', suffix='Complete', length=50)
        return text
