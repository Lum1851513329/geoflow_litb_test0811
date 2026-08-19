"""Conservative, repeatable Markdown normalization for source documents."""

from __future__ import annotations

from dataclasses import dataclass
import hashlib
import re
import unicodedata


_CHINESE_INLINE_LINK = re.compile(r"【\s*([^】\n]+?)\s*】\s*[（(]\s*([^\s)）]+)\s*[）)]")
_EMPTY_HEADING = re.compile(r"^\s{0,3}#{1,6}\s*$")
_ISOLATED_BACKSLASH = re.compile(r"^\s*\\\s*$")
_MARKDOWN_HEADING = re.compile(r"^\s{0,3}#\s+(.+?)\s*$")
_URL = re.compile(r"https?://[^\s<>\"']+")


@dataclass(frozen=True)
class NormalizedDocument:
    markdown: str
    normalized_hash: str
    title: str
    source_urls: tuple[str, ...]
    warnings: tuple[str, ...]


def normalize_document(raw: str) -> NormalizedDocument:
    """Normalize only format noise while preserving the source's wording.

    The returned ``markdown`` always ends in one newline when non-empty.  This
    keeps hashes stable across platform line endings and repeated processing.
    """
    if not isinstance(raw, str):
        raise TypeError("raw must be a string")

    text = unicodedata.normalize("NFC", raw).replace("\r\n", "\n").replace("\r", "\n")
    warnings: list[str] = []
    lines: list[str] = []
    previous_blank = False

    for source_line in text.split("\n"):
        line = source_line.rstrip()
        if _EMPTY_HEADING.fullmatch(line):
            _append_warning(warnings, "removed_empty_heading")
            if lines and lines[-1] != "":
                lines.append("")
                previous_blank = True
            continue
        if _ISOLATED_BACKSLASH.fullmatch(line):
            _append_warning(warnings, "removed_isolated_backslash")
            if lines and lines[-1] != "":
                lines.append("")
                previous_blank = True
            continue

        line = _CHINESE_INLINE_LINK.sub(r"[\1](\2)", line)
        is_blank = line == ""
        if is_blank and previous_blank:
            continue
        lines.append(line)
        previous_blank = is_blank

    while lines and lines[-1] == "":
        lines.pop()

    markdown = "\n".join(lines)
    if markdown:
        markdown += "\n"

    return NormalizedDocument(
        markdown=markdown,
        normalized_hash=hashlib.sha256(markdown.encode("utf-8")).hexdigest(),
        title=_extract_title(lines),
        source_urls=_extract_urls(markdown),
        warnings=tuple(warnings),
    )


def _append_warning(warnings: list[str], warning: str) -> None:
    if warning not in warnings:
        warnings.append(warning)


def _extract_title(lines: list[str]) -> str:
    for line in lines:
        match = _MARKDOWN_HEADING.match(line)
        if match:
            return match.group(1).strip().rstrip("#").rstrip()
    return ""


def _extract_urls(markdown: str) -> tuple[str, ...]:
    urls: list[str] = []
    for match in _URL.finditer(markdown):
        url = match.group(0).rstrip(".,;:!?")
        # A Markdown link's closing parenthesis is syntax, not part of the URL.
        if url.endswith(")") and url.count("(") < url.count(")"):
            url = url[:-1]
        if url and url not in urls:
            urls.append(url)
    return tuple(urls)
