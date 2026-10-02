#!/usr/bin/env python3
"""Publica Bet BD1 na raiz de lgmartinez.com por FTPS passivo."""

import argparse
import importlib.util
from pathlib import Path
import ssl
from ftplib import FTP_TLS, error_perm


HERE = Path(__file__).resolve().parent
BUILD = HERE / "build"


def config() -> dict[str, str]:
    result = {}
    for line in (HERE / ".env").read_text(encoding="utf-8").splitlines():
        if line and not line.startswith("#"):
            key, value = line.split("=", 1)
            result[key] = value
    return result


def ensure_dir(ftp: FTP_TLS, name: str) -> None:
    ftp.cwd("/")
    try:
        ftp.cwd(name)
    except error_perm:
        ftp.mkd(name)
        ftp.cwd(name)


def upload(ftp: FTP_TLS, folder: str, files: list[str]) -> None:
    ensure_dir(ftp, folder)
    for name in files:
        with (BUILD / ("private" if folder == "bet_bd1_private" else "public") / name).open("rb") as source:
            ftp.storbinary("STOR " + name, source)
        print("Enviado:", folder + "/" + name)


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--code-only", action="store_true", help="Preserva configuração e questões privadas do servidor")
    args = parser.parse_args()
    cfg = config()
    spec = importlib.util.spec_from_file_location("hostgator_build", HERE / "build.py")
    builder = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(builder)
    builder.main()
    if args.dry_run:
        if not args.code_only:
            print("Destino privado: bet_bd1_private/{config.php,question-bank.json}")
        print("Destino público: lgmartinez.com/{api.php,index.php}")
        return
    context = ssl.create_default_context()
    context.check_hostname = False  # IP informado pelo titular; SAN da HostGator conferido antes do login.
    with FTP_TLS(context=context, timeout=30) as ftp:
        ftp.connect(cfg["HOSTGATOR_FTP_HOST"], 21)
        ftp.auth()
        if ("DNS", "*.hostgator.com.br") not in ftp.sock.getpeercert().get("subjectAltName", ()):
            raise ssl.SSLCertVerificationError("certificado inesperado; envio cancelado")
        ftp.login(cfg["HOSTGATOR_FTP_USER"], cfg["HOSTGATOR_FTP_PASSWORD"])
        ftp.prot_p()
        ftp.set_pasv(True)
        if not args.code_only:
            upload(ftp, "bet_bd1_private", ["question-bank.json", "config.php"])
        upload(ftp, "lgmartinez.com", ["api.php", "index.php"])
    print("Publicado em https://lgmartinez.com/")


if __name__ == "__main__":
    main()
