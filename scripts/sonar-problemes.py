"""Remonte en annotations de la CI les problèmes ouverts de SonarCloud (branche main).

L'API publique de SonarCloud suffit pour un dépôt public, sans jeton. Les journaux
de la CI ne sont lisibles qu'avec un compte GitHub ; les annotations, elles, se lisent
par l'API (voir CLAUDE.md). Une annotation regroupe plusieurs problèmes.
"""
import json
import sys
import urllib.parse
import urllib.request

PROJET = "psenequier_Narbonnaise-Revamp"
API = "https://sonarcloud.io/api"


def lire(chemin, **params):
    url = f"{API}/{chemin}?{urllib.parse.urlencode(params)}"
    with urllib.request.urlopen(url, timeout=30) as reponse:
        return json.load(reponse)


def fichier(composant):
    return composant.split(":", 1)[1] if ":" in composant else composant


lignes = []
page, total = 1, 0
while True:
    donnees = lire("issues/search", componentKeys=PROJET, branch="main",
                   resolved="false", ps=500, p=page)
    total = donnees.get("total", 0)
    for i in donnees.get("issues", []):
        lignes.append(f"{i.get('severity')} {i.get('type')} {i.get('rule')} "
                      f"{fichier(i.get('component', ''))}:{i.get('line', '-')} {i.get('message')}")
    if page * 500 >= min(total, 10000):
        break
    page += 1

points = lire("hotspots/search", projectKey=PROJET, branch="main", ps=500)
for h in points.get("hotspots", []):
    lignes.append(f"HOTSPOT {h.get('status')} {h.get('ruleKey')} "
                  f"{fichier(h.get('component', ''))}:{h.get('line', '-')} {h.get('message')}")

print(f"::notice title=SonarCloud (bilan)::{total} problèmes ouverts, "
      f"{len(points.get('hotspots', []))} points sensibles")

# GitHub garde 10 annotations « notice » par étape : on regroupe.
paquets, courant = [], ""
for ligne in sorted(lignes):
    ligne = ligne.replace("%", "%25").replace("\r", " ").replace("\n", " ")
    if len(courant) + len(ligne) > 3500:
        paquets.append(courant)
        courant = ""
    courant += ligne + "%0A"
if courant:
    paquets.append(courant)
for n, paquet in enumerate(paquets[:9], 1):
    print(f"::notice title=SonarCloud {n}/{len(paquets)}::{paquet}")
sys.exit(0)
