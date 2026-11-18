#!/usr/bin/env python3
"""Convertit l'onglet « Membres » de l'Excel de la légion en seed/membres.json, le fichier qu'importe l'app.

Usage, depuis le dossier guildraid-app :
    python tools/excel_vers_json.py
        lit ../organisation_ludra_legion_LEGACY.xlsx et écrit seed/membres.json
    python tools/excel_vers_json.py chemin/vers/classeur.xlsx [sortie.json]

Il faut Python 3 et openpyxl (pip install openpyxl). Le fichier produit contient les infos des membres :
il est hors git (.gitignore) et ne reste sur le serveur que le temps de l'import (bin/import.php).
Les colonnes sont retrouvées par leur titre (ligne « Pseudo, Classe, Rôle… »), pas par leur position.
"""
import json
import re
import sys
import unicodedata
from pathlib import Path

try:
    import openpyxl
except ImportError:
    sys.exit("Il manque openpyxl : lance  pip install openpyxl  puis recommence.")

ROOT = Path(__file__).resolve().parent.parent
DEFAULT_IN = ROOT.parent / "organisation_ludra_legion_LEGACY.xlsx"
DEFAULT_OUT = ROOT / "seed" / "membres.json"
SHEET = "Membres"

DAYS = ["Lun", "Mar", "Mer", "Jeu", "Ven", "Sam", "Dim"]          # comme DAYS dans src/lists.php
INSCRIPTION = ["Confirmé", "À confirmer", "Indisponible"]         # comme INSCRIPTION dans src/lists.php

# Titre de colonne (sans accents, en minuscules, début du titre) -> clé du JSON
HEADERS = [
    ("pseudo", "pseudo"), ("classe", "classe"), ("role", "role"), ("gs", "gs"), ("cp", "cp"),
    ("connaissance", "connaissance"), ("meilleure etape", "meilleure_etape"), ("objectif", "objectif"),
    ("jours", "jours"), ("creneau", "creneau"), ("helper", "helper"), ("inscription", "inscription"),
    ("clear", "clear_semaine"), ("shot caller", "shot_caller"), ("notes", "notes"), ("verifi", "verifie"),
]
KEYS = ["pseudo", "classe", "role", "gs", "cp", "connaissance", "meilleure_etape", "objectif", "jours", "creneau",
        "helper", "inscription", "clear_semaine", "shot_caller", "notes", "verifie"]

warnings = []


def norm(v) -> str:
    """Texte sans accents, en minuscules, espaces simplifiés."""
    s = unicodedata.normalize("NFKD", str(v)).encode("ascii", "ignore").decode()
    return re.sub(r"\s+", " ", s).strip().lower()


def text(v):
    if v is None:
        return None
    if isinstance(v, float) and v.is_integer():
        v = int(v)
    s = str(v).strip()
    return s or None


def number(v, who, col, allow_k=False):
    """GS « 2 984 » -> 2984 ; CP « 190,5k » -> 190500 (même règle que parse_cp dans src/helpers.php)."""
    if v is None or str(v).strip() == "":
        return None
    if isinstance(v, (int, float)):
        return int(round(v))
    t = str(v).strip().lower()
    for ch in (" ", " ", " ", "'", "’"):
        t = t.replace(ch, "")
    t = t.replace(",", ".")
    mult = 1
    if allow_k and t.endswith("k"):
        mult, t = 1000, t[:-1]
    try:
        return int(round(float(t) * mult))
    except ValueError:
        warnings.append(f"{who} : {col} « {v} » illisible, laissé vide")
        return None


def yes(v) -> int:
    return 1 if norm(v) in ("oui", "yes", "x", "1", "true", "vrai") else 0


def days(v, who):
    """« Lun/Mar/Ven », « Lundi/Mardi », « Everydays », « Tous sauf le jeudi », « week-end »… -> ["Lun", …]."""
    t = norm(v) if v is not None else ""
    if not t:
        return []
    found = set()
    parts = re.split(r"\b(?:sauf|except|hors)\b", t, maxsplit=1)   # « tous sauf le jeudi »
    head, excluded = parts[0], (parts[1] if len(parts) > 1 else "")
    if re.search(r"\b(tous|tout|all|every ?days?|tlj|7/7|toute la semaine)\b", head):
        found = set(DAYS)
    for m in re.finditer(r"\b(lun|mar|mer|jeu|ven|sam|dim)\w*", head):
        found.add(m.group(1).capitalize())
    if re.search(r"week.?end", head):
        found |= {"Sam", "Dim"}
    for m in re.finditer(r"\b(lun|mar|mer|jeu|ven|sam|dim)\w*", excluded):
        found.discard(m.group(1).capitalize())
    if not found:
        warnings.append(f"{who} : jours « {v} » non reconnus, laissés vides")
    return [d for d in DAYS if d in found]


def valid_pseudo(p: str) -> bool:
    """Même règle que validate_pseudo dans src/auth.php : 2 à 20 lettres, chiffres, - ou _."""
    return 2 <= len(p) <= 20 and all(c.isalpha() or c.isdigit() or c in "_-" for c in p)


def main() -> None:
    src = Path(sys.argv[1]) if len(sys.argv) > 1 else DEFAULT_IN
    out = Path(sys.argv[2]) if len(sys.argv) > 2 else DEFAULT_OUT
    if not src.is_file():
        sys.exit(f"Classeur introuvable : {src}")
    wb = openpyxl.load_workbook(src, data_only=True, read_only=True)
    if SHEET not in wb.sheetnames:
        sys.exit(f"Onglet « {SHEET} » introuvable dans {src.name} (onglets : {', '.join(wb.sheetnames)})")
    rows = list(wb[SHEET].iter_rows(values_only=True))

    # Ligne des titres : celle qui contient « Pseudo »
    hdr = next((i for i, r in enumerate(rows[:20]) if any(norm(c) == "pseudo" for c in r if c is not None)), None)
    if hdr is None:
        sys.exit("Ligne de titres introuvable (aucune cellule « Pseudo » dans les 20 premières lignes).")
    cols = {}
    for j, c in enumerate(rows[hdr]):
        n = norm(c) if c is not None else ""
        for prefix, key in HEADERS:
            if n.startswith(prefix) and key not in cols:
                cols[key] = j
                break
    missing = [k for k in KEYS if k not in cols]
    if "pseudo" in missing:
        sys.exit("Colonne « Pseudo » introuvable.")

    members = {}
    for r in rows[hdr + 1:]:
        get = lambda k: r[cols[k]] if k in cols and cols[k] < len(r) else None
        pseudo = text(get("pseudo"))
        if not pseudo:
            continue
        if not valid_pseudo(pseudo):
            warnings.append(f"« {pseudo} » ignoré : le pseudo doit faire 2 à 20 lettres, chiffres, - ou _")
            continue
        insc = text(get("inscription"))
        if insc and insc not in INSCRIPTION:
            warnings.append(f"{pseudo} : inscription « {insc} » inconnue, remplacée par « À confirmer »")
        m = {
            "pseudo": pseudo,
            "classe": text(get("classe")) or "Autre",
            "role": text(get("role")),
            "gs": number(get("gs"), pseudo, "GS"),
            "cp": number(get("cp"), pseudo, "CP", allow_k=True),
            "connaissance": text(get("connaissance")),
            "meilleure_etape": text(get("meilleure_etape")),
            "objectif": text(get("objectif")),
            "jours": days(get("jours"), pseudo),
            "creneau": text(get("creneau")),
            "helper": yes(get("helper")),
            "inscription": insc if insc in INSCRIPTION else "À confirmer",
            "clear_semaine": yes(get("clear_semaine")),
            "shot_caller": yes(get("shot_caller")),
            "notes": text(get("notes")),
            "verifie": 1 if norm(get("verifie") or "").startswith("verifi") else 0,
        }
        key = pseudo.lower()
        if key in members:
            filled = lambda x: sum(1 for k, v in x.items() if v not in (None, [], 0, "Autre", "À confirmer"))
            keep = m if filled(m) >= filled(members[key]) else members[key]
            warnings.append(f"« {pseudo} » apparaît deux fois : la ligne la plus remplie est gardée")
            members[key] = keep
        else:
            members[key] = m

    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(list(members.values()), ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"{len(members)} membres écrits dans {out}")
    if missing:
        print("Colonnes absentes (laissées vides) : " + ", ".join(missing))
    for w in warnings:
        print("  attention : " + w)


if __name__ == "__main__":
    main()
