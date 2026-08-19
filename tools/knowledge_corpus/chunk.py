"""Heading-aware, deterministic preview chunks for governed documents."""

from __future__ import annotations

from collections.abc import Mapping
import re
from typing import Any

from .classify import Classification
from .normalize import NormalizedDocument


_HEADING = re.compile(r"^\s{0,3}(#{1,6})\s+(.+?)\s*$")
_TOKEN = re.compile(r"[\w]+(?:['’-][\w]+)?", re.UNICODE)
_LIST_ITEM = re.compile(r"^\s*(?:[-+*]|\d+[.)])\s+")


def build_chunks(
    document: object,
    target_min: int = 350,
    target_max: int = 700,
) -> list[dict[str, object]]:
    """Build document-isolated chunks with a heading path and governance data."""
    if target_min <= 0 or target_max < target_min:
        raise ValueError("target_min must be positive and not exceed target_max")

    normalized = _normalized(document)
    classification = _classification(document)
    sections = _sections(normalized.markdown)
    metadata = _governance_metadata(document, normalized, classification)
    chunks: list[dict[str, object]] = []
    for section in _combine_short_siblings(sections, target_min, target_max):
        chunks.extend(_split_section(section, target_min, target_max, metadata))
    return chunks


def _normalized(document: object) -> NormalizedDocument:
    normalized = _field(document, "normalized", "document")
    if not isinstance(normalized, NormalizedDocument):
        raise TypeError("document must contain a NormalizedDocument as 'normalized'")
    return normalized


def _classification(document: object) -> Classification:
    classification = _field(document, "classification")
    if not isinstance(classification, Classification):
        raise TypeError("document must contain a Classification as 'classification'")
    return classification


def _field(document: object, *names: str, default: Any = None) -> Any:
    if isinstance(document, Mapping):
        for name in names:
            if name in document:
                return document[name]
        return default
    for name in names:
        if hasattr(document, name):
            return getattr(document, name)
    return default


def _sections(markdown: str) -> list[dict[str, object]]:
    sections: list[dict[str, object]] = []
    path: dict[int, str] = {}
    current: dict[str, object] | None = None
    preamble: list[str] = []

    def flush() -> None:
        nonlocal current
        if current is None:
            return
        content = "\n".join(current["lines"]).strip()
        if content:
            current["content"] = content
            sections.append(current)
        current = None

    for line in markdown.splitlines():
        heading = _HEADING.match(line)
        if heading:
            flush()
            level = len(heading.group(1))
            title = heading.group(2).strip().rstrip("#").rstrip()
            for existing_level in tuple(path):
                if existing_level >= level:
                    del path[existing_level]
            path[level] = title
            ordered_path = tuple(path[index] for index in sorted(path))
            current = {
                "lines": [line.strip()],
                "section_path": " > ".join(ordered_path),
                "parent_path": " > ".join(ordered_path[:-1]),
                "title": title,
            }
        elif current is None:
            preamble.append(line)
        else:
            current["lines"].append(line)
    flush()

    preamble_content = "\n".join(preamble).strip()
    if preamble_content:
        sections.insert(0, {
            "content": preamble_content,
            "section_path": "",
            "parent_path": "",
            "title": "",
        })
    return sections


def _combine_short_siblings(
    sections: list[dict[str, object]], target_min: int, target_max: int
) -> list[dict[str, object]]:
    combined: list[dict[str, object]] = []
    for section in sections:
        if not combined:
            combined.append(dict(section))
            continue
        previous = combined[-1]
        candidate = f"{previous['content']}\n\n{section['content']}"
        if (
            previous["parent_path"] == section["parent_path"]
            and _token_count(str(previous["content"])) < target_min
            and _token_count(candidate) <= target_max
        ):
            previous["content"] = candidate
            previous["section_path"] = str(previous["section_path"])
            continue
        combined.append(dict(section))
    return combined


def _split_section(
    section: dict[str, object], target_min: int, target_max: int, metadata: dict[str, object]
) -> list[dict[str, object]]:
    content = str(section["content"])
    units = _split_content_units(content)
    if not units:
        return []
    heading = units[0] if _HEADING.match(units[0].splitlines()[0]) else ""
    body_units = units[1:] if heading else units
    if not body_units:
        return [_chunk(content, section, metadata)]

    chunks: list[dict[str, object]] = []
    buffer: list[str] = [heading] if heading else []
    body_tokens = 0
    for unit in body_units:
        unit_tokens = _token_count(unit)
        candidate = "\n\n".join([*buffer, unit])
        if body_tokens >= target_min and _token_count(candidate) > target_min:
            chunks.append(_chunk("\n\n".join(buffer), section, metadata))
            buffer = [heading] if heading else []
            body_tokens = 0
        if buffer and _token_count(candidate) > target_max and body_tokens:
            chunks.append(_chunk("\n\n".join(buffer), section, metadata))
            buffer = [heading] if heading else []
            body_tokens = 0
        buffer.append(unit)
        body_tokens += unit_tokens

    if buffer:
        chunks.append(_chunk("\n\n".join(buffer), section, metadata))
    return chunks


def _chunk(content: str, section: dict[str, object], metadata: dict[str, object]) -> dict[str, object]:
    chunk_metadata = dict(metadata)
    chunk_metadata["section_path"] = str(section["section_path"])
    return {
        "content": content,
        "title": str(section["title"]),
        "section_path": str(section["section_path"]),
        "strategy": "heading_rule",
        "metadata": chunk_metadata,
    }


def _split_content_units(content: str) -> list[str]:
    units: list[str] = []
    for block in re.split(r"\n\s*\n", content):
        block = block.strip()
        if not block:
            continue
        lines = block.splitlines()
        if len(lines) > 1 and all(_LIST_ITEM.match(line) for line in lines):
            units.extend(line.strip() for line in lines)
            continue
        units.append(block)
    return units


def _governance_metadata(
    document: object, normalized: NormalizedDocument, classification: Classification
) -> dict[str, object]:
    source_url = str(_field(document, "source_url", "canonical_url", default="")).strip()
    if not source_url and normalized.source_urls:
        source_url = normalized.source_urls[0]
    relative_path = str(_field(document, "relative_path", "source_file_path", "path", default=""))
    return {
        "brand_key": classification.brand_key,
        "site_key": classification.site_key,
        "directory_path": classification.directory_path or "",
        "language_code": classification.language_code,
        "authority_level": classification.authority_level,
        "risk_level": classification.risk_level,
        "review_status": classification.review_status,
        "generation_eligible": classification.generation_eligible,
        "citation_required": classification.citation_required,
        "lifecycle_status": str(_field(document, "lifecycle_status", default="active")),
        "effective_date": _string_or_empty(_field(document, "effective_date", default="")),
        "retrieved_at": _string_or_empty(_field(document, "retrieved_at", default="")),
        "source_url": source_url,
        "source_file_path": relative_path,
        "source_hash": normalized.normalized_hash,
        "document_key": str(_field(document, "canonical_document_key", default=relative_path)),
    }


def _string_or_empty(value: object) -> str:
    if value is None:
        return ""
    return value.isoformat() if hasattr(value, "isoformat") else str(value)


def _token_count(content: str) -> int:
    return len(_TOKEN.findall(content))
