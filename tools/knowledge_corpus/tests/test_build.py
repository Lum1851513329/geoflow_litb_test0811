import hashlib
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

from tools.knowledge_corpus import build as corpus_build
from tools.knowledge_corpus.build import BuildError, build_corpus, main


class CorpusBuilderTest(unittest.TestCase):
    def setUp(self):
        self.temporary_directory = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary_directory.name)
        self.source = self.root / "source"
        self.output = self.root / "output"

    def tearDown(self):
        self.temporary_directory.cleanup()

    def write(self, relative_path, content):
        path = self.source / relative_path
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(content, encoding="utf-8")
        return path

    def source_hashes(self):
        return {
            path.relative_to(self.source).as_posix(): hashlib.sha256(path.read_bytes()).hexdigest()
            for path in sorted(self.source.rglob("*"))
            if path.is_file()
        }

    def jsonl(self, output, name):
        text = (output / name).read_text(encoding="utf-8")
        return [json.loads(line) for line in text.splitlines() if line]

    def artifact_bytes(self, output):
        return {
            path.relative_to(output).as_posix(): path.read_bytes()
            for path in sorted(output.rglob("*"))
            if path.is_file()
        }

    def make_source_structure(self):
        for family in corpus_build.OFFICIAL_FAMILIES:
            (self.source / corpus_build.CANONICAL_MARKDOWN_ROOT / family).mkdir(
                parents=True, exist_ok=True
            )
            (self.source / corpus_build.TXT_MIRROR_ROOT / family).mkdir(
                parents=True, exist_ok=True
            )
        (self.source / corpus_build.REPORT_ROOT).mkdir(parents=True, exist_ok=True)

    def make_governed_fixture(self):
        self.make_source_structure()
        old_about = self.write(
            "LightInTheBox_PT_Official_KB/01_Company_Info/archive/about-2025.md",
            "# About LightInTheBox\n\n"
            "**Original URL:** https://www.lightinthebox.com/company/about\n"
            "**Retrieved Date:** 2025-01-10\n\n"
            "Our company serves customers around the world.\n",
        )
        current_about = self.write(
            "LightInTheBox_PT_Official_KB/01_Company_Info/about-2026.md",
            "# About LightInTheBox\n\n"
            "**Original URL:** https://www.lightinthebox.com/company/about\n"
            "**Retrieved Date:** 2026-08-01\n\n"
            "Our company serves customers around the world through the LightInTheBox site.\n",
        )
        policy_body = (
            "# Returns\n\n"
            "**Original URL:** https://www.lightinthebox.com/policies/returns\n\n"
            "The return policy explains how customers return products.\n"
        )
        policy = self.write(
            "LightInTheBox_PT_Official_KB/03_Company_Policies/returns.md",
            policy_body,
        )
        mirror = self.write(
            "LightInTheBox_PT_Official_KB_TXT/03_Company_Policies/returns.txt",
            policy_body,
        )
        research = self.write(
            "officialknowege/LITB_Reports/07_market_and_competition_report.md",
            "# Market and competition report\n\n"
            "The report compares market traffic and customer research.\n",
        )
        portuguese = self.write(
            "LightInTheBox_PT_Official_KB/04_Product_Categories/vestidos.md",
            "# Vestidos\n\nVocê encontra produtos para clientes e suas compras.\n",
        )
        portuguese_mirror = self.write(
            "LightInTheBox_PT_Official_KB_TXT/04_Product_Categories/vestidos.txt",
            "# Vestidos\n\nVocê encontra produtos para clientes e suas compras.\n",
        )
        chinese = self.write(
            "officialknowege/LITB_Reports/02_财务数据全面分析.md",
            "# 财务分析\n\n这是公司财务数据与年度报告。\n",
        )
        undetermined = self.write(
            "LightInTheBox_PT_Official_KB/04_Product_Categories/catalogue.md",
            "# SKU Catalogue\n\nSKU-1001\n",
        )
        self.write(
            "LightInTheBox_PT_Keyword_Library/keywords.md",
            "# Keywords\n\nThe company keyword library must not become prose.\n",
        )
        self.write("knowledge_cleanup_work/output/stale.md", "# Stale\n\nThe old output.\n")
        self.write("LightInTheBox_PT_Official_KB/.DS_Store", "ignored")
        return {
            "old_about": old_about,
            "current_about": current_about,
            "policy": policy,
            "mirror": mirror,
            "research": research,
            "portuguese": portuguese,
            "portuguese_mirror": portuguese_mirror,
            "chinese": chinese,
            "undetermined": undetermined,
        }

    def test_build_is_reproducible_read_only_auditable_and_english_only(self):
        fixture = self.make_governed_fixture()
        source_hashes_before = self.source_hashes()

        summary = build_corpus(
            self.source,
            self.output,
            brand="lightinthebox",
            site="lightinthebox-main",
        )

        self.assertEqual(source_hashes_before, self.source_hashes())
        manifest = self.jsonl(self.output, "manifest.jsonl")
        duplicates = self.jsonl(self.output, "duplicate_audit.jsonl")
        exclusions = self.jsonl(self.output, "exclusion_audit.jsonl")
        near_review = self.jsonl(self.output, "near_duplicate_review.jsonl")

        self.assertEqual(4, len(manifest))
        self.assertEqual({"en"}, {record["language_code"] for record in manifest})
        self.assertEqual({"pt", "zh", "und"}, {record["language_code"] for record in exclusions})
        self.assertEqual(4, len(exclusions))
        self.assertTrue(all(record["reason"] == "non_english_initial_corpus" for record in exclusions))

        self.assertEqual(2, len(duplicates))
        self.assertEqual({"exact"}, {record["kind"] for record in duplicates})
        mirror_duplicate = next(
            record for record in duplicates
            if record["source_file_path"] == fixture["mirror"].relative_to(self.source).as_posix()
        )
        self.assertEqual(
            fixture["mirror"].relative_to(self.source).as_posix(),
            mirror_duplicate["source_file_path"],
        )
        self.assertEqual(
            fixture["policy"].relative_to(self.source).as_posix(),
            mirror_duplicate["duplicate_of_source_file_path"],
        )
        audit_target_ids = {
            record["document_id"] for record in [*manifest, *exclusions]
        }
        self.assertTrue(all(
            record["duplicate_of_document_id"] in audit_target_ids
            for record in duplicates
        ))
        self.assertEqual([], near_review)

        about_versions = [
            record for record in manifest
            if record["canonical_document_key"] == "url:https://www.lightinthebox.com/company/about"
        ]
        self.assertEqual(2, len(about_versions))
        self.assertEqual(
            {"active", "superseded"},
            {record["lifecycle_status"] for record in about_versions},
        )
        active_about = next(record for record in about_versions if record["lifecycle_status"] == "active")
        self.assertEqual(
            fixture["current_about"].relative_to(self.source).as_posix(),
            active_about["source_file_path"],
        )
        self.assertEqual(2, len({record["document_id"] for record in about_versions}))

        policy_record = next(
            record for record in manifest
            if record["source_file_path"] == fixture["policy"].relative_to(self.source).as_posix()
        )
        self.assertFalse(policy_record["generation_eligible"])
        self.assertEqual("", policy_record["retrieved_at"])

        self.assertEqual(4, len(list((self.output / "cleaned").rglob("*.md"))))
        for record in manifest:
            cleaned = self.output / record["cleaned_file_path"]
            self.assertTrue(cleaned.is_file())
            self.assertEqual(
                record["cleaned_file_hash"],
                hashlib.sha256(cleaned.read_bytes()).hexdigest(),
            )
            self.assertIn(f'document_id: "{record["document_id"]}"', cleaned.read_text(encoding="utf-8"))

        self.assertEqual(7, summary["counts"]["canonical_source_files"])
        self.assertEqual(2, summary["counts"]["txt_mirror_files"])
        self.assertEqual(4, summary["counts"]["manifest_documents"])
        self.assertEqual(2, summary["counts"]["duplicate_records"])
        self.assertEqual(4, summary["counts"]["excluded_sources"])
        self.assertTrue(summary["validation"]["source_hashes_unchanged"])
        self.assertTrue(summary["validation"]["zero_active_exact_hash_duplicates"])
        self.assertTrue(summary["validation"]["valid_duplicate_targets"])
        self.assertTrue(summary["validation"]["policy_eligibility_rules"])

        second_output = self.root / "second-output"
        second_summary = build_corpus(
            self.source,
            second_output,
            brand="lightinthebox",
            site="lightinthebox-main",
        )
        self.assertEqual(summary, second_summary)
        self.assertEqual(self.artifact_bytes(self.output), self.artifact_bytes(second_output))

    def test_required_metadata_failure_is_nonzero_and_preserves_published_output(self):
        self.make_governed_fixture()
        build_corpus(self.source, self.output, brand="lightinthebox", site="lightinthebox-main")
        published_before = self.artifact_bytes(self.output)
        self.write(
            "LightInTheBox_PT_Official_KB/01_Company_Info/missing-title.md",
            "Our company serves the customers.\n",
        )

        exit_code = main([
            "--source", str(self.source),
            "--output", str(self.output),
            "--brand", "lightinthebox",
            "--site", "lightinthebox-main",
        ])

        self.assertNotEqual(0, exit_code)
        self.assertEqual(published_before, self.artifact_bytes(self.output))

    def test_source_symlink_escape_is_rejected_before_publication(self):
        self.make_governed_fixture()
        outside = self.root / "outside.md"
        outside.write_text("# Outside\n\nOur company serves customers.\n", encoding="utf-8")
        escaped = self.source / "LightInTheBox_PT_Official_KB/01_Company_Info/escaped.md"
        escaped.symlink_to(outside)

        with self.assertRaises(BuildError):
            build_corpus(self.source, self.output, brand="lightinthebox", site="lightinthebox-main")

        self.assertFalse(self.output.exists())

    def test_output_symlink_is_rejected_without_touching_its_target(self):
        self.make_governed_fixture()
        victim = self.root / "victim"
        victim.mkdir()
        marker = victim / "keep.txt"
        marker.write_text("untouched", encoding="utf-8")
        self.output.symlink_to(victim, target_is_directory=True)

        with self.assertRaises(BuildError):
            build_corpus(self.source, self.output, brand="lightinthebox", site="lightinthebox-main")

        self.assertTrue(self.output.is_symlink())
        self.assertEqual("untouched", marker.read_text(encoding="utf-8"))
        self.assertEqual([marker], list(victim.iterdir()))

    def test_output_ancestor_symlink_into_source_is_rejected_without_cleanup_aliasing(self):
        self.make_governed_fixture()
        marker = self.write("victim-marker.bin", "source victim must remain untouched")
        source_hashes_before = self.source_hashes()
        source_alias = self.root / "source-alias"
        source_alias.symlink_to(self.source, target_is_directory=True)
        aliased_output = source_alias / "knowledge_cleanup_work" / "output"

        with self.assertRaises(BuildError):
            build_corpus(
                self.source,
                aliased_output,
                brand="lightinthebox",
                site="lightinthebox-main",
            )

        self.assertEqual("source victim must remain untouched", marker.read_text(encoding="utf-8"))
        self.assertEqual(source_hashes_before, self.source_hashes())
        self.assertEqual(
            "# Stale\n\nThe old output.\n",
            (self.source / "knowledge_cleanup_work/output/stale.md").read_text(encoding="utf-8"),
        )

    def test_atomic_exchange_failure_leaves_existing_output_byte_identical(self):
        self.make_governed_fixture()
        build_corpus(self.source, self.output, brand="lightinthebox", site="lightinthebox-main")
        published_before = self.artifact_bytes(self.output)

        with patch.object(corpus_build, "_atomic_exchange", side_effect=OSError("injected exchange failure")):
            with self.assertRaises(OSError):
                build_corpus(
                    self.source,
                    self.output,
                    brand="lightinthebox",
                    site="lightinthebox-main",
                )

        self.assertEqual(published_before, self.artifact_bytes(self.output))
        self.assertFalse(any(path.name.startswith(".output.old-") for path in self.root.iterdir()))

    def test_existing_output_uses_one_atomic_exchange_and_remains_readable(self):
        self.make_governed_fixture()
        build_corpus(self.source, self.output, brand="lightinthebox", site="lightinthebox-main")
        real_exchange = corpus_build._atomic_exchange

        with patch.object(corpus_build, "_atomic_exchange", wraps=real_exchange) as exchange:
            summary = build_corpus(
                self.source,
                self.output,
                brand="lightinthebox",
                site="lightinthebox-main",
            )

        exchange.assert_called_once()
        self.assertEqual(summary, json.loads((self.output / "run_summary.json").read_text(encoding="utf-8")))
        self.assertEqual(4, len(self.jsonl(self.output, "manifest.jsonl")))
        self.assertFalse(any(path.name.startswith(".output.old-") for path in self.root.iterdir()))

    def test_old_output_cleanup_failure_does_not_report_publication_failure(self):
        self.make_governed_fixture()
        build_corpus(self.source, self.output, brand="lightinthebox", site="lightinthebox-main")

        with patch.object(corpus_build.shutil, "rmtree", side_effect=OSError("injected cleanup failure")):
            summary = build_corpus(
                self.source,
                self.output,
                brand="lightinthebox",
                site="lightinthebox-main",
            )

        self.assertEqual(4, summary["counts"]["manifest_documents"])
        self.assertEqual(4, len(self.jsonl(self.output, "manifest.jsonl")))

    def test_unsupported_atomic_exchange_platform_fails_without_mutation(self):
        first = self.root / "first"
        second = self.root / "second"
        first.mkdir()
        second.mkdir()
        (first / "new.txt").write_text("new", encoding="utf-8")
        (second / "live.txt").write_text("live", encoding="utf-8")

        with patch.object(corpus_build.platform, "system", return_value="UnsupportedOS"):
            with self.assertRaises(BuildError):
                corpus_build._atomic_exchange(first, second)

        self.assertEqual({"new.txt": b"new"}, self.artifact_bytes(first))
        self.assertEqual({"live.txt": b"live"}, self.artifact_bytes(second))

    def test_atomic_exchange_uses_platform_specific_at_fdcwd(self):
        self.assertEqual(-2, corpus_build._at_fdcwd("Darwin"))
        self.assertEqual(-100, corpus_build._at_fdcwd("Linux"))
        with self.assertRaises(BuildError):
            corpus_build._at_fdcwd("UnsupportedOS")

    def test_exact_then_near_duplicate_chain_points_directly_to_final_document(self):
        self.make_governed_fixture()
        shared = "The return policy explains customers and products. " + " ".join(
            f"policyword{number}" for number in range(100)
        )
        exact_canonical = self.write(
            "LightInTheBox_PT_Official_KB/03_Company_Policies/chain-a.md",
            f"# Shared policy\n\n{shared}\n",
        )
        exact_mirror = self.write(
            "LightInTheBox_PT_Official_KB_TXT/03_Company_Policies/chain-a.txt",
            f"# Shared policy\n\n{shared}\n",
        )
        final_canonical = self.write(
            "LightInTheBox_PT_Official_KB/03_Company_Policies/chain-c.md",
            "# Shared policy\n\n"
            "**Original URL:** https://www.lightinthebox.com/policies/shared\n\n"
            f"{shared}\n\nOfficial summary.\n",
        )

        build_corpus(self.source, self.output, brand="lightinthebox", site="lightinthebox-main")

        manifest = self.jsonl(self.output, "manifest.jsonl")
        duplicates = self.jsonl(self.output, "duplicate_audit.jsonl")
        final_path = final_canonical.relative_to(self.source).as_posix()
        final_record = next(record for record in manifest if record["source_file_path"] == final_path)
        chained_sources = {
            exact_canonical.relative_to(self.source).as_posix(),
            exact_mirror.relative_to(self.source).as_posix(),
        }
        chained_audits = [
            record for record in duplicates if record["source_file_path"] in chained_sources
        ]

        self.assertEqual(2, len(chained_audits))
        self.assertEqual({final_path}, {
            record["duplicate_of_source_file_path"] for record in chained_audits
        })
        self.assertEqual({final_record["document_id"]}, {
            record["duplicate_of_document_id"] for record in chained_audits
        })

    def test_identical_english_txt_orphans_resolve_to_final_exclusion_canonical(self):
        self.make_governed_fixture()
        orphan_body = (
            "# Orphan mirror\n\n"
            "The company return policy serves customers and products.\n"
        )
        orphan_paths = {
            self.write(
                "LightInTheBox_PT_Official_KB_TXT/02_Customer_Service/orphan-a.txt",
                orphan_body,
            ).relative_to(self.source).as_posix(),
            self.write(
                "LightInTheBox_PT_Official_KB_TXT/02_Customer_Service/archive/orphan-b.txt",
                orphan_body,
            ).relative_to(self.source).as_posix(),
        }

        summary = build_corpus(
            self.source,
            self.output,
            brand="lightinthebox",
            site="lightinthebox-main",
        )

        manifest = self.jsonl(self.output, "manifest.jsonl")
        duplicates = self.jsonl(self.output, "duplicate_audit.jsonl")
        exclusions = self.jsonl(self.output, "exclusion_audit.jsonl")
        orphan_exclusions = [
            record for record in exclusions
            if record["reason"] == "txt_mirror_without_markdown_canonical"
        ]
        exact_orphan = next(
            record for record in duplicates
            if record["kind"] == "exact" and record["source_file_path"] in orphan_paths
        )

        self.assertEqual(1, len(orphan_exclusions))
        target = orphan_exclusions[0]
        self.assertEqual("en", target["language_code"])
        self.assertIn(target["source_file_path"], orphan_paths)
        self.assertEqual(target["source_file_path"], exact_orphan["duplicate_of_source_file_path"])
        self.assertEqual(target["document_id"], exact_orphan["duplicate_of_document_id"])
        self.assertTrue(orphan_paths.isdisjoint({record["source_file_path"] for record in manifest}))
        self.assertTrue(summary["validation"]["valid_duplicate_targets"])

    def test_newest_lifecycle_date_wins_across_retrieved_and_effective_fields(self):
        self.make_governed_fixture()
        older = self.write(
            "LightInTheBox_PT_Official_KB/01_Company_Info/date-old.md",
            "# Date version\n\n"
            "**Original URL:** https://www.lightinthebox.com/company/date-version\n"
            "**Retrieved Date:** 2025-12-31\n\n"
            "Our company serves customers with the older version.\n",
        )
        newer = self.write(
            "LightInTheBox_PT_Official_KB/01_Company_Info/date-new.md",
            "# Date version\n\n"
            "**Original URL:** https://www.lightinthebox.com/company/date-version\n"
            "**Effective Date:** 2026-01-01\n\n"
            "Our company serves customers with the newer version.\n",
        )

        build_corpus(self.source, self.output, brand="lightinthebox", site="lightinthebox-main")

        versions = [
            record for record in self.jsonl(self.output, "manifest.jsonl")
            if record["canonical_document_key"]
            == "url:https://www.lightinthebox.com/company/date-version"
        ]
        active = next(record for record in versions if record["lifecycle_status"] == "active")
        self.assertEqual(newer.relative_to(self.source).as_posix(), active["source_file_path"])
        self.assertEqual(
            older.relative_to(self.source).as_posix(),
            next(record for record in versions if record["lifecycle_status"] == "superseded")[
                "source_file_path"
            ],
        )

    def test_natural_version_order_selects_v10_after_v2(self):
        self.make_governed_fixture()
        version_two = self.write(
            "LightInTheBox_PT_Official_KB/01_Company_Info/version-2.md",
            "# Natural version\n\n"
            "**Original URL:** https://www.lightinthebox.com/company/natural-version\n"
            "**Version:** v2\n\n"
            "Our company serves customers with version two.\n",
        )
        version_ten = self.write(
            "LightInTheBox_PT_Official_KB/01_Company_Info/version-10.md",
            "# Natural version\n\n"
            "**Original URL:** https://www.lightinthebox.com/company/natural-version\n"
            "**Version:** v10\n\n"
            "Our company serves customers with version ten.\n",
        )

        build_corpus(self.source, self.output, brand="lightinthebox", site="lightinthebox-main")

        versions = [
            record for record in self.jsonl(self.output, "manifest.jsonl")
            if record["canonical_document_key"]
            == "url:https://www.lightinthebox.com/company/natural-version"
        ]
        active = next(record for record in versions if record["lifecycle_status"] == "active")
        self.assertEqual(version_ten.relative_to(self.source).as_posix(), active["source_file_path"])
        self.assertNotEqual(version_two.relative_to(self.source).as_posix(), active["source_file_path"])

    def test_aba_mutation_during_load_fails_even_when_final_source_hash_matches(self):
        fixture = self.make_governed_fixture()
        target = fixture["current_about"]
        original = target.read_bytes()
        mutated = original.replace(b"serves customers", b"mutates customers")
        real_load = corpus_build._load_document
        mutated_once = False

        def load_with_aba(source, path, brand, site, source_kind, *args, **kwargs):
            nonlocal mutated_once
            if path.resolve() == target.resolve() and not mutated_once:
                mutated_once = True
                path.write_bytes(mutated)
                try:
                    return real_load(source, path, brand, site, source_kind, *args, **kwargs)
                finally:
                    path.write_bytes(original)
            return real_load(source, path, brand, site, source_kind, *args, **kwargs)

        with patch.object(corpus_build, "_load_document", side_effect=load_with_aba):
            with self.assertRaises(BuildError):
                build_corpus(
                    self.source,
                    self.output,
                    brand="lightinthebox",
                    site="lightinthebox-main",
                )

        self.assertTrue(mutated_once)
        self.assertEqual(original, target.read_bytes())
        self.assertFalse(self.output.exists())

    def test_nested_excluded_components_are_pruned_at_every_depth(self):
        self.make_governed_fixture()
        nested_exclusions = (
            "LightInTheBox_PT_Official_KB/01_Company_Info/archive/Keyword_Library/leak.md",
            "LightInTheBox_PT_Official_KB/02_Customer_Service/nested/knowledge_cleanup_work/leak.md",
            "LightInTheBox_PT_Official_KB/03_Company_Policies/nested/.DS_Store/leak.md",
            "LightInTheBox_PT_Official_KB/04_Product_Categories/nested/output/leak.md",
            "officialknowege/LITB_Reports/nested/work/leak.md",
        )
        for relative_path in nested_exclusions:
            self.write(
                relative_path,
                "# Leaked document\n\nThe company serves customers and products.\n",
            )

        summary = build_corpus(
            self.source,
            self.output,
            brand="lightinthebox",
            site="lightinthebox-main",
        )

        audited_paths = {
            record["source_file_path"]
            for name in ("manifest.jsonl", "duplicate_audit.jsonl", "exclusion_audit.jsonl")
            for record in self.jsonl(self.output, name)
        }
        self.assertEqual(7, summary["counts"]["canonical_source_files"])
        self.assertTrue(set(nested_exclusions).isdisjoint(audited_paths))

    def test_missing_or_empty_required_source_structure_fails_closed(self):
        with self.subTest(case="all roots missing"):
            self.source.mkdir()
            with self.assertRaises(BuildError):
                build_corpus(
                    self.source,
                    self.output,
                    brand="lightinthebox",
                    site="lightinthebox-main",
                )

        self.source.mkdir(exist_ok=True)
        self.make_source_structure()
        with self.subTest(case="empty canonical corpus"):
            with self.assertRaises(BuildError):
                build_corpus(
                    self.source,
                    self.output,
                    brand="lightinthebox",
                    site="lightinthebox-main",
                )

        (self.source / corpus_build.REPORT_ROOT).rmdir()
        self.write(
            "LightInTheBox_PT_Official_KB/01_Company_Info/about.md",
            "# About\n\nOur company serves customers and products.\n",
        )
        with self.subTest(case="one governed root missing"):
            with self.assertRaises(BuildError):
                build_corpus(
                    self.source,
                    self.output,
                    brand="lightinthebox",
                    site="lightinthebox-main",
                )

    def test_front_matter_scalars_round_trip_through_json_compatible_values(self):
        self.make_governed_fixture()
        edge = self.write(
            "LightInTheBox_PT_Official_KB/01_Company_Info/quoted-边缘.md",
            "# Edge: \"quoted\" [yes, no, null] & symbols\n\n"
            "**Original URL:** https://www.lightinthebox.com/company/edge?note=a:b&quoted=%22x%22\n\n"
            "Our company serves customers and the products.\n",
        )

        build_corpus(self.source, self.output, brand="lightinthebox", site="lightinthebox-main")

        record = next(
            item for item in self.jsonl(self.output, "manifest.jsonl")
            if item["source_file_path"] == edge.relative_to(self.source).as_posix()
        )
        cleaned = (self.output / record["cleaned_file_path"]).read_text(encoding="utf-8")
        front_matter = cleaned.split("---\n", 2)[1].strip().splitlines()
        parsed = {
            key: json.loads(value)
            for key, value in (line.split(": ", 1) for line in front_matter)
        }

        self.assertEqual('Edge: "quoted" [yes, no, null] & symbols', parsed["title"])
        self.assertEqual(record["source_url"], parsed["source_url"])
        for key, value in parsed.items():
            self.assertEqual(record[key], value)


if __name__ == "__main__":
    unittest.main()
