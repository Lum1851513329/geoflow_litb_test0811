import unittest

from tools.knowledge_corpus.classify import Classification
from tools.knowledge_corpus.deduplicate import find_near_duplicates, resolve_exact
from tools.knowledge_corpus.normalize import normalize_document


class DeduplicateTest(unittest.TestCase):
    def document(self, path, body, *, source_url="", directory_path="policy-compliance", **overrides):
        document = normalize_document(body)
        classification = Classification(
            brand_key=overrides.pop("brand_key", "lightinthebox"),
            site_key=overrides.pop("site_key", "lightinthebox-main"),
            directory_path=directory_path,
            language_code=overrides.pop("language_code", "en"),
            authority_level=overrides.pop("authority_level", "first_party"),
            risk_level="medium",
            review_status="reviewed",
            generation_eligible=True,
            citation_required=True,
        )
        return {
            "relative_path": path,
            "normalized": document,
            "classification": classification,
            "source_url": source_url,
            "source_name": overrides.pop("source_name", "Official Help Centre"),
            "source_checksum": overrides.pop("source_checksum", "a" * 64),
            "ingestion_method": overrides.pop("ingestion_method", "folder"),
            "retrieved_at": overrides.pop("retrieved_at", "2026-08-01"),
            "effective_date": overrides.pop("effective_date", "2026-07-01"),
            **overrides,
        }

    def test_exact_duplicates_keep_markdown_and_audit_every_discarded_mirror(self):
        body = "# Returns\n\nReturns are accepted within 30 days.\n"
        markdown = self.document(
            "03_Company_Policies/returns.md", body, source_url="https://example.test/returns"
        )
        txt = self.document("03_Company_Policies/returns.txt", body)
        second_txt = self.document("archive/returns.txt", body)

        canonical, duplicates = resolve_exact([txt, second_txt, markdown])

        self.assertEqual([markdown], canonical)
        self.assertEqual(2, len(duplicates))
        self.assertEqual(
            {"03_Company_Policies/returns.txt", "archive/returns.txt"},
            {record["relative_path"] for record in duplicates},
        )
        for record in duplicates:
            self.assertEqual("03_Company_Policies/returns.md", record["duplicate_of"])
            self.assertEqual("markdown_over_txt", record["reason"])

    def test_exact_duplicates_use_complete_provenance_then_newest_date_then_lexical_path(self):
        body = "# About\n\nLightInTheBox serves global shoppers.\n"
        less_complete = self.document(
            "z/about.md", body, source_url="", source_name="", source_checksum="", ingestion_method=""
        )
        older = self.document("b/about.md", body, retrieved_at="2026-01-01")
        winner = self.document("a/about.md", body, retrieved_at="2026-08-01")

        canonical, duplicates = resolve_exact([less_complete, older, winner])

        self.assertEqual([winner], canonical)
        self.assertEqual(
            {"z/about.md": "provenance_completeness", "b/about.md": "newer_lifecycle_date"},
            {record["relative_path"]: record["reason"] for record in duplicates},
        )

    def test_exact_duplicates_use_lexical_path_as_a_stable_final_tiebreaker(self):
        body = "# About\n\nLightInTheBox serves global shoppers.\n"
        zulu = self.document("z/about.md", body)
        alpha = self.document("a/about.md", body)

        canonical, duplicates = resolve_exact([zulu, alpha])

        self.assertEqual([alpha], canonical)
        self.assertEqual("lexical_path_tiebreaker", duplicates[0]["reason"])

    def test_near_duplicates_report_uncertain_pairs_without_merging(self):
        shared = " ".join(f"policyword{number}" for number in range(30))
        first = self.document("a.md", f"# Returns\n\n{shared} approved\n")
        second = self.document("b.md", f"# Returns\n\n{shared} pending\n")

        records = find_near_duplicates([first, second])

        self.assertEqual(1, len(records))
        self.assertEqual("review", records[0]["action"])
        self.assertNotIn("duplicate_of", records[0])
        self.assertGreaterEqual(records[0]["similarity"], 0.92)

    def test_near_subset_mirror_merges_only_when_provenance_precedence_is_unambiguous(self):
        shared = " ".join(f"policyword{number}" for number in range(30))
        canonical = self.document(
            "returns.md",
            f"# Returns\n\n{shared}\n\nOfficial summary\n",
            source_url="https://example.test/returns",
        )
        subset = self.document("returns.txt", f"# Returns\n\n{shared}\n", source_url="")

        records = find_near_duplicates([subset, canonical])

        self.assertEqual(1, len(records))
        self.assertEqual("merge", records[0]["action"])
        self.assertEqual("returns.md", records[0]["duplicate_of"])
        self.assertEqual("strict_subset_unambiguous_provenance", records[0]["reason"])

    def test_near_duplicates_do_not_cross_brand_language_or_directory_family(self):
        shared = " ".join(f"policyword{number}" for number in range(30))
        first = self.document("a.md", f"# Returns\n\n{shared}\n")
        different_brand = self.document("b.md", f"# Returns\n\n{shared}\n", brand_key="other")
        different_language = self.document("c.md", f"# Returns\n\n{shared}\n", language_code="pt")
        different_directory = self.document("d.md", f"# Returns\n\n{shared}\n", directory_path="brand-company")

        self.assertEqual([], find_near_duplicates([first, different_brand, different_language, different_directory]))
        canonical, duplicates = resolve_exact([first, different_brand, different_language, different_directory])
        self.assertCountEqual([first, different_brand, different_language, different_directory], canonical)
        self.assertEqual([], duplicates)
