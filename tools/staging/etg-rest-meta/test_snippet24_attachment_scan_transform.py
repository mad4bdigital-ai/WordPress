import hashlib
import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).parent))
from snippet24_attachment_scan_transform import transform, MARKER, PUBLIC_GUARD, EXPECTED_ORIGINAL_SHA256

HEADER = " * Version: 1.2.0\n"
SOURCE = (HEADER + MARKER + PUBLIC_GUARD + "\n\t\t$keys = $wpdb->get_col( 'query' );\n\t}\n"
          "\t// Register term meta.\n\tforeach ( $wp_taxonomies as $taxonomy => $obj ) {}\n").encode()


class Snippet24TransformContract(unittest.TestCase):
    def test_source_pinned_by_sha(self):
        self.assertEqual(len(EXPECTED_ORIGINAL_SHA256), 64)
        with self.assertRaisesRegex(ValueError, "SOURCE_SHA_MISMATCH"):
            transform(SOURCE)

    def test_skip_occurs_before_sql(self):
        new = transform(SOURCE, enforce_source_sha=False).decode()
        self.assertLess(new.index("'attachment' === $post_type"), new.index("$wpdb->get_col"))
        self.assertIn("Version: 1.2.1", new)
        self.assertNotIn("<?php", new)

    def test_term_registration_unchanged(self):
        updated = transform(SOURCE, enforce_source_sha=False).decode()
        term = "\t// Register term meta.\n\tforeach ( $wp_taxonomies as $taxonomy => $obj ) {}"
        self.assertIn(term, updated)

    def test_missing_marker_fails_closed(self):
        with self.assertRaisesRegex(ValueError, "MARKER_NOT_UNIQUE"):
            transform(HEADER.encode(), enforce_source_sha=False)

    def test_duplicate_marker_fails_closed(self):
        with self.assertRaisesRegex(ValueError, "MARKER_NOT_UNIQUE"):
            transform(SOURCE + MARKER.encode(), enforce_source_sha=False)

    def test_modified_guard_fails_closed(self):
        with self.assertRaisesRegex(ValueError, "PUBLIC_GUARD_CHANGED"):
            transform(SOURCE.replace(PUBLIC_GUARD.encode(), b"\t\tif (true) {}"), enforce_source_sha=False)

    def test_second_application_fails(self):
        once = transform(SOURCE, enforce_source_sha=False)
        with self.assertRaisesRegex(ValueError, "SOURCE_SHA_MISMATCH|ALREADY_PATCHED"):
            transform(once, enforce_source_sha=False)

    def test_ineligible_version_fails_closed(self):
        with self.assertRaisesRegex(ValueError, "VERSION_MISMATCH"):
            transform(SOURCE.replace(b"1.2.0", b"1.3.0"), enforce_source_sha=False)

    def test_exact_only_two_changes(self):
        after = transform(SOURCE, enforce_source_sha=False).decode()
        original = SOURCE.decode()
        self.assertEqual(after.replace("Version: 1.2.1", "Version: 1.2.0").replace(
            "\n\t\t// Only for the exact reviewed ETG Staging snippet: attachment has zero\n"
            "\t\t// currently allowed keys. Keep core /wp/v2/media unchanged.\n"
            "\t\tif ( 'attachment' === $post_type ) {\n"
            "\t\t\tcontinue;\n"
            "\t\t}\n", ""), original)


if __name__ == "__main__":
    unittest.main(verbosity=2)
