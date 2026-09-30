from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
peer=(ROOT/'includes/class-mad4b-scp-mcp-peer-governance.php').read_text(encoding='utf-8')
assert "foreign_transport_unreviewed" in peer
segment=peer[peer.index("$status['foreign_mcp_detected']"):peer.index("$status['blockers'] = array_values", peer.index("$status['foreign_mcp_detected']"))]
assert "$status['write_side_channel_detected'] = true" not in segment
assert "mcp_foreign_transport_unreviewed" in peer
print('mad4b.mcp-peer-semantic-risk.v1: PASS')
