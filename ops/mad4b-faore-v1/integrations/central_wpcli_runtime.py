"""Read-only selection of the existing enrolled Central WP-CLI runtime for 313.

Native 313 authority, signed jobs, allowlisted target command, independent
readback and receipts stay owned by the original host services.
"""
from __future__ import annotations
from functools import lru_cache
from wpcli_dynamic_actor import enrolled_target


@lru_cache(maxsize=1)
def central_wp() -> list[str]:
    actual=enrolled_target()
    if actual["environment"]!="staging":
        raise RuntimeError("ORIGINAL_313_CENTRAL_RUNTIME_WRONG_ENVIRONMENT")
    return [
        "/usr/bin/docker", "exec", "-u", "www-data",
        actual["container"], "/usr/local/bin/wp",
        "--path=/var/www/html", "--skip-themes"
    ]
