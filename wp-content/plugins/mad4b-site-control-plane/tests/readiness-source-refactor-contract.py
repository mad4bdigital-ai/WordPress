#!/usr/bin/env python3
"""Non-authorizing source/boundary contract for the Readiness extraction."""
from pathlib import Path
import re

ROOT=Path(__file__).resolve().parents[1]
REPO=ROOT.parents[2]
core=ROOT/"includes/class-mad4b-scp-content-readiness-alerts.php"
view=ROOT/"includes/class-mad4b-scp-content-readiness-admin-view.php"
impact=ROOT/"includes/class-mad4b-scp-frontend-impact-priority.php"
adapter=REPO/"wp-content/mu-plugins/mad4b-readiness-tour-origin-evidence.php"
content=core.read_text(encoding="utf-8")
presentation=view.read_text(encoding="utf-8")
explorer=impact.read_text(encoding="utf-8")
binding=adapter.read_text(encoding="utf-8")

assert len(content.splitlines())<=900, "Readiness must be a bounded domain service"
assert "class-mad4b-scp-content-readiness-admin-view.php" in content
assert "MAD4B_SCP_Content_Readiness_Admin_View::render()" in content
assert "MAD4B_SCP_Content_Readiness_Alerts::sort_issues($rows)" in presentation
assert "MAD4B_SCP_Content_Readiness_Alerts::summary($issues)" in presentation
assert "private static function priority_compare" in content
assert "public static function sort_issues" in content

# Tenant/business post types belong to optional company adapters.
business=re.compile(r"""['"](?:/?tours-and-activities/?|tour_type|tour_location|tour_style)['"]""")
assert not business.search(content), "Business model leaked into generic Readiness"
assert not business.search(explorer), "Business model leaked into generic explorer"
assert "mad4b/content_readiness/source_origin_post_type" in content
assert "mad4b/content_readiness/source_backed_hotel_pickup" in content
assert "mad4b/content_readiness/source_origin_post_type" in binding
assert "mad4b/content_readiness/source_backed_hotel_pickup" in binding
assert "post_type_exists('tours-and-activities')" in binding

# Missing provider is deny-by-default, not automatic write authority.
assert "'publication_authorized'=>false" in binding or "'fixed_origin_required'=>false" in binding
assert "if($prior!==false)return $prior;" in binding
assert "hash_equals" in binding
assert "source_meta_sha256" in content
assert "MAD4B_SCP_Policy::can_mutate()" not in binding
assert "wp_insert_post(" not in binding
assert "update_post_meta(" not in binding
assert "update_option(" not in binding
assert "'status'=>'PRIORITY_INTAKE'" not in content
assert "'requires_governed_content_job_admission'=>true" in content

assert "mad4b/frontend_impact/archive_post_types" in explorer
assert "mad4b/frontend_impact/detail_post_types" in explorer
assert "get_post_type_archive_link($type)" in explorer
assert "wpml_permalink" in explorer
assert "current_user_can('manage_options')" in explorer
assert "publication_mutated'=>false" in explorer
assert "content_jobs_mutated'=>false" in explorer

print("MAD4B_READINESS_SOURCE_REFACTOR: PASS (bounded core, delegated admin, optional native adapter, discovery and no write authority)")
