from .excel_processor import ExcelProcessor
from .pdf_processor import PDFProcessor

global processors

processors = [PDFProcessor(), ExcelProcessor()]
