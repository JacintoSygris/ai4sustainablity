from pydantic import BaseModel, Field, StrictInt
from enum import Enum
from typing import Any, Optional


class CompanyData(BaseModel):
    model_profile: Optional[str] = None
    company_name: str
    sector_list: list[str]
    subsidiaries_regions: list[str] = Field(default_factory=list)
    products_services: list[str] = Field(default_factory=list)
    company_size: Optional[str] = None
    juridic_form: Optional[str] = None
    headquarters_country: str
    num_subsidiaries_countries: int
    employees_total: int
    annual_turnover_million_euro: float
    stock_listed: bool
    reporting_currency: str


class CompanyDataAndEsrs(CompanyData):
    esrs: dict[str, StrictInt] = Field(description=f"Mapping of esrs to either 1 (relevant) or 0 (irrelevant)")


class FeatureMetadata(BaseModel):
    derived_fields: dict[str, Any] = Field(default_factory=dict)
    defaulted_fields: dict[str, Any] = Field(default_factory=dict)
    missing_required_fields: list[str] = Field(default_factory=list)


class Prediction(BaseModel):
    esrs: dict[str, int] = Field(description=f"Mapping of esrs to either 1 (relevant) or 0 (irrelevant)")
    model_profile: Optional[str] = None
    model_key_count: Optional[int] = None
    mapped_key_count: Optional[int] = None
    feature_metadata: FeatureMetadata = Field(default_factory=FeatureMetadata)
    mapping_metadata: dict[str, Any] = Field(default_factory=dict)
    evidence_refs: list[Any] = Field(default_factory=list)


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
