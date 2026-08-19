"""Deterministic, conservative source routing for the LightInTheBox corpus."""

from __future__ import annotations

from dataclasses import dataclass
import json
from pathlib import Path
import re
import unicodedata

from .normalize import NormalizedDocument


_TAXONOMY_PATH = Path(__file__).with_name("taxonomy.json")
DIRECTORY_KEYS = frozenset(json.loads(_TAXONOMY_PATH.read_text(encoding="utf-8"))["directory_keys"])

_PORTUGUESE_MARKERS = frozenset({
    "você", "voce", "devolução", "devoluções", "politica", "política", "entrega",
    "envio", "compras", "produto", "produtos", "prazo", "solicitar", "pedido",
    "não", "nao", "para", "sobre", "cliente", "clientes", "seus", "suas",
})
_ENGLISH_MARKERS = frozenset({
    "the", "and", "return", "returns", "policy", "shipping", "delivery", "our",
    "company", "about", "products", "customer", "customers", "report", "annual",
})
_WORD = re.compile(r"[\wÀ-ÖØ-öø-ÿ]+", re.UNICODE)
_HAN = re.compile(r"[\u3400-\u4dbf\u4e00-\u9fff\uf900-\ufaff]")
_URL = re.compile(r"https?://[^\s<>\"']+")
_PROVENANCE_HEADING = re.compile(r"^\s{0,3}#{1,6}\s+provenance\s*$", re.IGNORECASE)
_ROOTS = frozenset({"01_company_info", "02_customer_service", "03_company_policies", "04_product_categories"})


@dataclass(frozen=True)
class Classification:
    brand_key: str
    site_key: str
    directory_path: str | None
    language_code: str
    authority_level: str
    risk_level: str
    review_status: str
    generation_eligible: bool
    citation_required: bool
    warnings: tuple[str, ...] = ()


def classify_document(relative_path: str, document: NormalizedDocument) -> Classification:
    """Classify a normalized document without relying on its legacy filename language.

    Routing first uses the controlled source-folder conventions.  Content is a
    fallback for unstructured sources only, so an ambiguous record remains
    quarantined instead of being silently assigned a directory.
    """
    if not isinstance(relative_path, str):
        raise TypeError("relative_path must be a string")
    if not isinstance(document, NormalizedDocument):
        raise TypeError("document must be a NormalizedDocument")

    components = _path_components(relative_path)
    directory_path = _route_path(components)
    if directory_path is None:
        directory_path = _route_content(document.markdown)
    if directory_path is not None and directory_path not in DIRECTORY_KEYS:
        raise ValueError(f"unknown taxonomy directory key: {directory_path}")

    warnings: list[str] = []
    if directory_path is None:
        warnings.append("ambiguous_directory_route")

    language_code = _detect_language(document.markdown)
    if language_code == "und":
        warnings.append("ambiguous_language")

    authority_level, risk_level, review_status, generation_eligible = _governance_for(
        directory_path,
        document,
    )
    return Classification(
        brand_key="lightinthebox",
        site_key="lightinthebox-main",
        directory_path=directory_path,
        language_code=language_code,
        authority_level=authority_level,
        risk_level=risk_level,
        review_status=review_status,
        generation_eligible=generation_eligible,
        citation_required=True,
        warnings=tuple(warnings),
    )


def _path_components(relative_path: str) -> tuple[str, ...]:
    normalized = unicodedata.normalize("NFC", relative_path).replace("\\", "/")
    return tuple(component.casefold() for component in normalized.split("/") if component)


def _route_path(components: tuple[str, ...]) -> str | None:
    """Route only known source components before considering report filenames."""
    roots = set(components).intersection(_ROOTS)
    if "01_company_info" in roots:
        return "brand-company"
    if "02_customer_service" in roots:
        if _components_include(components, ("delivery", "shipping", "fulfilment", "fulfillment", "tracking", "dispatch")):
            return "logistics-fulfilment"
        return "customer-transaction"
    if "03_company_policies" in roots:
        if _components_include_phrase(components, ("all you need to know",)):
            return "product-category"
        return "policy-compliance"
    if "04_product_categories" in roots:
        if _components_include_phrase(
            components,
            (
                "new arrival", "new arrivals", "new_arrival", "best seller", "best sellers", "best_seller",
                "bestseller", "novo em", "mais vendidos",
            ),
        ):
            return "new-hot-products"
        return "product-category"
    if "litb_reports" in components:
        return _route_report(components)
    return None


def _route_report(components: tuple[str, ...]) -> str | None:
    if _components_include(components, ("竞争", "市场", "流量", "口碑", "评价")) or _components_include_phrase(
        components, ("research", "market", "traffic", "competition", "reputation")
    ):
        return "research-reference"
    if _components_include(components, ("投资者关系", "公司治理", "财务")) or _components_include_phrase(
        components, ("annual", "financial", "filing", "governance", "investor", "10 k", "10 q")
    ):
        return "investor-relations"
    if _components_include(components, ("新闻资讯",)) or _components_include_phrase(
        components, ("announcement", "press release", "official news")
    ):
        return "official-news"
    if _components_include(components, ("公司概况", "历史沿革")) or _components_include_phrase(
        components, ("corporate history", "company history")
    ):
        return "brand-company"
    if _components_include(components, ("产品目录", "产品分类树")):
        return "product-category"
    return None


def _components_include(components: tuple[str, ...], signals: tuple[str, ...]) -> bool:
    for component in components:
        tokens = frozenset(re.findall(r"[a-z0-9]+", component))
        for signal in signals:
            if signal.isascii() and signal in tokens:
                return True
            if not signal.isascii() and signal in component:
                return True
    return False


def _components_include_phrase(components: tuple[str, ...], phrases: tuple[str, ...]) -> bool:
    normalized_components = tuple(re.sub(r"[_-]+", " ", component) for component in components)
    return any(
        re.search(rf"(?<![a-z0-9]){re.escape(phrase.replace('_', ' '))}(?![a-z0-9])", component)
        for component in normalized_components
        for phrase in phrases
    )


def _route_content(markdown: str) -> str | None:
    text = markdown.casefold()
    candidates: list[str] = []
    if _contains_any(text, ("market analysis", "competition analysis", "traffic analysis", "third-party research")):
        candidates.append("research-reference")
    if _contains_any(text, ("annual report", "financial report", "investor relations", "corporate governance", "sec filing")):
        candidates.append("investor-relations")
    if _contains_any(text, ("new arrivals", "best sellers", "best seller")):
        candidates.append("new-hot-products")
    if _contains_any(text, ("delivery methods", "delivery time", "shipping information", "fulfilment")):
        candidates.append("logistics-fulfilment")
    if _contains_any(text, ("return policy", "privacy policy", "cookie policy", "terms and conditions")):
        candidates.append("policy-compliance")
    if _contains_any(text, ("company story", "corporate history", "about lightinthebox")):
        candidates.append("brand-company")
    unique = tuple(dict.fromkeys(candidates))
    return unique[0] if len(unique) == 1 else None


def _contains_any(text: str, signals: tuple[str, ...]) -> bool:
    return any(signal in text for signal in signals)


def _detect_language(markdown: str) -> str:
    prose = _prose_for_language(markdown)
    words = tuple(word.casefold() for word in _WORD.findall(prose))
    portuguese_score = sum(word in _PORTUGUESE_MARKERS for word in words)
    if "mais vendidos" in prose.casefold():
        portuguese_score += 2
    if "novo em" in prose.casefold():
        portuguese_score += 2
    english_score = sum(word in _ENGLISH_MARKERS for word in words)
    candidates = set()
    if len(_HAN.findall(prose)) >= 2:
        candidates.add("zh")
    if portuguese_score >= 2:
        candidates.add("pt")
    if english_score >= 2:
        candidates.add("en")
    return candidates.pop() if len(candidates) == 1 else "und"


def _prose_for_language(markdown: str) -> str:
    prose_lines: list[str] = []
    in_provenance = False
    for line in markdown.splitlines():
        if _PROVENANCE_HEADING.match(line):
            in_provenance = True
            continue
        if not in_provenance:
            prose_lines.append(line)
    return _URL.sub(" ", "\n".join(prose_lines))


def _governance_for(
    directory_path: str | None,
    document: NormalizedDocument,
) -> tuple[str, str, str, bool]:
    if directory_path == "research-reference":
        return "mixed_research", "high", "unreviewed", False
    if directory_path == "investor-relations":
        return "regulatory_filing", "medium", "reviewed", True
    if directory_path in {"policy-compliance", "customer-transaction", "logistics-fulfilment"}:
        # A retrieved date is unavailable during classification, so these are
        # deliberately ineligible until the builder/importer validates it.
        return "first_party", "medium", "unreviewed", False
    if directory_path is None:
        return "third_party", "high", "unreviewed", False
    return "first_party", "low", "reviewed", bool(document.markdown)
