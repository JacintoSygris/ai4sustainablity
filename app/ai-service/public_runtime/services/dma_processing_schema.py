"""Strict internal API boundary. UTF-8 byte bounds and code-point evidence offsets."""
import hashlib
from typing import Annotated, Literal
from pydantic import BaseModel, ConfigDict, Field, StringConstraints, field_validator, model_validator

Sha = Annotated[str, StringConstraints(min_length=64, max_length=64, pattern=r'^[a-f0-9]{64}$')]
class Strict(BaseModel):
    model_config = ConfigDict(extra='forbid', strict=True)

class DmaIro(Strict):
    iro_id: Sha
    description: Annotated[str, StringConstraints(min_length=1, max_length=2000)]
    source_sha256: Sha
    @field_validator('description')
    @classmethod
    def bounded_utf8(cls, value):
        if len(value.encode('utf-8')) > 2000 or not value.strip() or any(ord(c) < 32 and c not in '\n\r\t' for c in value):
            raise ValueError('invalid_description')
        return value

class DmaProcessingRequest(Strict):
    version: Literal['dma-processing-v1']
    locale: Literal['es', 'en']
    iros: Annotated[list[DmaIro], Field(min_length=1, max_length=20)]
    @model_validator(mode='after')
    def unique(self):
        if len({row.iro_id for row in self.iros}) != len(self.iros):
            raise ValueError('duplicate_iro')
        return self

class Span(Strict):
    start: Annotated[int, Field(ge=0, le=1999)]
    end: Annotated[int, Field(ge=1, le=2000)]
    quote: Annotated[str, StringConstraints(min_length=1, max_length=2000)]
    catalogue_ref: Annotated[str, StringConstraints(min_length=1, max_length=250)]
    @model_validator(mode='after')
    def offsets(self):
        if self.end <= self.start or self.end - self.start != len(self.quote):
            raise ValueError('invalid_span')
        return self

class Candidate(Strict):
    id: Annotated[str, StringConstraints(min_length=1, max_length=100)]
    label: Annotated[str, StringConstraints(min_length=1, max_length=4000)]
    score: Annotated[float, Field(ge=0, le=1, allow_inf_nan=False)]
    spans: Annotated[list[Span], Field(min_length=1, max_length=1)]

class Level(Strict):
    candidates: Annotated[list[Candidate], Field(max_length=3)]
    abstention: Literal['insufficient_evidence', 'ambiguous', 'unsupported_language'] | None
    @model_validator(mode='after')
    def exclusive(self):
        if bool(self.candidates) == (self.abstention is not None) or len({x.id for x in self.candidates}) != len(self.candidates):
            raise ValueError('invalid_candidates')
        return self

class Levels(Strict):
    topic: Level
    dr: Level
    datapoint: Level

class DmaResult(Strict):
    model_version: Literal['dma-processing-v1']
    model_sha256: Sha
    catalogue_version: Literal['ig3-2025-06-ar16-v1']
    catalogue_sha256: Sha
    iro_id: Sha
    description_sha256: Sha
    levels: Levels

class DmaProcessingResponse(Strict):
    version: Literal['dma-processing-v1']
    model_sha256: Sha
    catalogue_version: Literal['ig3-2025-06-ar16-v1']
    catalogue_sha256: Sha
    iros: Annotated[list[DmaResult], Field(min_length=1, max_length=20)]
