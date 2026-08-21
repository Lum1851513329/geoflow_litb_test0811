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

    def test_residual_han_tolerance_does_not_grow_with_english_document_length(self):
        # Reviewer reproduction.  The original guard was proportional
        # (han_count * 4 >= latin_score), so the amount of Chinese text an
        # English page could absorb scaled with the page's own length: a
        # 115-character Chinese jurisdiction notice appended to the real
        # 3.6_Terms of Use.md (english_score 473) still resolved to "en"
        # because 115 * 4 == 460 < 473.  The fixture below reproduces those
        # proportions (english_score 475, han_count 112, 112 * 4 == 448 < 475)
        # so the proportional clause alone would still answer "en".
        english_clause = (
            "The company and our customer support team publish the return policy, "
            "the returns policy and the shipping policy about delivery for our "
            "customers in the annual report about our products.\n"
        )
        english_body = "# Terms of Use\n\n" + english_clause * 19
        chinese_notice = (
            "本条款的解释与适用均以中华人民共和国法律为准据法，任何因本条款引起的或者与"
            "本条款有关的争议，双方应当首先通过友好协商的方式解决，协商不成的，任何一方"
            "均有权将争议提交至公司住所地有管辖权的人民法院通过诉讼方式解决。"
            "本声明自公布之日起生效。"
        )

        english_only = classify_document("misc/terms.md", self.document(english_body))
        with_chinese_block = classify_document(
            "misc/terms.md",
            self.document(f"{english_body}\n{chinese_notice}\n"),
        )

        self.assertEqual("en", english_only.language_code)
        self.assertEqual("und", with_chinese_block.language_code)
        self.assertIn("ambiguous_language", with_chinese_block.warnings)

    def test_small_residual_han_never_overturns_a_dominant_latin_signal(self):
        # The other side of the same boundary: a handful of stray Han
        # characters is markup residue, not evidence of a Chinese document,
        # no matter how short or long the surrounding English prose is.
        english_clause = (
            "The company and our customer support team publish the return policy, "
            "the returns policy and the shipping policy about delivery for our "
            "customers in the annual report about our products.\n"
        )
        for repetitions in (1, 19):
            with self.subTest(repetitions=repetitions):
                markdown = (
                    "# Terms of Use\n\n"
                    + english_clause * repetitions
                    + "\n参见条款说明\n"
                )

                classification = classify_document("misc/terms.md", self.document(markdown))

                self.assertEqual("en", classification.language_code)
                self.assertNotIn("ambiguous_language", classification.warnings)

    def test_link_annotation_strip_never_swallows_prose_across_lines(self):
        # The annotation matcher must stay on one line: an unclosed opener
        # followed by unrelated prose and a later closing parenthesis must
        # not delete the English sentences in between.
        markdown = (
            "# All You Need To Know\n\n"
            "（链接：https://www.lightinthebox.com/nh/Cookie-Notice.html\n"
            "Read the shipping policy and our returns policy before you order.\n"
            "The company publishes delivery timelines for every customer.\n"
            "）\n"
        )

        classification = classify_document("misc/notes.md", self.document(markdown))

        self.assertEqual("en", classification.language_code)
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
