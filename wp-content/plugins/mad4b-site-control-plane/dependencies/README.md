# Bundled certified dependencies

Release packaging places the exact certified `mcp-adapter.zip` in this directory.
The runtime never downloads or executes remote dependency code automatically. An administrator may explicitly install or repair the bundled archive only after its SHA-256 digest matches `config/certified-providers.json`.

Source checkouts may intentionally omit the binary archive; the release workflow supplies it from the repository-owned certified artifact.
