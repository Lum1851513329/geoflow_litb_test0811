"""Build a deterministic, audited, English-only governed knowledge corpus."""

from __future__ import annotations

import argparse
from collections import Counter
from collections.abc import Iterable, Sequence
import ctypes
from datetime import date
import hashlib
import json
import os
import platform
from pathlib import Path, PurePosixPath
import re
import shutil
import stat
import sys
import tempfile
import unicodedata
from urllib.parse import urlsplit, urlunsplit

if __package__ in {None, ""}:  # Support the documented direct-script CLI.
    sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
    from tools.knowledge_corpus.classify import Classification, classify_document
    from tools.knowledge_corpus.deduplicate import find_near_duplicates, resolve_exact
    from tools.knowledge_corpus.normalize import NormalizedDocument, normalize_document
else:
    from .classify import Classification, classify_document
    from .deduplicate import find_near_duplicates, resolve_exact
    from .normalize import NormalizedDocument, normalize_document


BUILD_SCHEMA_VERSION = 1
CANONICAL_MARKDOWN_ROOT = "LightInTheBox_PT_Official_KB"
TXT_MIRROR_ROOT = "LightInTheBox_PT_Official_KB_TXT"
REPORT_ROOT = "officialknowege/LITB_Reports"
OFFICIAL_FAMILIES = (
    "01_Company_Info",
    "02_Customer_Service",
    "03_Company_Policies",
    "04_Product_Categories",
)
ALLOWED_AUTHORITIES = frozenset({
    "first_party", "regulatory_filing", "mixed_research", "third_party",
})
POLICY_DIRECTORIES = frozenset({
    "policy-compliance", "customer-transaction", "logistics-fulfilment",
})
_KEY_PART = re.compile(r"^[a-z0-9][a-z0-9_-]*$")
_METADATA_DATE = re.compile(
    r"(?im)^\s*(?:[-*>]\s+)?(?:\*\*)?"
    r"(?P<label>retrieved(?:\s+(?:at|date))?|effective(?:\s+(?:at|date))?|"
    r"published(?:\s+(?:at|date))?)"
    r"(?:\*\*)?\s*[:：]\s*(?:\*\*)?\s*(?P<value>\d{4}-\d{2}-\d{2})"
)
_VERSION = re.compile(
    r"(?im)^\s*(?:[-*>]\s+)?(?:\*\*)?version(?:\*\*)?\s*[:：]\s*"
    r"(?:\*\*)?\s*(?P<value>[A-Za-z0-9._-]+)"
)


class BuildError(RuntimeError):
    """Raised when a corpus cannot be safely or consistently published."""


def build_corpus(
    source: str | os.PathLike[str],
    output: str | os.PathLike[str],
    *,
    brand: str,
    site: str,
) -> dict[str, object]:
    """Build and atomically publish governed corpus artifacts.

    The source is only read. All generated files are first written and
    validated in a sibling temporary directory, then published as one tree.
    """
    source_root = Path(source).expanduser().resolve(strict=True)
    caller_output_path = Path(os.path.abspath(Path(output).expanduser()))
    _reject_output_symlink(caller_output_path)
    output_path = _canonical_output_path(caller_output_path, source_root)
    _validate_configuration(source_root, output_path, brand, site)
    _validate_source_structure(source_root)

    canonical_paths, mirror_paths = _discover(source_root)
    if not canonical_paths:
        raise BuildError("canonical Markdown corpus is empty")
    discovered_paths = [*canonical_paths, *mirror_paths]
    source_hashes_before = _hash_source_files(source_root, discovered_paths)
    documents = [
        _load_document(
            source_root,
            path,
            brand,
            site,
            "canonical_md",
            source_hashes_before[_safe_relative_source_path(source_root, path)],
        )
        for path in canonical_paths
    ] + [
        _load_document(
            source_root,
            path,
            brand,
            site,
            "txt_mirror",
            source_hashes_before[_safe_relative_source_path(source_root, path)],
        )
        for path in mirror_paths
    ]

    canonical_documents, exact_records = resolve_exact(documents)
    canonical_markdown = [
        document for document in canonical_documents
        if document["source_kind"] == "canonical_md"
    ]
    exclusions = _build_exclusion_audit(documents, brand, site)
    exclusions.extend(
        _build_orphan_txt_exclusions(canonical_documents, documents, brand, site)
    )
    duplicate_audit = _exact_audit_records(exact_records, documents, brand, site)
    duplicate_audit.extend(
        _audit_surviving_txt_mirrors(canonical_documents, documents, brand, site)
    )

    english_documents = [
        document for document in canonical_markdown
        if document["classification"].language_code == "en"
    ]
    for document in english_documents:
        _validate_required_metadata(document, brand, site)

    near_records = find_near_duplicates(english_documents)
    english_documents, near_duplicate_audit, near_review, near_aliases = _apply_near_records(
        english_documents, near_records, brand, site
    )
    duplicate_audit.extend(near_duplicate_audit)
    exact_aliases = {
        str(record["relative_path"]): str(record["duplicate_of"])
        for record in exact_records
    }
    _collapse_duplicate_targets(
        duplicate_audit,
        {**exact_aliases, **near_aliases},
        documents,
        brand,
        site,
    )
    versioned_documents = _assign_lifecycle(english_documents)

    manifest = [_manifest_record(document, brand, site) for document in versioned_documents]
    manifest.sort(key=lambda record: (
        str(record["canonical_document_key"]),
        str(record["document_id"]),
    ))
    duplicate_audit.sort(key=lambda record: (
        str(record["source_file_path"]),
        str(record["kind"]),
    ))
    exclusions.sort(key=lambda record: str(record["source_file_path"]))
    near_review.sort(key=lambda record: tuple(record["source_file_paths"]))

    stage = _create_stage(output_path)
    try:
        _write_cleaned_documents(stage, manifest, versioned_documents)
        _write_jsonl(stage / "manifest.jsonl", manifest)
        _write_jsonl(stage / "duplicate_audit.jsonl", duplicate_audit)
        _write_jsonl(stage / "exclusion_audit.jsonl", exclusions)
        _write_jsonl(stage / "near_duplicate_review.jsonl", near_review)

        source_hashes_after = _hash_source_files(source_root, discovered_paths)
        summary = _build_summary(
            canonical_paths=canonical_paths,
            mirror_paths=mirror_paths,
            documents=documents,
            manifest=manifest,
            duplicates=duplicate_audit,
            exclusions=exclusions,
            near_review=near_review,
            source_hashes_before=source_hashes_before,
            source_hashes_after=source_hashes_after,
            stage=stage,
            brand=brand,
            site=site,
        )
        _validate_artifacts(
            manifest,
            duplicate_audit,
            exclusions,
            source_hashes_before,
            source_hashes_after,
            brand,
            site,
        )
        _write_json(stage / "run_summary.json", summary)
        _publish(stage, output_path)
        return summary
    except BaseException:
        if stage.exists():
            shutil.rmtree(stage)
        raise


def _validate_configuration(source: Path, output: Path, brand: str, site: str) -> None:
    if not source.is_dir():
        raise BuildError(f"source is not a directory: {source}")
    for label, value in (("brand", brand), ("site", site)):
        if not _KEY_PART.fullmatch(value):
            raise BuildError(f"invalid {label} key: {value!r}")
    if brand != "lightinthebox" or site != "lightinthebox-main":
        raise BuildError("the current classifier supports only lightinthebox/lightinthebox-main")
    _reject_output_symlink(output)
    if output == source or output in source.parents:
        raise BuildError("output cannot replace or contain the source directory")
    if source in output.parents:
        relative_output = output.relative_to(source)
        if (
            len(relative_output.parts) < 2
            or relative_output.parts[0] != "knowledge_cleanup_work"
        ):
            raise BuildError("output inside source must be under knowledge_cleanup_work")
    if output.exists() and not output.is_dir():
        raise BuildError(f"output exists and is not a directory: {output}")


def _reject_output_symlink(output: Path) -> None:
    try:
        mode = output.lstat().st_mode
    except FileNotFoundError:
        return
    if stat.S_ISLNK(mode):
        raise BuildError(f"output path must not be a symlink: {output}")


def _canonical_output_path(caller_output: Path, source: Path) -> Path:
    symlinked_ancestor = _has_symlinked_ancestor(caller_output)
    canonical_output = caller_output.resolve(strict=False)
    _reject_output_symlink(canonical_output)
    worktree = Path(__file__).resolve().parents[2]

    if symlinked_ancestor and (
        _is_within(canonical_output, source) or _is_within(canonical_output, worktree)
    ):
        raise BuildError("output symlink ancestor resolves inside a protected boundary")
    if _paths_overlap(canonical_output, worktree):
        raise BuildError("output cannot overlap the application worktree")
    return canonical_output


def _has_symlinked_ancestor(path: Path) -> bool:
    current = Path(path.anchor)
    for part in path.parts[1:-1]:
        current /= part
        try:
            if stat.S_ISLNK(current.lstat().st_mode):
                return True
        except FileNotFoundError:
            return False
    return False


def _is_within(path: Path, boundary: Path) -> bool:
    return path == boundary or boundary in path.parents


def _paths_overlap(first: Path, second: Path) -> bool:
    return _is_within(first, second) or _is_within(second, first)


def _discover(source: Path) -> tuple[list[Path], list[Path]]:
    canonical: list[Path] = []
    for family in OFFICIAL_FAMILIES:
        canonical.extend(_files_below(source, f"{CANONICAL_MARKDOWN_ROOT}/{family}", ".md"))
    canonical.extend(_files_below(source, REPORT_ROOT, ".md"))

    mirrors: list[Path] = []
    for family in OFFICIAL_FAMILIES:
        mirrors.extend(_files_below(source, f"{TXT_MIRROR_ROOT}/{family}", ".txt"))
    return sorted(set(canonical), key=_source_sort_key), sorted(set(mirrors), key=_source_sort_key)


def _validate_source_structure(source: Path) -> None:
    required_roots = [
        *(f"{CANONICAL_MARKDOWN_ROOT}/{family}" for family in OFFICIAL_FAMILIES),
        *(f"{TXT_MIRROR_ROOT}/{family}" for family in OFFICIAL_FAMILIES),
        REPORT_ROOT,
    ]
    missing = []
    for relative_root in required_roots:
        root = source / relative_root
        if root.is_symlink() or not root.is_dir():
            missing.append(relative_root)
    if missing:
        raise BuildError(f"required source roots missing: {', '.join(missing)}")


def _files_below(source: Path, relative_root: str, suffix: str) -> list[Path]:
    root = source / relative_root
    if not root.exists():
        return []
    if root.is_symlink() or not root.is_dir():
        raise BuildError(f"source family is not a real directory: {relative_root}")
    paths: list[Path] = []
    for current, directory_names, file_names in os.walk(root, followlinks=False):
        current_path = Path(current)
        retained_directories: list[str] = []
        for directory_name in sorted(directory_names):
            if _excluded_component(directory_name):
                continue
            directory = current_path / directory_name
            if directory.is_symlink():
                raise BuildError(f"source symlink is not permitted: {directory}")
            retained_directories.append(directory_name)
        directory_names[:] = retained_directories

        for file_name in sorted(file_names):
            if _excluded_component(file_name) or not file_name.casefold().endswith(suffix):
                continue
            path = current_path / file_name
            if not path.is_file():
                continue
            _safe_relative_source_path(source, path)
            paths.append(path)
    return paths


def _excluded_component(component: str) -> bool:
    folded = unicodedata.normalize("NFC", component).casefold()
    normalized = re.sub(r"[\s-]+", "_", folded)
    return (
        normalized in {".ds_store", "knowledge_cleanup_work", "output", "work"}
        or "keyword_library" in normalized
    )


def _safe_relative_source_path(source: Path, path: Path) -> str:
    if path.is_symlink():
        raise BuildError(f"source symlink is not permitted: {path}")
    try:
        resolved = path.resolve(strict=True)
        relative = resolved.relative_to(source)
    except (OSError, ValueError) as error:
        raise BuildError(f"source path escapes corpus root: {path}") from error
    if any(part in {"", ".", ".."} for part in relative.parts):
        raise BuildError(f"unsafe source path: {path}")
    return relative.as_posix()


def _source_sort_key(path: Path) -> str:
    return unicodedata.normalize("NFC", path.as_posix())


def _hash_source_files(source: Path, paths: Iterable[Path]) -> dict[str, str]:
    result: dict[str, str] = {}
    for path in sorted(paths, key=_source_sort_key):
        relative_path = _safe_relative_source_path(source, path)
        result[relative_path] = hashlib.sha256(path.read_bytes()).hexdigest()
    return result


def _load_document(
    source: Path,
    path: Path,
    brand: str,
    site: str,
    source_kind: str,
    expected_source_hash: str,
) -> dict[str, object]:
    relative_path = _safe_relative_source_path(source, path)
    raw_bytes = path.read_bytes()
    source_hash = hashlib.sha256(raw_bytes).hexdigest()
    if source_hash != expected_source_hash:
        raise BuildError(f"source changed while loading: {relative_path}")
    try:
        raw = raw_bytes.decode("utf-8")
    except UnicodeDecodeError as error:
        raise BuildError(f"source is not valid UTF-8: {relative_path}") from error
    normalized = normalize_document(raw)
    classification = classify_document(relative_path, normalized)
    source_url = normalized.source_urls[0] if normalized.source_urls else ""
    dates = _extract_dates(normalized.markdown)
    source_family = _source_family(relative_path)
    return {
        "relative_path": relative_path,
        "source_file_path": relative_path,
        "source_file_hash": source_hash,
        "source_checksum": source_hash,
        "source_kind": source_kind,
        "source_family": source_family,
        "source_name": (
            "LightInTheBox official knowledge base"
            if source_family in {"official_kb", "official_kb_txt"}
            else "LITB Reports"
        ),
        "source_type": (
            "official_web_page" if source_family in {"official_kb", "official_kb_txt"}
            else "compiled_report"
        ),
        "source_url": source_url,
        "ingestion_method": "read_only_folder",
        "retrieved_at": dates.get("retrieved", ""),
        "effective_date": dates.get("effective", dates.get("published", "")),
        "explicit_version": _extract_version(normalized.markdown),
        "normalized": normalized,
        "classification": classification,
        "canonical_document_key": _canonical_document_key(relative_path, normalized),
        "brand_key": brand,
        "site_key": site,
    }


def _source_family(relative_path: str) -> str:
    if relative_path.startswith(f"{CANONICAL_MARKDOWN_ROOT}/"):
        return "official_kb"
    if relative_path.startswith(f"{TXT_MIRROR_ROOT}/"):
        return "official_kb_txt"
    if relative_path.startswith(f"{REPORT_ROOT}/"):
        return "litb_reports"
    raise BuildError(f"unknown source family: {relative_path}")


def _extract_dates(markdown: str) -> dict[str, str]:
    dates: dict[str, str] = {}
    for match in _METADATA_DATE.finditer(markdown):
        label = match.group("label").casefold()
        value = match.group("value")
        try:
            date.fromisoformat(value)
        except ValueError:
            continue
        field = "retrieved" if label.startswith("retrieved") else (
            "effective" if label.startswith("effective") else "published"
        )
        dates.setdefault(field, value)
    return dates


def _extract_version(markdown: str) -> str:
    match = _VERSION.search(markdown)
    return match.group("value") if match else ""


def _canonical_document_key(relative_path: str, normalized: NormalizedDocument) -> str:
    if normalized.source_urls:
        return f"url:{_canonicalize_url(normalized.source_urls[0])}"
    parts = list(PurePosixPath(relative_path).parts)
    if parts[:2] == ["officialknowege", "LITB_Reports"]:
        parts = parts[2:]
    elif parts and parts[0] in {CANONICAL_MARKDOWN_ROOT, TXT_MIRROR_ROOT}:
        parts = parts[1:]
    path = PurePosixPath(*parts).with_suffix("").as_posix()
    return f"path:{unicodedata.normalize('NFC', path)}"


def _canonicalize_url(url: str) -> str:
    parsed = urlsplit(url)
    scheme = parsed.scheme.casefold()
    hostname = (parsed.hostname or "").casefold()
    port = parsed.port
    netloc = hostname
    if port is not None and not ((scheme == "http" and port == 80) or (scheme == "https" and port == 443)):
        netloc = f"{hostname}:{port}"
    path = parsed.path or "/"
    if path != "/":
        path = path.rstrip("/")
    return urlunsplit((scheme, netloc, path, parsed.query, ""))


def _build_exclusion_audit(
    documents: Sequence[dict[str, object]], brand: str, site: str
) -> list[dict[str, object]]:
    records: list[dict[str, object]] = []
    for document in documents:
        classification = _classification(document)
        if classification.language_code == "en":
            continue
        records.append(_exclusion_record(
            document, brand, site, reason="non_english_initial_corpus"
        ))
    return records


def _build_orphan_txt_exclusions(
    canonical_documents: Sequence[dict[str, object]],
    all_documents: Sequence[dict[str, object]],
    brand: str,
    site: str,
) -> list[dict[str, object]]:
    discovered_paths = {str(document["source_file_path"]) for document in all_documents}
    records: list[dict[str, object]] = []
    for document in canonical_documents:
        if (
            document["source_kind"] != "txt_mirror"
            or _classification(document).language_code != "en"
            or _expected_markdown_path(str(document["source_file_path"])) in discovered_paths
        ):
            continue
        records.append(_exclusion_record(
            document,
            brand,
            site,
            reason="txt_mirror_without_markdown_canonical",
        ))
    return records


def _exclusion_record(
    document: dict[str, object], brand: str, site: str, *, reason: str
) -> dict[str, object]:
    classification = _classification(document)
    normalized = _normalized(document)
    return {
        "document_id": _document_id(document, brand, site),
        "canonical_document_key": document["canonical_document_key"],
        "source_file_path": document["source_file_path"],
        "source_file_hash": document["source_file_hash"],
        "normalized_content_hash": normalized.normalized_hash,
        "language_code": classification.language_code,
        "reason": reason,
        "source_kind": document["source_kind"],
        "warnings": sorted(set((*classification.warnings, *normalized.warnings))),
    }


def _exact_audit_records(
    records: Sequence[dict[str, object]],
    documents: Sequence[dict[str, object]],
    brand: str,
    site: str,
) -> list[dict[str, object]]:
    by_path = {str(document["source_file_path"]): document for document in documents}
    audit: list[dict[str, object]] = []
    for record in records:
        source = by_path[str(record["relative_path"])]
        target = by_path[str(record["duplicate_of"])]
        audit.append({
            "kind": "exact",
            "source_file_path": source["source_file_path"],
            "source_file_hash": source["source_file_hash"],
            "duplicate_of_source_file_path": target["source_file_path"],
            "duplicate_of_document_id": _document_id(target, brand, site),
            "normalized_content_hash": record["normalized_hash"],
            "reason": record["reason"],
        })
    return audit


def _audit_surviving_txt_mirrors(
    canonical_documents: Sequence[dict[str, object]],
    all_documents: Sequence[dict[str, object]],
    brand: str,
    site: str,
) -> list[dict[str, object]]:
    by_path = {str(document["source_file_path"]): document for document in all_documents}
    records: list[dict[str, object]] = []
    for document in canonical_documents:
        if document["source_kind"] != "txt_mirror":
            continue
        source_path = str(document["source_file_path"])
        expected_path = _expected_markdown_path(source_path)
        target = by_path.get(expected_path)
        record: dict[str, object] = {
            "kind": "txt_mirror_excluded",
            "source_file_path": source_path,
            "source_file_hash": document["source_file_hash"],
            "normalized_content_hash": _normalized(document).normalized_hash,
            "reason": "txt_not_a_canonical_source",
        }
        if target is not None:
            record.update({
                "duplicate_of_source_file_path": expected_path,
                "duplicate_of_document_id": _document_id(target, brand, site),
                "reason": "txt_mirror_content_diverges_from_markdown",
            })
        records.append(record)
    return records


def _expected_markdown_path(txt_path: str) -> str:
    return (
        txt_path.replace(f"{TXT_MIRROR_ROOT}/", f"{CANONICAL_MARKDOWN_ROOT}/", 1)
        .removesuffix(".txt") + ".md"
    )


def _apply_near_records(
    documents: Sequence[dict[str, object]],
    records: Sequence[dict[str, object]],
    brand: str,
    site: str,
) -> tuple[
    list[dict[str, object]],
    list[dict[str, object]],
    list[dict[str, object]],
    dict[str, str],
]:
    by_path = {str(document["source_file_path"]): document for document in documents}
    merge_targets: dict[str, str] = {}
    review_records: list[dict[str, object]] = []
    for record in records:
        if record["action"] == "merge":
            source_path = str(record["relative_path"])
            target_path = str(record["duplicate_of"])
            if _same_canonical_key(by_path[source_path], by_path[target_path]):
                continue
            merge_targets[source_path] = target_path
            continue
        first_path, second_path = tuple(record["relative_paths"])
        if _same_canonical_key(by_path[first_path], by_path[second_path]):
            continue
        review_records.append({
            "kind": "near",
            "action": "review",
            "source_file_paths": [first_path, second_path],
            "reason": record["reason"],
            "similarity": record["similarity"],
        })

    def final_target(path: str) -> str:
        visited: set[str] = set()
        while path in merge_targets:
            if path in visited:
                raise BuildError("near-duplicate merge cycle")
            visited.add(path)
            path = merge_targets[path]
        return path

    duplicate_records: list[dict[str, object]] = []
    for source_path, target_path in sorted(merge_targets.items()):
        resolved_target = final_target(target_path)
        source = by_path[source_path]
        target = by_path[resolved_target]
        source_record = next(record for record in records if (
            record.get("action") == "merge" and record.get("relative_path") == source_path
        ))
        duplicate_records.append({
            "kind": "near",
            "source_file_path": source_path,
            "source_file_hash": source["source_file_hash"],
            "duplicate_of_source_file_path": resolved_target,
            "duplicate_of_document_id": _document_id(target, brand, site),
            "normalized_content_hash": _normalized(source).normalized_hash,
            "reason": source_record["reason"],
            "similarity": source_record["similarity"],
        })

    remaining = [
        document for document in documents
        if str(document["source_file_path"]) not in merge_targets
    ]
    remaining_paths = {str(document["source_file_path"]) for document in remaining}
    review_records = [
        record for record in review_records
        if set(record["source_file_paths"]).issubset(remaining_paths)
    ]
    return remaining, duplicate_records, review_records, merge_targets


def _collapse_duplicate_targets(
    records: Sequence[dict[str, object]],
    aliases: dict[str, str],
    documents: Sequence[dict[str, object]],
    brand: str,
    site: str,
) -> None:
    by_path = {str(document["source_file_path"]): document for document in documents}

    def final_target(path: str) -> str:
        visited: set[str] = set()
        while path in aliases:
            if path in visited:
                raise BuildError("duplicate alias cycle")
            visited.add(path)
            path = aliases[path]
        return path

    for record in records:
        if "duplicate_of_source_file_path" not in record:
            continue
        target_path = final_target(str(record["duplicate_of_source_file_path"]))
        target = by_path.get(target_path)
        if target is None:
            raise BuildError(f"duplicate target is not a discovered source: {target_path}")
        record["duplicate_of_source_file_path"] = target_path
        record["duplicate_of_document_id"] = _document_id(target, brand, site)


def _same_canonical_key(first: dict[str, object], second: dict[str, object]) -> bool:
    return first["canonical_document_key"] == second["canonical_document_key"]


def _validate_required_metadata(document: dict[str, object], brand: str, site: str) -> None:
    classification = _classification(document)
    normalized = _normalized(document)
    required = {
        "brand_key": brand,
        "site_key": site,
        "directory_path": classification.directory_path,
        "language_code": classification.language_code,
        "authority_level": classification.authority_level,
        "source_file_path": document["source_file_path"],
        "source_file_hash": document["source_file_hash"],
        "source_name": document["source_name"],
        "source_type": document["source_type"],
        "ingestion_method": document["ingestion_method"],
        "title": normalized.title,
        "normalized_markdown": normalized.markdown,
    }
    missing = sorted(field for field, value in required.items() if not str(value or "").strip())
    if classification.authority_level not in ALLOWED_AUTHORITIES:
        missing.append("valid_authority_level")
    if missing:
        raise BuildError(
            f"required metadata missing for {document['source_file_path']}: {', '.join(missing)}"
        )
    if classification.brand_key != brand or classification.site_key != site:
        raise BuildError(f"unknown owner for {document['source_file_path']}")


def _assign_lifecycle(documents: Sequence[dict[str, object]]) -> list[dict[str, object]]:
    groups: dict[str, list[dict[str, object]]] = {}
    for document in documents:
        groups.setdefault(str(document["canonical_document_key"]), []).append(document)
    result: list[dict[str, object]] = []
    for canonical_key in sorted(groups):
        group = groups[canonical_key]
        reviewed = [
            document for document in group
            if _classification(document).review_status == "reviewed"
        ]
        active = max(reviewed or group, key=_version_sort_key)
        for document in group:
            copy = dict(document)
            copy["lifecycle_status"] = "active" if document is active else "superseded"
            copy["version_label"] = _version_label(document)
            result.append(copy)
    return result


def _version_sort_key(
    document: dict[str, object],
) -> tuple[int, tuple[tuple[int, object], ...], str]:
    return (
        _latest_lifecycle_ordinal(document),
        _natural_version_key(str(document["explicit_version"])),
        str(document["source_file_path"]),
    )


def _latest_lifecycle_ordinal(document: dict[str, object]) -> int:
    parsed: list[date] = []
    for field in ("retrieved_at", "effective_date"):
        value = str(document[field])
        if not value:
            continue
        try:
            parsed.append(date.fromisoformat(value))
        except ValueError:
            continue
    return max(parsed, default=date.min).toordinal()


def _natural_version_key(value: str) -> tuple[tuple[int, object], ...]:
    return tuple(
        (1, int(part)) if part.isdigit() else (0, part.casefold())
        for part in re.split(r"(\d+)", value)
        if part
    )


def _version_label(document: dict[str, object]) -> str:
    base = (
        str(document["explicit_version"])
        or str(document["retrieved_at"])
        or str(document["effective_date"])
        or "source"
    )
    return f"{base}-{_normalized(document).normalized_hash[:12]}"


def _document_id(document: dict[str, object], brand: str, site: str) -> str:
    """Return an order-independent stable identity for one content version."""
    identity = "\0".join((
        brand,
        site,
        str(document["canonical_document_key"]),
        _normalized(document).normalized_hash,
    ))
    return hashlib.sha256(identity.encode("utf-8")).hexdigest()[:32]


def _manifest_record(
    document: dict[str, object], brand: str, site: str
) -> dict[str, object]:
    normalized = _normalized(document)
    classification = _classification(document)
    document_id = _document_id(document, brand, site)
    generation_eligible = classification.generation_eligible
    if classification.directory_path in POLICY_DIRECTORIES:
        generation_eligible = bool(
            generation_eligible and document["source_url"] and document["retrieved_at"]
        )
    return {
        "document_id": document_id,
        "asset_key": f"knowledge:{document_id}",
        "canonical_document_key": document["canonical_document_key"],
        "version_label": document["version_label"],
        "lifecycle_status": document["lifecycle_status"],
        "brand_key": brand,
        "site_key": site,
        "directory_path": classification.directory_path,
        "language_code": classification.language_code,
        "authority_level": classification.authority_level,
        "risk_level": classification.risk_level,
        "review_status": classification.review_status,
        "generation_eligible": generation_eligible,
        "citation_required": classification.citation_required,
        "title": normalized.title,
        "source_name": document["source_name"],
        "source_url": document["source_url"],
        "source_type": document["source_type"],
        "source_file_path": document["source_file_path"],
        "source_file_hash": document["source_file_hash"],
        "normalized_content_hash": normalized.normalized_hash,
        "ingestion_method": document["ingestion_method"],
        "retrieved_at": document["retrieved_at"],
        "effective_date": document["effective_date"],
        "review_due_at": "",
        "warnings": sorted(set((*normalized.warnings, *classification.warnings))),
        "cleaned_file_path": f"cleaned/{classification.directory_path}/{document_id}.md",
    }


def _create_stage(output: Path) -> Path:
    output.parent.mkdir(parents=True, exist_ok=True)
    return Path(tempfile.mkdtemp(prefix=f".{output.name}.tmp-", dir=output.parent))


def _write_cleaned_documents(
    stage: Path,
    manifest: Sequence[dict[str, object]],
    documents: Sequence[dict[str, object]],
) -> None:
    by_id = {
        str(_document_id(document, str(document["brand_key"]), str(document["site_key"]))): document
        for document in documents
    }
    stage_root = stage.resolve(strict=True)
    for record in manifest:
        relative_path = _safe_output_relative_path(str(record["cleaned_file_path"]))
        destination = stage / relative_path
        destination.parent.mkdir(parents=True, exist_ok=True)
        if stage_root not in destination.resolve(strict=False).parents:
            raise BuildError(f"cleaned path escapes staging directory: {relative_path}")
        document = by_id[str(record["document_id"])]
        rendered = _render_cleaned_document(record, _normalized(document).markdown)
        destination.write_text(rendered, encoding="utf-8", newline="\n")
        record["cleaned_file_hash"] = hashlib.sha256(destination.read_bytes()).hexdigest()


def _safe_output_relative_path(value: str) -> PurePosixPath:
    path = PurePosixPath(value)
    if path.is_absolute() or any(part in {"", ".", ".."} for part in path.parts):
        raise BuildError(f"unsafe output path: {value}")
    return path


def _render_cleaned_document(record: dict[str, object], markdown: str) -> str:
    front_matter_fields = (
        "document_id", "asset_key", "canonical_document_key", "version_label",
        "lifecycle_status", "brand_key", "site_key", "directory_path",
        "language_code", "authority_level", "risk_level", "review_status",
        "generation_eligible", "citation_required", "title", "source_name",
        "source_url", "source_type", "source_file_path", "source_file_hash",
        "normalized_content_hash", "ingestion_method", "retrieved_at",
        "effective_date", "review_due_at", "warnings",
    )
    lines = ["---"]
    for field in front_matter_fields:
        lines.append(f"{field}: {_yaml_value(record[field])}")
    lines.extend(("---", "", markdown.rstrip("\n"), ""))
    return "\n".join(lines)


def _yaml_value(value: object) -> str:
    if isinstance(value, bool):
        return "true" if value else "false"
    return json.dumps(value, ensure_ascii=False, sort_keys=True)


def _write_jsonl(path: Path, records: Sequence[dict[str, object]]) -> None:
    lines = [
        json.dumps(record, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
        for record in records
    ]
    path.write_text("\n".join(lines) + ("\n" if lines else ""), encoding="utf-8", newline="\n")


def _write_json(path: Path, value: dict[str, object]) -> None:
    path.write_text(
        json.dumps(value, ensure_ascii=False, sort_keys=True, indent=2) + "\n",
        encoding="utf-8",
        newline="\n",
    )


def _build_summary(
    *,
    canonical_paths: Sequence[Path],
    mirror_paths: Sequence[Path],
    documents: Sequence[dict[str, object]],
    manifest: Sequence[dict[str, object]],
    duplicates: Sequence[dict[str, object]],
    exclusions: Sequence[dict[str, object]],
    near_review: Sequence[dict[str, object]],
    source_hashes_before: dict[str, str],
    source_hashes_after: dict[str, str],
    stage: Path,
    brand: str,
    site: str,
) -> dict[str, object]:
    validation = _validation_results(
        manifest, duplicates, exclusions, source_hashes_before, source_hashes_after, brand, site
    )
    artifacts = (
        "manifest.jsonl", "duplicate_audit.jsonl", "exclusion_audit.jsonl",
        "near_duplicate_review.jsonl",
    )
    return {
        "schema_version": BUILD_SCHEMA_VERSION,
        "brand_key": brand,
        "site_key": site,
        "source_set_hash": _mapping_hash(source_hashes_before),
        "counts": {
            "canonical_source_files": len(canonical_paths),
            "txt_mirror_files": len(mirror_paths),
            "source_files_hashed": len(source_hashes_before),
            "manifest_documents": len(manifest),
            "active_documents": sum(record["lifecycle_status"] == "active" for record in manifest),
            "superseded_documents": sum(record["lifecycle_status"] == "superseded" for record in manifest),
            "duplicate_records": len(duplicates),
            "near_review_records": len(near_review),
            "excluded_sources": len(exclusions),
        },
        "distributions": {
            "directory": _distribution(record["directory_path"] for record in manifest),
            "language": _distribution(record["language_code"] for record in manifest),
            "authority": _distribution(record["authority_level"] for record in manifest),
            "lifecycle": _distribution(record["lifecycle_status"] for record in manifest),
            "excluded_language": _distribution(record["language_code"] for record in exclusions),
            "source_language": _distribution(
                _classification(document).language_code for document in documents
            ),
            "source_family": _distribution(document["source_family"] for document in documents),
        },
        "validation": validation,
        "artifact_hashes": {
            artifact: hashlib.sha256((stage / artifact).read_bytes()).hexdigest()
            for artifact in artifacts
        },
    }


def _validation_results(
    manifest: Sequence[dict[str, object]],
    duplicates: Sequence[dict[str, object]],
    exclusions: Sequence[dict[str, object]],
    source_hashes_before: dict[str, str],
    source_hashes_after: dict[str, str],
    brand: str,
    site: str,
) -> dict[str, bool]:
    active_hashes = [
        (record["brand_key"], record["language_code"], record["normalized_content_hash"])
        for record in manifest if record["lifecycle_status"] == "active"
    ]
    final_targets = {
        (str(record["source_file_path"]), str(record["document_id"]))
        for record in (*manifest, *exclusions)
    }
    return {
        "source_hashes_unchanged": source_hashes_before == source_hashes_after,
        "zero_active_exact_hash_duplicates": len(active_hashes) == len(set(active_hashes)),
        "no_unknown_owner": all(
            record["brand_key"] == brand and record["site_key"] == site
            and record["directory_path"] and record["authority_level"] in ALLOWED_AUTHORITIES
            for record in manifest
        ),
        "english_only_manifest": all(record["language_code"] == "en" for record in manifest),
        "policy_eligibility_rules": all(
            record["directory_path"] not in POLICY_DIRECTORIES
            or not record["generation_eligible"]
            or bool(record["source_url"] and record["retrieved_at"])
            for record in manifest
        ),
        "valid_duplicate_targets": all(
            "duplicate_of_source_file_path" not in record
            or (
                str(record["duplicate_of_source_file_path"]),
                str(record.get("duplicate_of_document_id", "")),
            ) in final_targets
            for record in duplicates
        ),
        "cleaned_manifest_paths_unique": len(manifest) == len({
            record["cleaned_file_path"] for record in manifest
        }),
    }


def _validate_artifacts(
    manifest: Sequence[dict[str, object]],
    duplicates: Sequence[dict[str, object]],
    exclusions: Sequence[dict[str, object]],
    source_hashes_before: dict[str, str],
    source_hashes_after: dict[str, str],
    brand: str,
    site: str,
) -> None:
    document_hashes: dict[str, str] = {}
    for record in manifest:
        document_id = str(record["document_id"])
        content_hash = str(record["normalized_content_hash"])
        previous = document_hashes.setdefault(document_id, content_hash)
        if previous != content_hash:
            raise BuildError(f"divergent hash collision for document ID {document_id}")
    failed = [
        name for name, passed in _validation_results(
            manifest,
            duplicates,
            exclusions,
            source_hashes_before,
            source_hashes_after,
            brand,
            site,
        ).items() if not passed
    ]
    if failed:
        raise BuildError(f"corpus validation failed: {', '.join(failed)}")


def _distribution(values: Iterable[object]) -> dict[str, int]:
    counts = Counter(str(value) for value in values)
    return {key: counts[key] for key in sorted(counts)}


def _mapping_hash(mapping: dict[str, str]) -> str:
    payload = json.dumps(mapping, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    return hashlib.sha256(payload.encode("utf-8")).hexdigest()


def _normalized(document: dict[str, object]) -> NormalizedDocument:
    normalized = document["normalized"]
    if not isinstance(normalized, NormalizedDocument):
        raise TypeError("invalid normalized document")
    return normalized


def _classification(document: dict[str, object]) -> Classification:
    classification = document["classification"]
    if not isinstance(classification, Classification):
        raise TypeError("invalid classification")
    return classification


def _publish(stage: Path, output: Path) -> None:
    if not output.exists():
        stage.replace(output)
        return
    _reject_output_symlink(output)
    if not output.is_dir():
        raise BuildError(f"live output is not a directory: {output}")
    _atomic_exchange(stage, output)
    try:
        shutil.rmtree(stage)
    except OSError:
        # Publication already succeeded atomically. A stale old-output temp
        # directory is safer than reporting a false build failure.
        pass


def _atomic_exchange(first: Path, second: Path) -> None:
    """Atomically exchange two directory names or fail before changing either."""
    system = platform.system()
    at_fdcwd = _at_fdcwd(system)
    libc = ctypes.CDLL(None, use_errno=True)
    first_bytes = os.fsencode(first)
    second_bytes = os.fsencode(second)

    if system == "Darwin" and hasattr(libc, "renameatx_np"):
        rename = libc.renameatx_np
        rename.argtypes = [ctypes.c_int, ctypes.c_char_p, ctypes.c_int, ctypes.c_char_p, ctypes.c_uint]
        rename.restype = ctypes.c_int
        result = rename(at_fdcwd, first_bytes, at_fdcwd, second_bytes, 0x00000002)
    elif system == "Linux" and hasattr(libc, "renameat2"):
        rename = libc.renameat2
        rename.argtypes = [ctypes.c_int, ctypes.c_char_p, ctypes.c_int, ctypes.c_char_p, ctypes.c_uint]
        rename.restype = ctypes.c_int
        result = rename(at_fdcwd, first_bytes, at_fdcwd, second_bytes, 0x00000002)
    else:
        raise BuildError(f"atomic directory exchange is unsupported on {system}")

    if result != 0:
        error_number = ctypes.get_errno()
        raise OSError(error_number, os.strerror(error_number), os.fspath(first), os.fspath(second))


def _at_fdcwd(system: str) -> int:
    if system == "Darwin":
        return -2
    if system == "Linux":
        return -100
    raise BuildError(f"atomic directory exchange is unsupported on {system}")


def _argument_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    parser.add_argument("--brand", required=True)
    parser.add_argument("--site", required=True)
    return parser


def main(argv: Sequence[str] | None = None) -> int:
    arguments = _argument_parser().parse_args(argv)
    try:
        summary = build_corpus(
            arguments.source,
            arguments.output,
            brand=arguments.brand,
            site=arguments.site,
        )
    except (BuildError, OSError) as error:
        print(f"knowledge corpus build failed: {error}", file=sys.stderr)
        return 1
    print(json.dumps(summary, ensure_ascii=False, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
