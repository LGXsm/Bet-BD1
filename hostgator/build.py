#!/usr/bin/env python3
"""Prepara Bet BD1 para PHP na HostGator sem incluir segredos no site público."""

import hashlib
import os
from pathlib import Path
import shutil


ROOT = Path(__file__).resolve().parents[1]
HERE = Path(__file__).resolve().parent
BUILD = HERE / "build"


def config() -> dict[str, str]:
    result = {}
    for line in (HERE / ".env").read_text(encoding="utf-8").splitlines():
        if line and not line.startswith("#"):
            key, value = line.split("=", 1)
            result[key] = os.getenv(key, value)
    return result


def main() -> None:
    cfg = config()
    code = cfg["BET_BD1_OWNER_CODE"]
    token = cfg["BET_BD1_COOKIE_TOKEN"]
    if len(code) < 16 or len(token) < 32:
        raise ValueError("Código do criador ou token muito curto")
    public = BUILD / "public"
    private = BUILD / "private"
    public.mkdir(parents=True, exist_ok=True)
    private.mkdir(parents=True, exist_ok=True)
    html = (ROOT / "src" / "index.html").read_text(encoding="utf-8")
    old = "fetch('/api/'+path"
    if html.count(old) != 1:
        raise ValueError("Não foi possível localizar a chamada da API no site")
    html = html.replace(old, "fetch('/api.php?route='+encodeURIComponent(path)")
    html = html.replace("setInterval(refresh,750)", "setInterval(refresh,1000)")
    # A página precisa atualizar junto com as fases novas do servidor.
    (public / "index.php").write_text(
        "<?php header('Cache-Control: no-store, no-cache, must-revalidate'); ?>\n" + html,
        encoding="utf-8",
    )
    shutil.copyfile(HERE / "api.php", public / "api.php")
    shutil.copyfile(ROOT / "src" / "question-bank.json", private / "question-bank.json")
    owner_hash = hashlib.sha256(("bet-bd1:" + code).encode("utf-8")).hexdigest()
    (private / "config.php").write_text(
        "<?php\nreturn ['owner_hash' => '" + owner_hash +
        "', 'cookie_token' => '" + token + "'];\n", encoding="utf-8"
    )
    print("Bet BD1 preparado para lgmartinez.com (segredos fora da pasta pública).")


if __name__ == "__main__":
    main()
