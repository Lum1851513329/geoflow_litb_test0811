"""Deterministic duplicate decisions for governed knowledge documents.

The resolver intentionally keeps the source mappings immutable.  Callers retain
discarded files for their audit manifest; this module only identifies a
canonical source and explains every decision.
"""

from __future__ import annotations

from collections.abc import Mapping, Sequence
from datetime import datetime, timezone
import hashlib
from itertools import combinations
import re
from typing import Any

from .classify import Classification
from .normalize import NormalizedDocument


_TOKEN = re.compile(r"[\w]+(?:['’-][\w]+)?", re.UNICODE)
_MARKDOWN_SUFFIXES = frozenset({".md", ".markdown", ".mdown", ".mkdn"})
_HEADING_LINE = re.compile(r"^\s{0,3}#{1,6}\s+")
# Provenance front matter identifies where a document came from, not what it
# says.  Two mirrors of the same page carry different provenance lines, so the
# body-only signal below has to ignore them the same way it ignores headings.
_PROVENANCE_LINE = re.compile(
    r"^\s{0,3}[*_]{0,2}\s*"
    r"(?:original\s+url|source(?:\s+url)?|url|canonical\s+url|"
    r"retrieved(?:\s+at|\s+on)?|captured(?:\s+at|\s+on)?|"
    r"effective\s+date|last\s+updated)"
    r"\s*[*_]{0,2}\s*[:：]",
    re.IGNORECASE,
)
# Token-set Jaccard is diluted by front-matter differences: the real
# 3.1_All You Need To Know.md pair shares every body paragraph yet scores only
# 0.868 because its heading number and Original URL differ.  Containment over
# body-only paragraph fingerprints measures the same pair at 1.000, while the
# closest genuinely distinct pair in the real corpus (3.3_Intellectual Property
# Policy.md vs 3.6_Terms of Use.md, which share boilerplate clauses) measures
# 0.577.  A 0.9 threshold sits in the middle of that gap.
_BODY_OVERLAP_THRESHOLD = 0.9
# Containment is meaningless for a stub: a single paragraph repeated inside a
# long document would otherwise score 1.000.  Require enough body paragraphs
# on the smaller side for the overlap to carry information.
_MIN_BODY_PARAGRAPHS = 3


def resolve_exact(documents: Sequence[object]) -> tuple[list[object], list[dict[str, object]]]:
    """Choose one exact duplicate per brand/language/hash group.

    Original input objects are returned unchanged.  A duplicate audit record
    always points to the canonical relative path and contains a deterministic
    reason suitable for an import manifest.
    """
    groups: dict[tuple[str, str, str], list[object]] = {}
    for document in documents:
        normalized = _normalized(document)
        brand, language = _exact_scope(document)
        groups.setdefault((brand, language, normalized.normalized_hash), []).append(document)

    canonical_documents: list[object] = []
    duplicate_records: list[dict[str, object]] = []
    for group_key in sorted(groups):
        group = groups[group_key]
        canonical = min(group, key=_precedence_key)
        canonical_documents.append(canonical)
        for discarded in sorted((item for item in group if item is not canonical), key=_relative_path):
            duplicate_records.append({
                "kind": "exact",
                "relative_path": _relative_path(discarded),
                "duplicate_of": _relative_path(canonical),
                "reason": _precedence_reason(canonical, discarded),
                "normalized_hash": group_key[2],
            })

    return canonical_documents, duplicate_records


def find_near_duplicates(documents: Sequence[object], threshold: float = 0.92) -> list[dict[str, object]]:
    """Report near duplicate candidates without silently merging uncertainty.

    Candidates are strictly constrained to one brand, language, and directory
    family.  Only strict paragraph subsets with a non-lexical provenance
    winner are marked ``merge``.  All other high-similarity pairs remain
    separate and are emitted as ``review`` records.

    A pair whose token similarity stays under ``threshold`` is still reviewed
    when the two documents share the bulk of their body paragraphs, since
    front matter alone -- a heading number, a provenance URL -- can hold the
    token score down while the prose is duplicated verbatim.  Such a pair is
    never a ``merge`` candidate: only a human decides which export to keep.
    """
    if not 0 <= threshold <= 1:
        raise ValueError("threshold must be between 0 and 1")

    scoped: dict[
        tuple[str, str, str],
        list[tuple[object, tuple[frozenset[str], frozenset[str], frozenset[str]]]],
    ] = {}
    for document in documents:
        scoped.setdefault(_near_scope(document), []).append((document, _document_analysis(document)))

    records: list[dict[str, object]] = []
    for scope in sorted(scoped):
        candidates = sorted(scoped[scope], key=lambda item: _relative_path(item[0]))
        for (first, first_analysis), (second, second_analysis) in combinations(candidates, 2):
            if _normalized(first).normalized_hash == _normalized(second).normalized_hash:
                continue
            similarity = _token_set_similarity(first_analysis[0], second_analysis[0])
            if similarity < threshold:
                if _body_overlap(first_analysis[2], second_analysis[2]) < _BODY_OVERLAP_THRESHOLD:
                    continue
                records.append({
                    "kind": "near",
                    "action": "review",
                    "relative_paths": (_relative_path(first), _relative_path(second)),
                    "reason": "identical_body_requires_review",
                    "similarity": similarity,
                })
                continue
            subset, superset = _strict_subset_pair(first, second, first_analysis[1], second_analysis[1])
            if subset is not None and _has_unambiguous_provenance_precedence(first, second):
                canonical = min((first, second), key=_precedence_key)
                if canonical is superset:
                    duplicate = second if canonical is first else first
                    records.append({
                        "kind": "near",
                        "action": "merge",
                        "relative_path": _relative_path(duplicate),
                        "duplicate_of": _relative_path(canonical),
                        "reason": "strict_subset_unambiguous_provenance",
                        "similarity": similarity,
                    })
                    continue
                review_reason = "strict_subset_canonical_lacks_content"
            else:
                review_reason = "near_duplicate_requires_review"

            records.append({
                "kind": "near",
                "action": "review",
                "relative_paths": (_relative_path(first), _relative_path(second)),
                "reason": review_reason,
                "similarity": similarity,
            })

    return records


def _normalized(document: object) -> NormalizedDocument:
    normalized = _field(document, "normalized", "document")
    if not isinstance(normalized, NormalizedDocument):
        raise TypeError("documents must contain a NormalizedDocument as 'normalized'")
    return normalized


def _classification(document: object) -> Classification:
    classification = _field(document, "classification")
    if not isinstance(classification, Classification):
        raise TypeError("documents must contain a Classification as 'classification'")
    return classification


def _exact_scope(document: object) -> tuple[str, str]:
    classification = _classification(document)
    return classification.brand_key, classification.language_code


def _near_scope(document: object) -> tuple[str, str, str]:
    classification = _classification(document)
    directory_path = classification.directory_path or ""
    directory_family = directory_path.split("/", 1)[0]
    return classification.brand_key, classification.language_code, directory_family


def _relative_path(document: object) -> str:
    return str(_field(document, "relative_path", "source_file_path", "path", default=""))


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


def _is_markdown(document: object) -> bool:
    path = _relative_path(document).casefold()
    return any(path.endswith(suffix) for suffix in _MARKDOWN_SUFFIXES)


def _has_first_party_url(document: object) -> bool:
    if _classification(document).authority_level != "first_party":
        return False
    source_url = str(_field(document, "source_url", "canonical_url", default="")).strip()
    return bool(source_url or _normalized(document).source_urls)


def _provenance_completeness(document: object) -> int:
    fields = (
        _field(document, "source_url", "canonical_url", default=""),
        _field(document, "source_name", default=""),
        _field(document, "source_checksum", "source_file_hash", default=""),
        _field(document, "ingestion_method", default=""),
        _relative_path(document),
        _field(document, "retrieved_at", default=""),
        _field(document, "effective_date", default=""),
    )
    return sum(bool(str(value).strip()) for value in fields)


def _lifecycle_timestamp(document: object) -> float:
    candidates = (
        _field(document, "retrieved_at", default=None),
        _field(document, "effective_date", default=None),
    )
    parsed: list[float] = []
    for candidate in candidates:
        if candidate is None or candidate == "":
            continue
        try:
            value = candidate if isinstance(candidate, datetime) else datetime.fromisoformat(
                str(candidate).replace("Z", "+00:00")
            )
            if value.tzinfo is None:
                value = value.replace(tzinfo=timezone.utc)
            parsed.append(value.timestamp())
        except ValueError:
            continue
    return max(parsed, default=float("-inf"))


def _precedence_key(document: object) -> tuple[int, int, int, float, str]:
    return (
        -int(_is_markdown(document)),
        -int(_has_first_party_url(document)),
        -_provenance_completeness(document),
        -_lifecycle_timestamp(document),
        _relative_path(document),
    )


def _precedence_reason(canonical: object, discarded: object) -> str:
    if _is_markdown(canonical) != _is_markdown(discarded):
        return "markdown_over_txt" if _is_markdown(canonical) else "format_precedence"
    if _has_first_party_url(canonical) != _has_first_party_url(discarded):
        return "first_party_url_bearing_source"
    if _provenance_completeness(canonical) != _provenance_completeness(discarded):
        return "provenance_completeness"
    if _lifecycle_timestamp(canonical) != _lifecycle_timestamp(discarded):
        return "newer_lifecycle_date"
    return "lexical_path_tiebreaker"


def _document_analysis(document: object) -> tuple[frozenset[str], frozenset[str], frozenset[str]]:
    markdown = _normalized(document).markdown
    return (
        frozenset(_tokens(markdown)),
        _paragraph_fingerprints(markdown),
        _body_fingerprints(markdown),
    )


def _token_set_similarity(first_tokens: frozenset[str], second_tokens: frozenset[str]) -> float:
    union = first_tokens | second_tokens
    return len(first_tokens & second_tokens) / len(union) if union else 1.0


def _body_overlap(first_bodies: frozenset[str], second_bodies: frozenset[str]) -> float:
    """Share of the smaller document's body paragraphs the two documents share.

    Containment rather than Jaccard, so a mirror that adds a few paragraphs is
    still recognisable, and the smaller side is the denominator so a long
    document cannot dilute the signal.
    """
    smaller = min(len(first_bodies), len(second_bodies))
    if smaller < _MIN_BODY_PARAGRAPHS:
        return 0.0
    return len(first_bodies & second_bodies) / smaller


def _tokens(markdown: str) -> tuple[str, ...]:
    return tuple(token.casefold() for token in _TOKEN.findall(markdown))


def _paragraph_fingerprints(markdown: str) -> frozenset[str]:
    paragraphs = re.split(r"\n\s*\n", markdown.strip())
    fingerprints = []
    for paragraph in paragraphs:
        tokens = _tokens(re.sub(r"^\s{0,3}#{1,6}\s+", "", paragraph))
        if tokens:
            fingerprints.append(hashlib.sha256(" ".join(tokens).encode("utf-8")).hexdigest())
    return frozenset(fingerprints)


def _body_fingerprints(markdown: str) -> frozenset[str]:
    """Fingerprint the prose only, with headings and provenance lines removed.

    Two exports of one page differ in their heading number and their source
    URL while saying exactly the same thing.  Dropping those lines lets the
    bodies be compared directly instead of through front matter noise.
    """
    fingerprints = []
    for paragraph in re.split(r"\n\s*\n", markdown.strip()):
        body_lines = [
            line for line in paragraph.splitlines()
            if not _HEADING_LINE.match(line) and not _PROVENANCE_LINE.match(line)
        ]
        tokens = _tokens("\n".join(body_lines))
        if tokens:
            fingerprints.append(hashlib.sha256(" ".join(tokens).encode("utf-8")).hexdigest())
    return frozenset(fingerprints)


def _strict_subset_pair(
    first: object,
    second: object,
    first_fingerprints: frozenset[str],
    second_fingerprints: frozenset[str],
) -> tuple[object | None, object | None]:
    if first_fingerprints < second_fingerprints:
        return first, second
    if second_fingerprints < first_fingerprints:
        return second, first
    return None, None


def _has_unambiguous_provenance_precedence(first: object, second: object) -> bool:
    return _precedence_key(first)[:4] != _precedence_key(second)[:4]
