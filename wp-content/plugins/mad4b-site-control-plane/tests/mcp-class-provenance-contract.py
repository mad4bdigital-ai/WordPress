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


profile_070 = profiles["providers"]["mcp_adapter"]["0.7.0"]
runtime_070 = profile_070.get("runtime_classes") or {}
critical_070 = profile_070.get("critical_files") or {}
required_070 = {
    "server": ("WP\\MCP\\Core\\McpServer", "includes/Core/McpServer.php"),
    "component_registry": ("WP\\MCP\\Core\\McpComponentRegistry", "includes/Core/McpComponentRegistry.php"),
    "transport_factory": ("WP\\MCP\\Core\\McpTransportFactory", "includes/Core/McpTransportFactory.php"),
    "http_transport": ("WP\\MCP\\Transport\\HttpTransport", "includes/Transport/HttpTransport.php"),
    "transport_context": ("WP\\MCP\\Transport\\Infrastructure\\McpTransportContext", "includes/Transport/Infrastructure/McpTransportContext.php"),
    "initialize_handler": ("WP\\MCP\\Handlers\\Initialize\\InitializeHandler", "includes/Handlers/Initialize/InitializeHandler.php"),
    "tools_handler": ("WP\\MCP\\Handlers\\Tools\\ToolsHandler", "includes/Handlers/Tools/ToolsHandler.php"),
    "resources_handler": ("WP\\MCP\\Handlers\\Resources\\ResourcesHandler", "includes/Handlers/Resources/ResourcesHandler.php"),
    "prompts_handler": ("WP\\MCP\\Handlers\\Prompts\\PromptsHandler", "includes/Handlers/Prompts/PromptsHandler.php"),
    "system_handler": ("WP\\MCP\\Handlers\\System\\SystemHandler", "includes/Handlers/System/SystemHandler.php"),
    "error_log_handler": ("WP\\MCP\\Infrastructure\\ErrorHandling\\ErrorLogMcpErrorHandler", "includes/Infrastructure/ErrorHandling/ErrorLogMcpErrorHandler.php"),
    "null_error_handler": ("WP\\MCP\\Infrastructure\\ErrorHandling\\NullMcpErrorHandler", "includes/Infrastructure/ErrorHandling/NullMcpErrorHandler.php"),
    "null_observability_handler": ("WP\\MCP\\Infrastructure\\Observability\\NullMcpObservabilityHandler", "includes/Infrastructure/Observability/NullMcpObservabilityHandler.php"),
}
for alias, (class_name, path) in required_070.items():
    spec = runtime_070.get(alias) or {}
    if spec.get("class") != class_name or spec.get("file") != path:
        raise SystemExit(f"MCP 0.7 construction provenance mapping missing or wrong for {alias}: {spec}")
    value = critical_070.get(path)
    if not isinstance(value, str) or len(value) != 64 or any(ch not in "0123456789abcdef" for ch in value.lower()):
        raise SystemExit(f"MCP 0.7 construction class lacks certified SHA-256: {path}")

for marker in ("runtime_classes", "critical_class_set_pinned", "critical_class_pin_count"):
    if marker not in mu_bootstrap:
        raise SystemExit(f"MU bootstrap does not consume expanded MCP runtime provenance: {marker}")

for marker in (
    "MAD4B_SCP_MCP_Class_Provenance::BLOCKER",
    "runtime_class_provenance",
    "validator_reason",
    "official_schema_validation",
):
    if marker not in catalog:
        raise SystemExit(f"Catalog diagnostics missing class/validator invariant: {marker}")

print(f"mad4b.mcp-class-provenance.v1: PASS ({len(required)} legacy + {len(required_070)} MCP 0.7 construction classes)")
