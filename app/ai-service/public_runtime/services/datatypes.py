from pydantic import BaseModel, Field, StrictInt, field_validator
from datetime import date, datetime
from enum import Enum
from typing import Any, Optional


class CompanyData(BaseModel):
    model_profile: Optional[str] = None
    learning_shadow_authorized: bool = Field(default=False, strict=True)
    learning_shadow_subject: str | None = Field(default=None, pattern=r"^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$")
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


class DocumentExtractionRequest(BaseModel):
    """POST /extract-document request (contract p6-document-extraction-v0.md).

    Path-based request used by the web application; the supplied path is
    validated against the ESRS_UPLOAD_EXTRACT_BASE allowlist before use.
    """

    document_id: str
    document_path: str
    original_filename: str
    sha256: str


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


# Closed request/response vocabulary; text is never stripped or translated.
from typing import Literal
from uuid import UUID
from pydantic import ConfigDict, model_validator


class XbrlStrictModel(BaseModel):
    model_config = ConfigDict(extra="forbid", strict=True)


class XbrlIndicator(XbrlStrictModel):
    label: str = Field(max_length=300)
    description: str = Field(max_length=4000)
    language: str = Field(min_length=2, max_length=16, pattern=r"^[a-zA-Z-]+$")
    standard: str | None = Field(default=None, pattern=r"^(ESRS 2|E[1-5]|S[1-4]|G1)$")
    dr: str | None = Field(default=None, max_length=40, pattern=r"^(BP-[12]|GOV-[1-5]|SBM-[1-3]|IRO-[12]|MDR-[PAMT]|(?:E[1-5]|S[1-4]|G1)-[0-9]{1,2})$")
    value_type: Literal["text", "number", "monetary", "integer", "boolean", "enumeration", "date", "nil"] | None = None
    unit: str | None = Field(default=None, min_length=1, max_length=64)
    period_type: Literal["instant", "duration"] | None = None

    @model_validator(mode="after")
    def descriptive_text(self):
        from .xbrl_runtime_context import supported_context

        if not (self.label.strip() or self.description.strip()):
            raise ValueError("descriptive_text_required")
        if not supported_context(self.model_dump()):
            raise ValueError("unsupported_context")
        return self


class XbrlConceptRequest(XbrlStrictModel):
    schema_version: Literal["xbrl-runtime-identification-v1"]
    request_id: UUID
    taxonomy_profile: str = Field(min_length=1, max_length=100, pattern=r"^[A-Za-z0-9_.-]+$")
    catalog_sha256: str = Field(pattern=r"^[a-f0-9]{64}$")
    indicator: XbrlIndicator


class XbrlCandidate(XbrlStrictModel):
    qname: str = Field(max_length=500, pattern=r"^esrs:[A-Za-z_][A-Za-z0-9_]*$")
    rank: int = Field(ge=1, le=5)
    score: float = Field(ge=0, le=1, allow_inf_nan=False)
    validation_status: Literal["map_only", "unverified", "verified_taxonomy"]
    validation_reasons: list[str] = Field(max_length=5)


class XbrlConceptResponse(XbrlStrictModel):
    schema_version: Literal["xbrl-runtime-identification-v1"]
    request_id: str
    model_version: str = Field(min_length=1, max_length=100)
    artifact_sha256: str = Field(pattern=r"^[a-f0-9]{64}$")
    concept_map_sha256: str = Field(pattern=r"^[a-f0-9]{64}$")
    catalog_sha256: str = Field(pattern=r"^[a-f0-9]{64}$")
    package_sha256: str | None = Field(pattern=r"^[a-f0-9]{64}$")
    taxonomy_profile: str
    candidates: list[XbrlCandidate] = Field(max_length=5)
    abstained: bool
    reason_codes: list[Literal["unsupported_context", "unsupported_language", "zero_textual_overlap",
                              "no_compatible_candidates", "below_threshold", "insufficient_margin"]] = Field(max_length=1)


# Read-only sector comparison contract. Strict types reject coercion and extras.


class SectorStrictModel(BaseModel):
    model_config = ConfigDict(extra="forbid", strict=True, frozen=True)


class SectorComparisonRequest(SectorStrictModel):
    version: Literal["sector-comparison-v1"]
    sections: list[str] = Field(max_length=22)
    topics: list[str] = Field(min_length=1, max_length=89)

    @field_validator("sections", "topics")
    @classmethod
    def unique_codes(cls, values, info):
        import re
        pattern = r"[A-V]" if info.field_name == "sections" else r"(?:[1-9]|[1-8][0-9])"
        if len(set(values)) != len(values) or any(re.fullmatch(pattern, v) is None for v in values):
            raise ValueError("invalid or duplicate vocabulary")
        return values


class SectorHashes(SectorStrictModel):
    labels: str = Field(pattern=r"^[0-9a-f]{64}$")
    masks: str = Field(pattern=r"^[0-9a-f]{64}$")
    companies: str = Field(pattern=r"^[0-9a-f]{64}$")
    encoding: str = Field(pattern=r"^[0-9a-f]{64}$")
    topic_mapping: str = Field(pattern=r"^[0-9a-f]{64}$")


class SectorProvenance(SectorStrictModel):
    label_kind: Literal["automatic", "human", "mixed", "synthetic"]
    source_reference: str = Field(pattern=r"^[A-Za-z0-9_-]{1,128}$")
    authorization_reference: str = Field(pattern=r"^[A-Za-z0-9_-]{1,128}$")


class SectorPeriod(SectorStrictModel):
    start: str
    end: str

    @model_validator(mode="after")
    def dates(self):
        if date.fromisoformat(self.start).isoformat() != self.start or date.fromisoformat(self.end).isoformat() != self.end or self.start > self.end:
            raise ValueError("invalid coverage period")
        return self


class SectorCounts(SectorStrictModel):
    n_scored: int = Field(ge=0, le=1000000000)
    n_material: int = Field(ge=0, le=1000000000)

    @model_validator(mode="after")
    def bounds(self):
        if self.n_material > self.n_scored:
            raise ValueError("material exceeds support")
        return self


class SectorPopulation(SectorStrictModel):
    population_companies: int = Field(ge=0, le=1000000000)
    topics: dict[str, SectorCounts]

    @model_validator(mode="after")
    def population_bounds(self):
        if set(self.topics) != {str(i) for i in range(1, 90)} or any(s.n_scored > self.population_companies for s in self.topics.values()):
            raise ValueError("invalid vocabulary or population")
        return self


class SectorSupportModel(SectorStrictModel):
    min_support: Literal[5]

    @field_validator("min_support", mode="before")
    @classmethod
    def integer_support(cls, value):
        if type(value) is not int:
            raise ValueError("support must be an integer")
        return value


class SectorArtifact(SectorSupportModel):
    version: Literal["sector-comparison-v1"]
    built_at: str
    selection_policy: Literal["operator-selected-report-per-verified-company"]
    aggregation_policy: Literal["known-positive-any-known-negative-all-approved-keys"]
    sector_taxonomy: Literal["NACE Rev.2.1 / CNAE-2025"]
    min_support: Literal[5]
    hashes: SectorHashes
    provenance: SectorProvenance
    coverage_period: SectorPeriod
    vocabulary: list[str]
    excluded_additional_reports: int = Field(ge=0)
    global_population: SectorPopulation
    sections: dict[str, SectorPopulation]

    @model_validator(mode="after")
    def complete(self):
        stamp = datetime.fromisoformat(self.built_at.replace("Z", "+00:00"))
        if stamp.utcoffset() is None or stamp.utcoffset().total_seconds() != 0:
            raise ValueError("build time must be UTC")
        if self.vocabulary != [str(i) for i in range(1, 90)] or set(self.sections) != set("ABCDEFGHIJKLMNOPQRSTUV"):
            raise ValueError("incomplete vocabulary")
        g = self.global_population
        for section in self.sections.values():
            if section.population_companies > g.population_companies:
                raise ValueError("section population exceeds global")
            for key, stat in section.topics.items():
                glob = g.topics[key]
                if stat.n_material > glob.n_material or stat.n_scored - stat.n_material > glob.n_scored - glob.n_material:
                    raise ValueError("section is not a subset of global")
        return self


class SectorTopicComparison(SectorStrictModel):
    topic_id: str
    source: Literal["sector", "global_fallback", "insufficient_data"]
    low_confidence: bool
    proportion: float | None
    sector: SectorCounts
    global_counts: SectorCounts


class SectorSectionComparison(SectorStrictModel):
    section: str | None
    population_companies: int
    topics: list[SectorTopicComparison]


class SectorComparisonResponse(SectorSupportModel):
    version: Literal["sector-comparison-v1"]
    artifact_sha256: str = Field(pattern=r"^[0-9a-f]{64}$")
    built_at: str
    hashes: SectorHashes
    provenance: SectorProvenance
    coverage_period: SectorPeriod
    sector_taxonomy: Literal["NACE Rev.2.1 / CNAE-2025"]
    min_support: Literal[5]
    global_population_companies: int
    comparisons: list[SectorSectionComparison]
