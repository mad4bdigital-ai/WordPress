from pathlib import Path
root=Path(__file__).resolve().parents[1]
impl=(root/"includes/class-mad4b-scp-projection-hotset-recommender.php").read_text(encoding="utf-8")
projection=(root/"includes/class-mad4b-scp-chatgpt-tool-projection.php").read_text(encoding="utf-8")
abilities=(root/"includes/class-mad4b-scp-abilities.php").read_text(encoding="utf-8")
for marker in ["MAX_QUOTA = 12","MAX_CANDIDATES = 1000","projection_eligible","'read' !==","'breakglass'","auto_apply","privileged_candidates_excluded","authority_effect' => 'none'"]:
    if marker not in impl: raise SystemExit("FAIL recommender safety marker "+marker)
for forbidden in ["::apply(", "update_option(", "add_option(", "delete_option(", "wp_register_ability("]:
    if forbidden in impl: raise SystemExit("FAIL recommender owns mutation/registration path "+forbidden)
if "record_usage( $name, 'direct_projection' )" not in projection:
    raise SystemExit("FAIL direct projection admitted-demand telemetry missing")
if "record_usage( $ability_name, 'fixed_dispatch' )" not in abilities:
    raise SystemExit("FAIL successful fixed dispatch telemetry missing")
for marker in ["include_recommendations","hotset_recommendation_available","hotset_recommendations"]:
    if marker not in projection: raise SystemExit("FAIL projection status recommendation surface "+marker)
print("mad4b.projection-hotset-recommender.contract.v1: PASS")
