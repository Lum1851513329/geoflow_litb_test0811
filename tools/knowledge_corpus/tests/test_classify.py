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
                self.assertIn(classification.directory_path, {
                    "brand-company", "customer-transaction", "policy-compliance", "product-category",
                    "new-hot-products", "logistics-fulfilment", "official-news", "investor-relations",
                    "research-reference",
                })
                self.assertEqual(authority, classification.authority_level)
                self.assertEqual(risk, classification.risk_level)

    def test_controlled_source_root_precedes_filename_terms(self):
        company = classify_document(
            "01_Company_Info/market-reputation.md",
            self.document("# Market reputation\nCompetition analysis."),
        )
        marketplace = classify_document("misc/marketplace.md", self.document("# Marketplace\n"))
        non_report = classify_document("archive/annual-financial-report.md", self.document("# Notes\n"))

        self.assertEqual("brand-company", company.directory_path)
        self.assertIsNone(marketplace.directory_path)
        self.assertIn("ambiguous_directory_route", marketplace.warnings)
        self.assertIsNone(non_report.directory_path)

    def test_detects_language_only_from_positive_prose_evidence(self):
        cases = (
            ("# Mais vendidos\nNovo em\n", "pt"),
            ("# Política de devolución\n", "und"),
            ("# Note\n\n## Provenance\n- Source: https://example.com/中文\n", "und"),
            ("# Mais vendidos\nOur company story.\n", "und"),
            ("# About\nOur company story.\n", "en"),
            ("# 公司概况\n", "zh"),
        )

        for markdown, language_code in cases:
            with self.subTest(markdown=markdown):
                classification = classify_document("misc/notes.md", self.document(markdown))

                self.assertEqual(language_code, classification.language_code)
                if language_code == "und":
                    self.assertIn("ambiguous_language", classification.warnings)

    def test_chinese_link_annotations_do_not_overturn_english_prose(self):
        # The PT knowledge base annotates inline links with a Chinese "（链接：url）"
        # label.  Those residual Han characters must not make an English document
        # look bilingual, even when the English signal itself is modest.
        markdown = (
            "# All You Need To Know\n\n"
            "Read the shipping policy and our returns policy before you order.\n\n"
            "<u>Cookie Notice</u>（链接：https://www.lightinthebox.com/nh/Cookie-Notice.html）\n"
            "<u>Terms of Use</u>（链接：https://www.lightinthebox.com/nh/Term-Of-Use.html）\n"
            "**Email: **ipip@lightinthebox.com（链接：ipip@lightinthebox.com）\n"
        )

        classification = classify_document("misc/notes.md", self.document(markdown))

        self.assertEqual("en", classification.language_code)
        self.assertNotIn("ambiguous_language", classification.warnings)

    def test_still_detects_chinese_prose_including_annotated_links(self):
        chinese_prose = (
            "# 公司概况\n\n"
            "兰亭集势是一家总部位于中国的跨境电子商务公司，主要通过自有网站向全球"
            "消费者销售服装、家居用品与消费电子产品。公司成立于二零零七年，并于二"
            "零一三年在纽约证券交易所挂牌上市。\n"
        )
        cases = (
            chinese_prose,
            chinese_prose + "\n参见隐私政策（链接：https://www.lightinthebox.com/nh/Privacy-Note.html）\n",
        )

        for markdown in cases:
            with self.subTest(markdown=markdown):
                classification = classify_document("misc/notes.md", self.document(markdown))

                self.assertEqual("zh", classification.language_code)
                self.assertNotIn("ambiguous_language", classification.warnings)

    def test_genuinely_bilingual_prose_stays_ambiguous(self):
        markdown = (
            "# 退货政策 / Return Policy\n\n"
            "本公司的退货政策适用于所有通过官方网站购买的商品，消费者可以在收到"
            "包裹之后申请退货或者换货，具体的运费与时效请参阅下方说明。\n\n"
            "Our company applies the same return policy to the products we ship. "
            "The returns policy and the delivery timelines are described for every "
            "customer in our annual report about shipping and delivery.\n"
        )

        classification = classify_document("misc/notes.md", self.document(markdown))

        self.assertEqual("und", classification.language_code)
        self.assertIn("ambiguous_language", classification.warnings)

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
