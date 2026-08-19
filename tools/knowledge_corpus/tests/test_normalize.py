import unittest

from tools.knowledge_corpus.normalize import normalize_document


class NormalizeDocumentTest(unittest.TestCase):
    def test_normalizes_formatting_without_rewriting_claims_and_is_idempotent(self):
        raw = (
            "# Cafe\u0301  \r\n"
            "\r\n"
            "【Política de devoluções】(https://www.lightinthebox.com/pt/returns)  \r\n"
            "###   \r\n"
            " \\ \r\n"
            "A política permanece como foi publicada.   \r\n"
            "\r\n"
            "\r\n"
            "\r\n"
            "## Provenance\r\n"
            "- Source: https://www.lightinthebox.com/pt/returns\r\n"
        )

        document = normalize_document(raw)
        repeat = normalize_document(document.markdown)

        self.assertEqual(
            "# Café\n\n"
            "[Política de devoluções](https://www.lightinthebox.com/pt/returns)\n\n"
            "A política permanece como foi publicada.\n\n"
            "## Provenance\n"
            "- Source: https://www.lightinthebox.com/pt/returns\n",
            document.markdown,
        )
        self.assertEqual("Café", document.title)
        self.assertEqual(("https://www.lightinthebox.com/pt/returns",), document.source_urls)
        self.assertIn("removed_empty_heading", document.warnings)
        self.assertIn("removed_isolated_backslash", document.warnings)
        self.assertEqual(document.markdown, repeat.markdown)
        self.assertEqual(document.normalized_hash, repeat.normalized_hash)

    def test_preserves_all_non_format_claim_text(self):
        raw = "# Terms\n\nRefunds are subject to the stated policy.\n"

        document = normalize_document(raw)

        self.assertIn("Refunds are subject to the stated policy.", document.markdown)
        self.assertNotIn("reembolso", document.markdown.casefold())

    def test_converts_full_width_chinese_inline_link_wrapper(self):
        document = normalize_document("【Página de devoluções】（https://www.lightinthebox.com/pt/returns）\n")

        self.assertEqual(
            "[Página de devoluções](https://www.lightinthebox.com/pt/returns)\n",
            document.markdown,
        )
        self.assertEqual(("https://www.lightinthebox.com/pt/returns",), document.source_urls)
