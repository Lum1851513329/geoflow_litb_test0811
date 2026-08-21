import unittest
import os
import time

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
            "z/about.md", body, source_url="https://example.test/about", source_name="", source_checksum="", ingestion_method=""
        )
        older = self.document("b/about.md", body, source_url="https://example.test/about", retrieved_at="2026-01-01")
        winner = self.document("a/about.md", body, source_url="https://example.test/about", retrieved_at="2026-08-01")

        canonical, duplicates = resolve_exact([less_complete, older, winner])

        self.assertEqual([winner], canonical)
        self.assertEqual(
            {"z/about.md": "provenance_completeness", "b/about.md": "newer_lifecycle_date"},
            {record["relative_path"]: record["reason"] for record in duplicates},
        )

    def test_exact_duplicates_prefer_first_party_url_bearing_sources_before_completeness(self):
        body = "# About\n\nLightInTheBox serves global shoppers.\n"
        first_party = self.document(
            "z/about.txt", body, source_url="https://example.test/about", authority_level="first_party"
        )
        third_party = self.document(
            "a/about.txt", body, source_url="https://reference.test/about", authority_level="third_party"
        )

        canonical, duplicates = resolve_exact([third_party, first_party])

        self.assertEqual([first_party], canonical)
        self.assertEqual("first_party_url_bearing_source", duplicates[0]["reason"])

    def test_exact_duplicates_use_lexical_path_as_a_stable_final_tiebreaker(self):
        body = "# About\n\nLightInTheBox serves global shoppers.\n"
        zulu = self.document("z/about.md", body)
        alpha = self.document("a/about.md", body)

        canonical, duplicates = resolve_exact([zulu, alpha])

        self.assertEqual([alpha], canonical)
        self.assertEqual("lexical_path_tiebreaker", duplicates[0]["reason"])

    def test_exact_duplicates_merge_across_directory_families_within_brand_and_language(self):
        body = "# Shared Policy\n\nThis policy applies to all orders.\n"
        policy_copy = self.document("policies/shared.md", body, directory_path="policy-compliance")
        service_copy = self.document("service/shared.txt", body, directory_path="customer-transaction")

        canonical, duplicates = resolve_exact([service_copy, policy_copy])

        self.assertEqual([policy_copy], canonical)
        self.assertEqual("service/shared.txt", duplicates[0]["relative_path"])
        self.assertEqual("policies/shared.md", duplicates[0]["duplicate_of"])

    def test_naive_lifecycle_dates_are_compared_as_utc_independent_of_process_timezone(self):
        body = "# About\n\nLightInTheBox serves global shoppers.\n"
        naive = self.document("a/about.md", body, retrieved_at="2026-08-02T00:00:00")
        aware = self.document("z/about.md", body, retrieved_at="2026-08-02T02:00:00+00:00")
        original_timezone = os.environ.get("TZ")
        try:
            os.environ["TZ"] = "America/Los_Angeles"
            time.tzset()
            canonical, _ = resolve_exact([naive, aware])
        finally:
            if original_timezone is None:
                os.environ.pop("TZ", None)
            else:
                os.environ["TZ"] = original_timezone
            time.tzset()

        self.assertEqual([aware], canonical)

    def test_near_duplicates_report_uncertain_pairs_without_merging(self):
        shared = " ".join(f"policyword{number}" for number in range(50))
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

    def test_near_subset_with_stronger_provenance_does_not_discard_unique_superset_content(self):
        shared = " ".join(f"policyword{number}" for number in range(50))
        stronger_subset = self.document(
            "returns.md", f"# Returns\n\n{shared}\n", source_url="https://example.test/returns"
        )
        weaker_superset = self.document(
            "returns.txt", f"# Returns\n\n{shared}\n\nUnique official detail\n", source_url=""
        )

        records = find_near_duplicates([stronger_subset, weaker_superset])

        self.assertEqual(1, len(records))
        self.assertEqual("review", records[0]["action"])
        self.assertNotIn("duplicate_of", records[0])
        self.assertEqual("strict_subset_canonical_lacks_content", records[0]["reason"])

    def test_identical_bodies_with_different_front_matter_are_flagged_for_review(self):
        # Regression: 3.1_All You Need To Know.md and
        # 3.1_All_You_Need_To_Know/3.1.0_All You Need To Know.md share every
        # body paragraph and differ only in their heading number and their
        # "Original URL" provenance line.  Token Jaccard scores 0.868 (below
        # the 0.92 threshold) and neither fingerprint set is a strict subset
        # of the other, so both were previously admitted with no review
        # signal at all.
        body = (
            "- All You Need To Know About Covid-19\n\n"
            "------\n\n"
            "- **All You Need To Know About Wedding & Events**\n\n"
            "------\n\n"
            "- **All You Need To Know About Phone & Electronics**\n\n"
            "------\n\n"
            "- **All You Need To Know About Group Buy**\n\n"
            "------\n\n"
            "- **All You Need To Know About Men's Custom Suits**\n"
        )
        first = self.document(
            "03_Company_Policies/3.1_All You Need To Know.md",
            "# 3.1 All You Need To Know\n\n"
            "**Original URL:** https://example.test/pt/help/shopping-guide\n\n" + body,
            source_url="https://example.test/pt/help/shopping-guide",
        )
        second = self.document(
            "03_Company_Policies/3.1_All_You_Need_To_Know/3.1.0_All You Need To Know.md",
            "# 3.1.0 All You Need To Know\n\n"
            "**Original URL:** https://example.test/pt/nh/All-You-Need-To-Know.html\n\n" + body,
            source_url="https://example.test/pt/nh/All-You-Need-To-Know.html",
        )

        records = find_near_duplicates([first, second])

        self.assertEqual(1, len(records))
        self.assertEqual("near", records[0]["kind"])
        self.assertEqual("review", records[0]["action"])
        self.assertNotIn("duplicate_of", records[0])
        self.assertEqual("identical_body_requires_review", records[0]["reason"])
        self.assertEqual(
            {
                "03_Company_Policies/3.1_All You Need To Know.md",
                "03_Company_Policies/3.1_All_You_Need_To_Know/3.1.0_All You Need To Know.md",
            },
            set(records[0]["relative_paths"]),
        )
        self.assertLess(records[0]["similarity"], 0.92)

    def test_body_overlap_does_not_flag_documents_that_merely_share_a_boilerplate_paragraph(self):
        shared = "This policy applies to every order placed on the website.\n"
        first = self.document(
            "a.md",
            "# Returns\n\n" + shared + "\n" + "\n\n".join(f"Returns detail {n}." for n in range(8)),
        )
        second = self.document(
            "b.md",
            "# Shipping\n\n" + shared + "\n" + "\n\n".join(f"Shipping detail {n}." for n in range(8)),
        )

        self.assertEqual([], find_near_duplicates([first, second]))

    def test_body_overlap_ignores_documents_too_short_to_judge(self):
        # A one-paragraph stub that happens to repeat a paragraph of a longer
        # document is not evidence of duplication.
        paragraph = "Orders ship within three business days of payment.\n"
        stub = self.document("stub.md", "# Shipping\n\n" + paragraph)
        longer = self.document(
            "long.md",
            "# Shipping guide\n\n"
            + paragraph
            + "\n"
            + "\n\n".join(f"Shipping guide detail {n}." for n in range(12)),
        )

        self.assertEqual([], find_near_duplicates([stub, longer]))

    def test_near_duplicates_do_not_cross_brand_language_or_directory_family(self):
        shared = " ".join(f"policyword{number}" for number in range(30))
        first = self.document("a.md", f"# Returns\n\n{shared}\n")
        different_brand = self.document("b.md", f"# Returns\n\n{shared}\n", brand_key="other")
        different_language = self.document("c.md", f"# Returns\n\n{shared}\n", language_code="pt")
        different_directory = self.document("d.md", f"# Returns\n\n{shared}\n", directory_path="brand-company")

        self.assertEqual([], find_near_duplicates([first, different_brand, different_language, different_directory]))
        canonical, duplicates = resolve_exact([first, different_brand, different_language, different_directory])
        self.assertCountEqual([first, different_brand, different_language], canonical)
        self.assertEqual("d.md", duplicates[0]["relative_path"])
        self.assertEqual("a.md", duplicates[0]["duplicate_of"])
