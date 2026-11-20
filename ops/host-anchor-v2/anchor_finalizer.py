"""Original313 signed Host Anchor evidence verifier, not receipt issuer."""
from __future__ import annotations


def verify_original313_precondition(index, proof, original_job, original312,
                                    independently_read_postcondition):
    import evidence
    checked = evidence.verify_host_v2_signed_operation(
        index, proof, original_job, original312,
        independently_read_postcondition)
    if (checked.get("original313_certified") is not False
        or checked.get("central_receipt_created") is not False
        or checked.get("execution_authorized") is not False):
        raise ValueError("HOST_ANCHOR_RECEIPT_NOT_INDEPENDENT")
    return checked
