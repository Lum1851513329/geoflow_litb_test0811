import hashlib
import json
from pathlib import Path
import tempfile
import unittest

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

    def make_governed_fixture(self):
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


if __name__ == "__main__":
    unittest.main()
