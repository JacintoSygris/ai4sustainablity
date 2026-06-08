from pydantic import BaseModel, Field
from enum import Enum
from typing import Optional


class CompanyData(BaseModel):
    company_name: str
    sector_list: list[str]
    headquarters_country: str
    num_subsidiaries_countries: int
    employees_total: int
    annual_turnover_million_euro: float
    stock_listed: bool
    reporting_currency: str


class CompanyDataAndEsrs(CompanyData):
    esrs: dict[str, int] = Field(description=f"Mapping of esrs to either 1 (relevant) or 0 (irrelevant)")


class Prediction(BaseModel):
    esrs: dict[str, int] = Field(description=f"Mapping of esrs to either 1 (relevant) or 0 (irrelevant)")


class JobStatus(BaseModel):
    class Status(str, Enum):
        running = "running"
        finished = "finished"
        failed = "failed"
        started = "started"
        not_found = "not found"

    job_id: str
    status: Status = Field(description=f"Possible values are {[js.value for js in Status]}")
    error: Optional[str] = None
