from typing import List, Optional

import yaml
from openpyxl.worksheet.filters import DynamicFilter
from pydantic import BaseModel, Field, create_model, ConfigDict

from features.feature_description import ESRSBase
from tasks.tasks import Task


class Theme(BaseModel):
    name: str
    description: str
    extractContext: bool = False
    format: str = 'str'
    subthemes: Optional[List['Theme']] = None
    parent: Optional['Theme'] = Field(default=None, exclude=True) # set when calling get_model_fields

    def prompt(self):
        return self.name + ": "+self.description

    def get_var_name(self):
        if self.parent is not None:
            return self.parent.get_var_name() + '_' + self.my_name()
        return self.my_name()

    def my_name(self):
        return self.name.replace(" ", "_").lower()

    def get_fields(self, parent_theme = None) -> List['Theme']:
        if parent_theme is not None:
            self.parent = parent_theme
        if not self.subthemes:
            return [self]
        fields = []     # do not include self
        for st in self.subthemes:
            fields.extend(st.get_fields(self))
        return fields

    def subthemes_prompt(self, indent) -> str:
        st_prompt = ''
        if self.subthemes is None:
            return st_prompt
        for theme in self.subthemes:
            st_prompt += indent+'- '+theme.prompt()+'\n'
            st_prompt += theme.subthemes_prompt(indent + indent)
        return st_prompt


Theme.update_forward_refs()

class YAMLTaskModel(BaseModel):
    active: bool = True
    name: str
    description: str
    isESR: bool = True
    summaryField : bool = True       # whether we want a boolean flag reporting whether the task was relevant in the document or not
    themes: List[Theme] = Field(alias='fields')

    model_config = ConfigDict(populate_by_name=True)

    def get_model_fields(self):
        #return {
        #    self.get_var_name(theme): (eval(theme.format), Field(..., description=theme.description))
        #    for theme in self.themes
        #}
        fields = {}
        if self.summaryField:
            fields[self.get_model_name()+'_summary'] = (bool, Field(..., description=self.task_summary_description()))
        for rtheme in self.themes:
            theme_fields = rtheme.get_fields()
            for theme in theme_fields:
                fields[self.get_var_name(theme)] = (eval(theme.format), Field(..., description=theme.description + " If the document does not report on this theme, just return the word 'None'."))
                if theme.extractContext:
                    context_description = (f'verbatim quote of the information found in the document, which you used in'
                                           f'field {self.get_var_name(theme)}, '
                                           f'indicating page number (page X). Truncate if the paragraph is too long.')
                    fields[self.get_context_var_name(theme)] = (str, Field(..., description=context_description))
        return fields

    def task_summary_description(self):
        if self.summaryField:
            return f"""This is a summary field (a Boolean) of the relevance of the theme. Return True if the document
                    reports {self.name} ({self.description}) as relevant for the company, False otherwise.
                    Logically, you can only return True in this summary field if any of the subthemes is relevant (if you reported None for all subthemes,
                    you cannot report here True, but you should return False)."""
        return ''

    def get_var_name(self, theme: Theme):
        return self.get_model_name()+'_'+theme.get_var_name()

    def get_context_var_name(self, theme: Theme):
        return self.get_model_name()+'_'+theme.get_var_name()+'_context'

    def get_model_name(self):
        return self.name.replace(" ", "_").lower()


class YAMLRootModel(BaseModel):
    task: YAMLTaskModel


class YAMLDefinedTask(Task):
    def __init__(self, path):
        self.__path = path
        with open(path, 'r') as file:
            yaml_data = yaml.safe_load(file)

        self.__model = YAMLRootModel(**yaml_data)
        print(f"Extracting: {self.__model}")

    def esr_task_prompt(self):
        return f"""
        You are an assistant specialized in extracting sustainability-related data from corporate reports in line with the
        European Sustainability Reporting Standards (ESRS) under the Corporate Sustainability Reporting Directive (CSRD).
        Your task is to analyze the provided corporate reports and extract only the information that is explicitly reported
        by the company in relation to {self.__model.task.name} -- {self.__model.task.description}.

        OBJECTIVE

        Determine whether the company discloses any information aligned with {self.__model.task.name}, and if so, extract the reported
        data related to the following key thematic areas.

        DISCLOSURE AREAS

        For each of the following topics, extract reported data if available. If nothing is reported in the document, or if there is no
        explicit information on the topic in the document, return just "None" (and nothing else, do not add an explanation in that case)
        """

    def company_data_prompt(self):
        return f"""
        You are an assistant specialized in extracting sustainability-related data from corporate reports in line with the
        European Sustainability Reporting Standards (ESRS) under the Corporate Sustainability Reporting Directive (CSRD).
        Your task is to analyze the provided corporate reports and extract only the information that is explicitly reported.
        In particular, you need to extract the company data listed below.

        OBJECTIVE
        Determine if the report contains company data information, and extract the relevant information as requested below.
        """

    def get_prompt(self):
        if self.__model.task.isESR: return self.esr_task_prompt()
        else: return self.company_data_prompt()

    def prompt(self):
        prompt = super().prompt() + self.get_prompt()
        # now concat the fields
        count = 1
        for theme in self.__model.task.themes:
            prompt += str(count) + '. ' + theme.prompt() + '\n'
            prompt += theme.subthemes_prompt('        ')
            count = count + 1
        return prompt + self.base_instructions

    def is_active(self) -> bool:
        return self.__model.task.active

    def data_format(self):
        model_fields = self.__model.task.get_model_fields()
        model_name = self.__model.task.get_model_name()
        DynamicModel = create_model(model_name, __base__=ESRSBase, **model_fields)
        return DynamicModel

    def task_name(self):
        return self.__model.task.name

    def description(self):
        return self.__model.task.description
