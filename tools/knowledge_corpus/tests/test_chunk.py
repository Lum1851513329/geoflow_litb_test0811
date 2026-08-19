import re
import unittest

from tools.knowledge_corpus.chunk import build_chunks
from tools.knowledge_corpus.classify import Classification
from tools.knowledge_corpus.normalize import normalize_document


class BuildChunksTest(unittest.TestCase):
    def document(self, path, body, *, language_code="en", directory_path="policy-compliance"):
        return {
            "relative_path": path,
            "normalized": normalize_document(body),
            "classification": Classification(
                brand_key="lightinthebox",
                site_key="lightinthebox-main",
                directory_path=directory_path,
                language_code=language_code,
                authority_level="first_party",
                risk_level="medium",
                review_status="reviewed",
                generation_eligible=True,
                citation_required=True,
            ),
            "source_url": "https://example.test/returns",
            "effective_date": "2026-07-01",
            "retrieved_at": "2026-08-01",
            "lifecycle_status": "active",
        }

    def token_count(self, text):
        return len(re.findall(r"[A-Za-z0-9]+(?:['’-][A-Za-z0-9]+)?", text))

    def test_chunks_preserve_heading_paths_and_governance_metadata(self):
        document = self.document(
            "policies/returns.md",
            "# Returns\n\nIntroductory policy context.\n\n## Eligibility\n\nItems must meet the stated conditions.\n",
        )

        chunks = build_chunks(document)

        self.assertEqual(2, len(chunks))
        self.assertEqual("Returns", chunks[0]["section_path"])
        self.assertEqual("Returns > Eligibility", chunks[1]["section_path"])
        self.assertEqual("lightinthebox", chunks[0]["metadata"]["brand_key"])
        self.assertEqual("lightinthebox-main", chunks[0]["metadata"]["site_key"])
        self.assertEqual("en", chunks[0]["metadata"]["language_code"])
        self.assertEqual("first_party", chunks[0]["metadata"]["authority_level"])
        self.assertEqual("active", chunks[0]["metadata"]["lifecycle_status"])
        self.assertEqual("https://example.test/returns", chunks[0]["metadata"]["source_url"])

    def test_long_section_splits_at_paragraph_or_list_boundaries_within_token_targets(self):
        paragraphs = [
            " ".join(f"paragraph{paragraph}word{word}" for word in range(120))
            for paragraph in range(6)
        ]
        document = self.document("policies/long.md", "# Returns\n\n" + "\n\n".join(paragraphs) + "\n")

        chunks = build_chunks(document, target_min=350, target_max=700)

        self.assertGreaterEqual(len(chunks), 2)
        for chunk in chunks:
            self.assertLessEqual(self.token_count(chunk["content"]), 700)
            self.assertTrue(chunk["content"].startswith("# Returns"))
            self.assertTrue(any(paragraph in chunk["content"] for paragraph in paragraphs))

    def test_oversized_list_splits_only_between_list_items(self):
        items = [
            "- " + " ".join(f"item{item}word{word}" for word in range(120))
            for item in range(6)
        ]
        document = self.document("policies/list.md", "# Returns\n\n" + "\n".join(items) + "\n")

        chunks = build_chunks(document, target_min=350, target_max=700)

        self.assertGreaterEqual(len(chunks), 2)
        self.assertTrue(all(self.token_count(chunk["content"]) <= 700 for chunk in chunks))
        for item in items:
            self.assertTrue(any(item in chunk["content"] for chunk in chunks))

    def test_chunks_never_mix_documents_or_governance_scope(self):
        english = self.document("policies/en.md", "# Returns\n\nEnglish policy evidence.\n")
        portuguese = self.document(
            "policies/pt.md", "# Returns\n\nPortuguese policy evidence.\n", language_code="pt"
        )

        english_chunks = build_chunks(english)
        portuguese_chunks = build_chunks(portuguese)

        self.assertTrue(all("Portuguese" not in chunk["content"] for chunk in english_chunks))
        self.assertTrue(all("English" not in chunk["content"] for chunk in portuguese_chunks))
        self.assertEqual("en", english_chunks[0]["metadata"]["language_code"])
        self.assertEqual("pt", portuguese_chunks[0]["metadata"]["language_code"])
