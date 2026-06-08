# This is a sample Python script.
import argparse
import os
import csv
from argparse import ArgumentParser
from pathlib import Path
from typing import Type

from features.feature_description import CompanyFeatures
from processors.processor import Processor
from registry.ar16 import ReconciliationReport, build_reconciliation_report, default_paths
from tasks.tasks import *
from tasks.yaml_task import YAMLDefinedTask
from utils import utils
from utils.llm_utils import *
from utils.open_ai_utils import OpenAIHandler
from utils.gemini_utils import GeminiHandler
import time

class Extractor:
    task_map = {'company_data': [ExtractCompanyDataTask()],
                'esrse1': [ExtractESRSE1Task()],
                'esrse2': [ExtractESRSE2Task()],
                'esrse3': [ExtractESRSE3Task()],
                'esrse4': [ExtractESRSE4Task()],
                'esrse5': [ExtractESRSE5Task()],
                'esrss1': [ExtractESRSS1Task()],
                'esrss2': [ExtractESRSS2Task()],
                'esrss3': [ExtractESRSS3Task()],
                'esrss4': [ExtractESRSS4Task()],
                'esrsg1': [ExtractESRSG1Task()],
                'all': [ExtractESRSE1Task(), ExtractESRSE2Task(), ExtractESRSE3Task(), ExtractESRSE4Task(),
                        ExtractESRSE5Task(), ExtractESRSS1Task(), ExtractESRSS2Task(), ExtractESRSS3Task(),
                        ExtractESRSS4Task(), ExtractESRSG1Task()]}

    def __init__(self, vectorise: bool, model: str):
        self.all_values = None
        self.all_headers = None
        self.results_file = None
        self.tasks = None
        self.vectorise = vectorise
        self.model = model
        self.failed_files = []
        self.__init_ai_model(model)

    def __init_ai_model(self, model: str):
        handlers = {OpenAIHandler: ["sygris_extract_esr", "sygris_report_analyser"], GeminiHandler: []}
        for handler in handlers.keys():
            try:
                utils.check_keys([handler.API_KEY_NAME])
                api_key_value = os.environ[handler.API_KEY_NAME]
                if handler.accepts_model(api_key_value, model):
                    self.llm_handler = handler(api_key_value, model, *handlers[handler])
                    print(f"[LLM handler] Using LLM model {model} from {self.llm_handler.name()}")
                    return
                #else:
                #    raise UnknownAiModelException(model)
                #return
            except UnknownApiKeyException:
                if handler == OpenAIHandler:
                    pass
                else:
                    raise
        raise UnknownAiModelException(model)

    def process(self, path: str, results_file: str, tasks: list[str]):
        # self.tasks = [task_map[task] for task in tasks]
        self.tasks = [obj for task in tasks for obj in Extractor.task_map[task]]
        self.results_file = results_file
        if os.path.isdir(path):
            self.process_folder(path)
        else:
            self.process_file(path)

    def process_folder(self, path: str):
        all_files = os.listdir(path)
        print (f"[Processor] Processing {len(all_files)} files")
        num = 1
        for file_name in all_files:
            print(f"[Processor] Processing file {num} of {len(all_files)}")
            self.process_file(os.path.join(path, file_name))
            num += 1

    def __update_results(self, response, file_path: str):
        if not response:
            return
        if not self.all_headers:
            self.all_headers = response.to_header_row()
            self.all_values = response.to_csv_row(os.path.basename(file_path))
        else:
            self.all_headers += ';' + response.to_header_row(False)
            self.all_values += ';' + response.to_csv_row()

    def process_file(self, file_path: str):
        _, extension = os.path.splitext(file_path)
        print(f"[Report processor] Processing: {file_path}")
        processor = Processor.get_processor(extension)
        if processor is None:
            print(f"No processor found for extension {extension}")
            return

        self.all_headers = ''
        self.all_values = ''
        if self.vectorise:  # we vectorise the file
            self.llm_handler.upload_file(file_path)
            print("[Report processor] Extracting...")
            for task in self.tasks:
                response = self.llm_handler.call_with_prompt(file_path, task.prompt(), task.data_format())
                print(f"[Task {task.task_name()}] Response: {response}")  # if None, retry
                self.__update_results(response, file_path)

        if not self.vectorise or response is None:  # do manual trimming
            try:
                text = processor.extract(file_path)
            except:
                print(f"[Processor] Error reading PDF... skipping")
                return
            for task in self.tasks:
                try:
                    response = self.llm_handler.call_llm(task.prompt(), text, task.data_format())
                except Exception as e:
                    print(f"[Task {task.task_name()}] Error in response: {e}. Exiting all remaining tasks")
                    self.failed_files.append(file_path)
                    return
                print(f"[Task {task.task_name()}] Response: {response}")
                self.__update_results(response, file_path)

        empty = not os.path.exists(self.results_file)
        os.makedirs(os.path.dirname(self.results_file), exist_ok=True)
        with open(self.results_file, 'a', encoding='utf-8') as file:
            if empty:
                file.write(self.all_headers)
            file.write('\n')
            file.write(self.all_values)
        print(f"[Report processor] All extracted: {self.all_values}")


def parse_tasks(values):
    """Validate input: must be either a known task or an existing file path."""
    result = []
    for val in values:
        if val in Extractor.task_map:
            result.append(val)
        elif os.path.isfile(val):
            task = add_yaml_task(val)
            if task:
                result.append(task.task_name())
        elif os.path.isdir(val):
            # one for each .yaml file
            yaml_files = list(Path(val).glob("*.yaml")) + list(Path(val).glob("*.yml"))
            yaml_files = [str(p) for p in yaml_files]
            for yaml in yaml_files:
                task = add_yaml_task(yaml)
                if task:
                    result.append(task.task_name())
        else:
            raise argparse.ArgumentTypeError(
                f"'{val}' is not a known task ({list(Extractor.task_map.keys())}) or a valid file path"
            )
    return result


def ensure_ar16_reconciled() -> ReconciliationReport:
    """Fail before external LLM calls when AR16/YAML/Python key drift is unresolved."""
    report = build_reconciliation_report(**default_paths())
    if report.has_unmatched():
        raise RuntimeError(_format_ar16_preflight_error(report))
    return report


def _format_ar16_preflight_error(report: ReconciliationReport) -> str:
    pending = []
    for name, values in report.pending_unmatched.items():
        if values:
            pending.append(f"{name}: {', '.join(values)}")
    if report.invalid_equivalences:
        pending.append(f"invalid_equivalences: {len(report.invalid_equivalences)}")
    return (
        "AR16 reconciliation preflight failed. "
        "Run `python src/ar16_reconcile.py --strict` and resolve: "
        + " | ".join(pending)
    )

def add_yaml_task(file: str) -> Task:
    task = YAMLDefinedTask(file)
    if task.is_active():
        Extractor.task_map[task.task_name()] = [task]
        return task
    return None

if __name__ == '__main__':
    argument_parser = ArgumentParser(description='Extract sustainability data from documents')
    subparsers = argument_parser.add_subparsers(dest="command", required=True, help="Subcommands")

    # --- help subcommand ---
    help_parser = subparsers.add_parser("help", help="Show help info")
    help_parser.add_argument("--models", action='store_true', help="names of accepted AI models")

    # --- config subcommand ---
    config_parser = subparsers.add_parser("config", help="Configure extraction")
    config_parser.add_argument('--tasks', required=False, default=['company_data'],
                               type=str,
                               help=f"task to be performed, one or more of: {', '.join(Extractor.task_map.keys())+', OR path to YAML models'}",
                               #choices=Extractor.task_map.keys(),
                               nargs='+')
    config_parser.add_argument('--path', required=False, default='./data',
                               help='path to a folder with documents, or a specific file')
    config_parser.add_argument('--save', required=False, default='./results.csv',
                               help='name of the file where the extracted data is to be stored')
    config_parser.add_argument('--vectorise', action='store_true',
                               help='vectorise the report before extraction')
    config_parser.add_argument('--model', required=False, default='gpt-4o-mini',
                               help='LLM model used to perform the extraction')
    config_parser.add_argument('--require-ar16-aligned', action='store_true',
                               help='fail before external AI calls if AR16/YAML/Python reconciliation has pending drift')

    # --- parse arguments ---
    args = argument_parser.parse_args()
    try: # custom parse for tasks
        args.tasks = parse_tasks(args.tasks)
    except argparse.ArgumentTypeError as e:
        config_parser.error(str(e))

    if args.command == "config":
        start_time = time.time()
        if args.require_ar16_aligned:
            try:
                report = ensure_ar16_reconciled()
                summary = report.to_dict()["summary"]
                print(
                    "[AR16 preflight] OK: "
                    f"{summary['mapping_entries']} AR16 topics, "
                    f"{summary['yaml_fields']} YAML fields, "
                    f"{summary['resolved_equivalences']} resolved equivalences, "
                    f"{summary['invalid_equivalences']} invalid equivalences."
                )
            except RuntimeError as e:
                config_parser.error(str(e))
        extractor = Extractor(args.vectorise, args.model)
        extractor.process(args.path, args.save, args.tasks)
        end_time = time.time()
        elapsed = end_time - start_time
        minutes = int(elapsed // 60)
        seconds = elapsed % 60
        print(f"[Finished] Time taken: {minutes} minutes and {seconds:.2f} seconds")
        if extractor.failed_files:
            print(f"[Finished] {len(extractor.failed_files)} unprocessed files: {','.join(extractor.failed_files)}")
        else:
            print(f"[Finished] All files processed.")

    elif args.command == "help":
        if args.models:
            for ai_handler in [OpenAIHandler, GeminiHandler]:
                try:
                    utils.check_keys([ai_handler.API_KEY_NAME])
                    api_key = os.environ[ai_handler.API_KEY_NAME]
                    print(f"Supported {ai_handler.__name__[:6]} models: {ai_handler.valid_models(api_key)}")
                except Exception:
                    print(f"Supported {ai_handler.__name__[:6]} models: None (unable to find {ai_handler.API_KEY_NAME})")
        else:
            print("General help:\nUse --models to get more specific help.")
