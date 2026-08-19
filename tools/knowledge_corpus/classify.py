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

    path = _canonical_path(relative_path)
    directory_path = _route_path(path)
    if directory_path is None:
        directory_path = _route_content(document.markdown)

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


def _canonical_path(relative_path: str) -> str:
    return unicodedata.normalize("NFC", relative_path).replace("\\", "/").casefold()


def _route_path(path: str) -> str | None:
    """Apply source-folder rules in their declared precedence order."""
    if _contains_any(
        path,
        ("research", "market", "traffic", "competition", "reputation", "竞争", "市场", "流量", "口碑", "评价"),
    ):
        return "research-reference"
    if _contains_any(path, ("annual", "financial", "filing", "governance", "investor", "10-k", "10-q", "投资者关系", "公司治理", "财务")):
        return "investor-relations"
    if _contains_any(path, ("announcement", "announcements", "press-release", "press_release", "official-news", "official_news", "新闻资讯")):
        return "official-news"
    if _contains_any(path, ("公司概况", "历史沿革", "corporate-history", "corporate_history", "company-history", "company_history")):
        return "brand-company"
    if _contains_any(path, ("产品目录", "产品分类树")):
        return "product-category"
    if "01_company_info" in path:
        return "brand-company"
    if "02_customer_service" in path:
        if _contains_any(path, ("delivery", "shipping", "fulfilment", "fulfillment", "tracking", "dispatch")):
            return "logistics-fulfilment"
        return "customer-transaction"
    if "03_company_policies" in path:
        if "all you need to know" in path or "all_you_need_to_know" in path:
            return "product-category"
        return "policy-compliance"
    if "04_product_categories" in path:
        if _contains_any(
            path,
            ("new arrival", "new_arrival", "best seller", "best_seller", "bestseller", "novo em", "mais vendidos"),
        ):
            return "new-hot-products"
        return "product-category"
    return None


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
    if _HAN.search(markdown):
        return "zh"
    words = tuple(word.casefold() for word in _WORD.findall(markdown))
    portuguese_score = sum(word in _PORTUGUESE_MARKERS for word in words)
    english_score = sum(word in _ENGLISH_MARKERS for word in words)
    accented_portuguese = sum(character in "ãõçáéíóúâêô" for character in markdown.casefold())
    portuguese_score += accented_portuguese
    if portuguese_score >= 2 and portuguese_score > english_score:
        return "pt"
    if english_score:
        return "en"
    # The governed source is Portuguese/English/Chinese only. Plain Latin text
    # without Portuguese signals is treated as English; no filename is used.
    return "en" if any(character.isalpha() for character in markdown) else "und"


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
