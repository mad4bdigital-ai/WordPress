#!/usr/bin/env python3
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
config = json.loads((ROOT / "config/certified-providers.json").read_text("utf-8"))
profiles = json.loads((ROOT / "config/certified-provider-profiles.json").read_text("utf-8"))
refresh = (ROOT / "tests/refresh-runtime-integrity-baseline.py").read_text("utf-8")
runtime = (ROOT / "includes/class-mad4b-scp-mcp-class-provenance.php").read_text("utf-8")
catalog = (ROOT / "includes/class-mad4b-scp-mcp-catalog-diagnostics.php").read_text("utf-8")
mu_bootstrap = (ROOT / "bootstrap/mad4b-mcp-adapter-mu-bootstrap.php").read_text("utf-8")

required = {
    "includes/Core/McpAdapter.php",
    "includes/Domain/Tools/McpToolValidator.php",
    "includes/Domain/Tools/RegisterAbilityAsMcpTool.php",
    "includes/Domain/Utils/McpAnnotationMapper.php",
    "includes/Domain/Utils/McpValidator.php",
    "includes/Domain/Utils/SchemaTransformer.php",
    "vendor/wordpress/php-mcp-schema/src/Server/Tools/DTO/Tool.php",
    "vendor/wordpress/php-mcp-schema/src/Server/Tools/DTO/ToolInputSchema.php",
    "vendor/wordpress/php-mcp-schema/src/Server/Tools/DTO/ToolOutputSchema.php",
    "vendor/wordpress/php-mcp-schema/src/Server/Tools/DTO/ToolAnnotations.php",
    "vendor/wordpress/php-mcp-schema/src/Server/Tools/DTO/ToolExecution.php",
}
mcp = config["providers"]["mcp_adapter"]
critical = mcp.get("critical_files") or {}
missing = sorted(required - set(critical))
if missing:
    raise SystemExit(f"MCP class provenance baseline missing critical files: {missing}")
for path in required:
    value = critical[path]
    if not isinstance(value, str) or len(value) != 64 or any(ch not in "0123456789abcdef" for ch in value.lower()):
        raise SystemExit(f"Invalid certified SHA-256 for {path}")

for path in required:
    if path not in refresh:
        raise SystemExit(f"Integrity refresh does not preserve {path}")
    if path not in runtime:
        raise SystemExit(f"Runtime provenance mapping does not cover {path}")

for marker in (
    "active_mcp_adapter_root",
    "runtime_root_relative",
    "plugin_identity_unavailable",
):
    if marker not in runtime:
        raise SystemExit(f"Runtime provenance dynamic-root contract missing: {marker}")
if "trailingslashit( WP_PLUGIN_DIR ) . 'mcp-adapter'" in runtime:
    raise SystemExit("Runtime provenance must not hardcode the MCP plugin directory")

if "elseif ( ! $uses_blob_identity && ! $row['sha256_match'] ) $row['reason'] = 'runtime_class_sha256_mismatch';" not in runtime:
    raise SystemExit("blob-certified success must not emit a SHA mismatch reason")


profile_070 = profiles["providers"]["mcp_adapter"]["0.7.0"]
surface = profile_070.get("runtime_surface") or {}
symbols = profile_070.get("runtime_symbols") or []
surface_tool = (ROOT / "tests/mcp-runtime-surface-profile.py").read_text("utf-8")

if surface.get("contract") != "mad4b.mcp-runtime-surface.v1":
    raise SystemExit("MCP 0.7 runtime surface contract missing")
if surface.get("discovery") != "exact_archive_php_namespace_surface":
    raise SystemExit("MCP 0.7 runtime surface is not archive-discovered")
if surface.get("generator") != "tests/mcp-runtime-surface-profile.py":
    raise SystemExit("MCP 0.7 runtime surface generator identity missing")
if surface.get("fail_closed_on_unprofiled_symbol") is not True:
    raise SystemExit("MCP runtime surface must fail closed on unprofiled symbols")
prefixes = surface.get("include_prefixes") or []
if not prefixes or "includes/Core/" not in prefixes or "includes/Transport/" not in prefixes:
    raise SystemExit("MCP runtime surface prefixes do not cover Core + Transport")
if (surface.get("namespace_prefixes") or []) != ["WP\\MCP\\"]:
    raise SystemExit("MCP runtime surface namespace authority drift")
if not isinstance(symbols, list) or len(symbols) < 20:
    raise SystemExit("MCP runtime surface symbol inventory is unexpectedly small")
if surface.get("symbol_count") != len(symbols):
    raise SystemExit("MCP runtime surface symbol_count drift")
files = set()
names = set()
for spec in symbols:
    if not isinstance(spec, dict):
        raise SystemExit("MCP runtime symbol entry is not an object")
    symbol = str(spec.get("symbol") or "")
    kind = str(spec.get("kind") or "")
    file = str(spec.get("file") or "")
    blob = str(spec.get("git_blob_sha1") or "").lower()
    if not symbol.startswith("WP\\MCP\\"):
        raise SystemExit(f"runtime symbol escaped namespace authority: {symbol}")
    if kind not in {"class", "interface", "trait"}:
        raise SystemExit(f"runtime symbol kind unsupported: {symbol}:{kind}")
    if not any(file.startswith(prefix) for prefix in prefixes):
        raise SystemExit(f"runtime symbol escaped file surface: {symbol}:{file}")
    if len(blob) != 40 or any(ch not in "0123456789abcdef" for ch in blob):
        raise SystemExit(f"runtime symbol Git blob identity invalid: {symbol}")
    if symbol in names:
        raise SystemExit(f"duplicate runtime symbol: {symbol}")
    names.add(symbol)
    files.add(file)

if surface.get("file_count") != len(files):
    raise SystemExit("MCP runtime surface file_count drift")
tree = str(surface.get("tree_sha256") or "").lower()
if len(tree) != 64 or any(ch not in "0123456789abcdef" for ch in tree):
    raise SystemExit("MCP runtime surface tree digest invalid")

# Only the integration boundary remains explicit. Transitive symbols are generated.
for required_symbol in {
    "WP\\MCP\\Core\\McpAdapter",
    "WP\\MCP\\Core\\McpServer",
    "WP\\MCP\\Transport\\HttpTransport",
    "WP\\MCP\\Infrastructure\\ErrorHandling\\ErrorLogMcpErrorHandler",
    "WP\\MCP\\Infrastructure\\Observability\\NullMcpObservabilityHandler",
}:
    if required_symbol not in names:
        raise SystemExit(f"MCP runtime surface missing integration root: {required_symbol}")

for marker in (
    "runtime_symbols",
    "runtime_symbol_exists",
    "git_blob_sha1",
    "runtime_symbol_blob_mismatch",
):
    if marker not in runtime:
        raise SystemExit(f"Runtime provenance does not consume generated symbol surface: {marker}")

for marker in (
    "runtime_symbols",
    "git_blob_sha1",
    "critical_class_set_pinned",
    "critical_class_pin_count",
):
    if marker not in mu_bootstrap:
        raise SystemExit(f"MU bootstrap does not consume generated MCP runtime surface: {marker}")

for marker in (
    "git_blob_sha1",
    "runtime_symbols",
    "--write",
    "exact_archive_php_namespace_surface",
):
    if marker not in surface_tool:
        raise SystemExit(f"MCP runtime surface generator contract missing: {marker}")

for marker in (
    "MAD4B_SCP_MCP_Class_Provenance::BLOCKER",
    "runtime_class_provenance",
    "validator_reason",
    "official_schema_validation",
):
    if marker not in catalog:
        raise SystemExit(f"Catalog diagnostics missing class/validator invariant: {marker}")

print(f"mad4b.mcp-class-provenance.v1: PASS ({len(required)} legacy + {len(symbols)} generated MCP 0.7 runtime symbols)")
