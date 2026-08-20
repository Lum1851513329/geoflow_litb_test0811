"""Conservative, repeatable Markdown normalization for source documents."""

from __future__ import annotations

from dataclasses import dataclass
import hashlib
import ipaddress
import re
import unicodedata
from urllib.parse import urlsplit


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

        line = _normalize_inline_link_wrappers(line, warnings)
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


def _normalize_inline_link_wrappers(line: str, warnings: list[str]) -> str:
    """Convert only balanced, HTTP(S) Chinese-style link wrappers."""
    rendered: list[str] = []
    position = 0
    while True:
        start = line.find("【", position)
        if start < 0:
            rendered.append(line[position:])
            return "".join(rendered)
        close_label = line.find("】", start + 1)
        if close_label < 0:
            rendered.append(line[position:])
            return "".join(rendered)
        opener = close_label + 1
        while opener < len(line) and line[opener].isspace():
            opener += 1
        if opener == len(line) or line[opener] not in "(（":
            rendered.append(line[position:close_label + 1])
            position = close_label + 1
            continue
        close_destination = _find_wrapper_close(line, opener)
        if close_destination is None:
            rendered.append(line[position:])
            return "".join(rendered)

        destination = line[opener + 1:close_destination].strip()
        if _is_http_url(destination):
            label = line[start + 1:close_label].strip()
            rendered.append(line[position:start])
            rendered.append(f"[{label}]({destination})")
        else:
            _append_warning(warnings, "preserved_invalid_inline_link")
            rendered.append(line[position:close_destination + 1])
        position = close_destination + 1


def _find_wrapper_close(line: str, opener: int) -> int | None:
    opening, closing = ("(", ")") if line[opener] == "(" else ("（", "）")
    depth = 0
    for index in range(opener, len(line)):
        character = line[index]
        if character == opening:
            depth += 1
        elif character == closing:
            depth -= 1
            if depth == 0:
                return index
    return None


def _is_http_url(destination: str) -> bool:
    if not destination or any(character.isspace() for character in destination):
        return False
    try:
        parsed = urlsplit(destination)
        port = parsed.port
        hostname = parsed.hostname
    except ValueError:
        return False
    if (
        parsed.scheme.casefold() not in {"http", "https"}
        or not hostname
        or parsed.username is not None
        or parsed.password is not None
        or (port is not None and not 1 <= port <= 65535)
    ):
        return False
    try:
        ipaddress.ip_address(hostname)
        return True
    except ValueError:
        pass
    if any(ord(character) > 127 for character in hostname) or len(hostname) > 253:
        return False
    labels = hostname.split(".")
    return all(
        re.fullmatch(r"[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?", label)
        for label in labels
    )


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
