import json
from pathlib import Path
import unittest

from tools.knowledge_corpus.classify import classify_document
from tools.knowledge_corpus.normalize import NormalizedDocument


class ClassifyDocumentTest(unittest.TestCase):
    def document(self, markdown, source_urls=()):
        return NormalizedDocument(
            markdown=markdown,
            normalized_hash="f" * 64,
            title="Fixture",
            source_urls=source_urls,
            warnings=(),
        )

    def test_routes_known_source_families_with_path_precedence(self):
        cases = (
            ("01_Company_Info/about.md", "# About\nOur company story.", "brand-company", "first_party", "low"),
            ("03_Company_Policies/returns.md", "# Returns\nReturn policy.", "policy-compliance", "first_party", "medium"),
            ("02_Customer_Service/delivery-times.md", "# Delivery\nShipping information.", "logistics-fulfilment", "first_party", "medium"),
            ("03_Company_Policies/All You Need To Know/dresses.md", "# Dresses\nBuying guide.", "product-category", "first_party", "low"),
            ("04_Product_Categories/New Arrivals.md", "# New arrivals\n", "new-hot-products", "first_party", "low"),
            ("04_Product_Categories/4.16_Mais vendidos.md", "# Mais vendidos\n", "new-hot-products", "first_party", "low"),
            ("LITB_Reports/2025 Annual Financial Report.md", "# Annual report\n", "investor-relations", "regulatory_filing", "medium"),
            ("research/market-and-competition-report.md", "# Research\nTraffic and competition analysis.", "research-reference", "mixed_research", "high"),
            ("officialknowege/LITB_Reports/01_公司概况与历史沿革.md", "# 公司概况\n", "brand-company", "first_party", "low"),
            ("officialknowege/LITB_Reports/10_投资者关系与公司治理.md", "# 投资者关系\n", "investor-relations", "regulatory_filing", "medium"),
            ("officialknowege/LITB_Reports/07_竞争格局与市场定位.md", "# 市场研究\n", "research-reference", "mixed_research", "high"),
        )

        for relative_path, markdown, directory_key, authority, risk in cases:
            with self.subTest(relative_path=relative_path):
                classification = classify_document(relative_path, self.document(markdown))

                self.assertEqual("lightinthebox", classification.brand_key)
                self.assertEqual("lightinthebox-main", classification.site_key)
                self.assertEqual(directory_key, classification.directory_path)
                self.assertEqual(authority, classification.authority_level)
                self.assertEqual(risk, classification.risk_level)

    def test_detects_portuguese_from_body_not_legacy_english_path_label(self):
        document = self.document(
            "# Política de devoluções\n\n"
            "Você pode solicitar a devolução dos produtos dentro do prazo informado.\n",
            ("https://www.lightinthebox.com/pt/returns",),
        )

        classification = classify_document("03_Company_Policies/Returns Policy.md", document)

        self.assertEqual("pt", classification.language_code)
        self.assertEqual("policy-compliance", classification.directory_path)
        self.assertFalse(classification.generation_eligible)
        self.assertTrue(classification.citation_required)

    def test_quarantines_ambiguous_route_instead_of_guessing(self):
        classification = classify_document("miscellaneous/notes.md", self.document("# Notes\nGeneric text."))

        self.assertIsNone(classification.directory_path)
        self.assertIn("ambiguous_directory_route", classification.warnings)
        self.assertFalse(classification.generation_eligible)

    def test_taxonomy_has_only_the_nine_stable_directory_keys(self):
        taxonomy_path = Path(__file__).resolve().parents[1] / "taxonomy.json"
        taxonomy = json.loads(taxonomy_path.read_text(encoding="utf-8"))

        self.assertEqual(
            {
                "brand-company",
                "customer-transaction",
                "policy-compliance",
                "product-category",
                "new-hot-products",
                "logistics-fulfilment",
                "official-news",
                "investor-relations",
                "research-reference",
            },
            set(taxonomy["directory_keys"]),
        )
