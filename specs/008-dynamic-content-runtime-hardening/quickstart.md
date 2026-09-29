# Quickstart

1. Checkout `feat/008-dynamic-content-runtime-hardening-20260929`.
2. Read `constitution.md`, `spec.md`, and `gate-graph.json`.
3. Implement tasks in dependency order, not task-number order when the gate graph differs.
4. Keep public rc.83 schemas stable unless a versioned additive field is introduced.
5. Add tests before routing existing behavior through a new internal service.
6. Do not enable Production mutation during this feature.
7. Run the Staging canary only after all prerequisite gates are green.
