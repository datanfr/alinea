<p align="center">
  <img src="images/logo.png" alt="Alinéa" width="420">
</p>

# DEFI.md

### Nom du défi
Alinéa — Reconstituer l'intention du législateur à partir des débats parlementaires

### Description courte
Alinéa est le microscope sémantique qui relie chaque phrase d'une loi à la seconde
exacte du débat parlementaire qui lui a donné son sens. On transforme les données brutes
(texte adopté, amendements, comptes rendus) en une lecture « Rayon X » de la loi : pour
chaque paragraphe, *pourquoi* il existe, *via quel amendement*, et *à la suite de quel
rapport de force*.

### Démo en ligne
https://alinea.remikel.fr/

### Porteur
Équipe Alinéa

### Description longue

**Le problème.** Une loi publiée au Journal officiel est un texte figé : on lit *ce qui*
a été voté, jamais *pourquoi*. L'intention du législateur — les arbitrages, les
concessions, les consensus de séance — reste enfouie dans des milliers de pages
d'amendements et de comptes rendus, illisibles pour le citoyen.

**L'idée.** Alinéa reconstitue cette intention en reliant chaque modification du texte au
débat qui l'a produite. On combine récupération/parsing des données législatives, analyse
sémantique par IA et synthèse des positions parlementaires (forces en présence,
concessions, consensus). On part directement des comptes rendus écrits (CRI) : pas de
transcription vidéo, elle existe déjà sous forme de texte.

**Le périmètre.** L'analyse fine (Rayon X, IA) porte sur les modifications apportées par
l'**Assemblée nationale** (commission et séance). Les **débats du Sénat** sont désormais
couverts eux aussi : les comptes rendus intégraux de la séance publique sénatoriale sont
importés (texte des interventions rattaché à chaque loi via le dossier Dosleg), et chaque
loi affiche ses séances au Sénat ainsi que les amendements sénatoriaux (base Ameli) les
plus discutés.

**Les sources de débats couvertes.**

| Source | Couverture | Voie d'accès |
|---|---|---|
| AN — séance publique | ✅ texte intégral (CRI) | open data `syceronbrut` (`app:import:comptes-rendus`) |
| AN — commissions | ✅ texte intégral | HTML assemblee-nationale.fr (`app:import:comptes-rendus-commissions`) |
| Sénat — séance publique | ✅ texte intégral (CRI) | base Débats (structure) + HTML senat.fr balisé `cri:` (`app:import:comptes-rendus-senat`) |
| Sénat — amendements | ✅ base Ameli (sort, auteurs, mentions dans le CRI) | open data Sénat |
| Sénat — commissions | 🔗 lien seulement | CR HTML senat.fr, sans rattachement open data exploitable |
| CMP | ⚠️ partiel | pas de CR en open data (ni AN ni Sénat) ; la lecture des conclusions en séance est couverte par les CRI des deux chambres |
| Congrès / Conseil constitutionnel | ✖ hors périmètre | pas des débats législatifs ordinaires |

**La loi choisie : la loi « Narcotrafic ».**
*Loi n° 2025-532 du 13 juin 2025 visant à sortir la France du piège du narcotrafic.*

| Élément | Valeur |
|---|---|
| Législature | 17e (intégralement) |
| Dossier législatif AN | `DLR5L17N50169` |
| Origine | Proposition de loi sénatoriale des sénateurs Étienne Blanc (LR) et Jérôme Durain (PS) |
| Dépôt (Sénat) | 12 juillet 2024 |
| 1re lecture Sénat | Adoptée le 4 février 2025 |
| Examen AN | Mars 2025 (texte issu du Sénat amendé par les députés) |
| Adoption définitive | 29 avril 2025 |
| Promulgation | 13 juin 2025 (partielle conformité, décision du Conseil constitutionnel du 12 juin 2025) |
| Sujets phares | Parquet national anti-criminalité organisée ; régime carcéral renforcé « anti-mafia » (QLCO) ; renforcement du statut des repentis |

Bon exemple : loi **entièrement examinée sous la 17e législature** (donc couverte par
`an-amendements-xvii`), avec un vrai travail d'amendements des députés sur le texte venu
du Sénat — ce qui s'aligne parfaitement avec notre logique « texte de base → retour du
Sénat signalé → modifications AN analysées ».

**Le cœur IA.** Entre chaque version N et N-1 du texte, on compare les changements et on
explique chaque modification à la lumière des amendements adoptés et des interventions du
débat. Pour **chaque amendement**, l'IA produit aussi un **résumé des débats** qui
l'entourent (interventions du CRI) — le **lien vers le débat brut restant toujours
accessible** pour vérification. Sortie JSON structurée par modification :

```json
{
  "intention_principale": "max 15 mots — ex: 'Interdire la publicité fast fashion pour freiner la surconsommation textile'",
  "concept_juridique_defendu": "ex: 'Principe pollueur-payeur'",
  "concession_faite": "ce que le gouvernement/rapporteur a accepté de lâcher",
  "verbatim_cle": "la phrase exacte du débat qui résume ce consensus"
}
```

**Le frontend — « Rayon X de la loi ».** Interface en deux volets :
- **À gauche** : le texte officiel tel que publié au Journal officiel.
- **À droite** : au clic sur un paragraphe, celui-ci se surligne et Alinéa affiche
  l'explication (« Ce paragraphe a été inséré via l'amendement 412 du groupe X pour
  répondre au problème de Y »), le résumé IA du débat associé (avec lien vers le CRI
  brut), et la **jauge des forces en présence** (Gouvernement : Favorable · Commission :
  Défavorable · Rebond de séance : adopté à quelques voix près).

**Stack.** Back-end et parsing en PHP / Symfony, front soigné pour la démo, IA via API
(Claude / Mistral), données Open Data Assemblée nationale + Datan.

### Image principale
![Image principale](images/logo.png)

### Contributeurs
- Rémi Mikel

### Ressources utilisées
Cochez les ressources utilisées en remplaçant `[ ]` par `[x]`.

- [ ] `openfisca-france-parameters` — Base de données de paramètres ✺ OpenFisca
- [x] `an-dossiers-legislatifs` — Dossiers législatifs de l'Assemblée nationale (législature courante) ✺ Assemblée nationale
- [x] `an-amendements-xvii` — Amendements déposés à l'Assemblée nationale (législature actuelle) ✺ Assemblée nationale
- [x] `an-comptes-rendus` — Comptes rendus de la séance publique à l'Assemblée nationale (législature actuelle) ✺ Assemblée nationale
- [x] `an-votes-xvii` — Votes des députés (législature actuelle) ✺ Assemblée nationale
- [x] `an-deputes-en-exercice` — Députés en exercice ✺ Assemblée nationale
- [ ] `an-deputes-historique` — Historique des députés ✺ Assemblée nationale
- [x] `an-deputes-senateurs-ministres-par-legislature` — Députés, sénateurs et ministres d'une législature ✺ Assemblée nationale
- [ ] `an-agenda-reunions` — Agenda des réunions à l'Assemblée nationale (législature courante) ✺ Assemblée nationale
- [ ] `an-questions-gouvernement` — Questions de l'Assemblée nationale au Gouvernement ✺ Assemblée nationale
- [ ] `an-questions-gouvernement-ecrites` — Questions écrites de l'Assemblée nationale au Gouvernement ✺ Assemblée nationale
- [ ] `an-questions-gouvernement-orales` — Questions orales de l'Assemblée nationale au Gouvernement ✺ Assemblée nationale
- [ ] `premier-ministre-legi` — Codes, lois et règlements consolidés ✺ Premier ministre
- [ ] `premier-ministre-dole` — Dossiers législatifs Légifrance ✺ Premier ministre
- [x] `premier-ministre-jorf` — Édition ''Lois et décrets'' du Journal officiel ✺ Premier ministre
- [ ] `senat-dispositifs-textes` — Dispositifs des textes déposés ou adoptés au Sénat ✺ Sénat
- [x] `senat-dossiers-legislatifs` — Dossiers législatifs du Sénat ✺ Sénat
- [x] `senat-amendements` — Amendements déposés au Sénat ✺ Sénat
- [ ] `senat-senateurs` — Sénateurs ✺ Sénat
- [ ] `senat-questions-gouvernement` — Questions orales et écrites du Sénat au Gouvernement ✺ Sénat
- [x] `senat-comptes-rendus` — Comptes rendus de la séance publique au Sénat ✺ Sénat
- [ ] `an-et-co-database-regroupement-toutes-donnees` — Base de données unifiée Parlement / Législation / Service Public ✺ Assemblée nationale & communauté
- [ ] `an-et-co-serveur-mcp-regroupement-toutes-donnees` — Serveur MCP  - Accès unifié Parlement / Législation / Service Public ✺ Assemblée nationale & communauté
- [ ] `an-et-co-api-regroupement-toutes-donnees` — API - Accès unifié Parlement / Législation / Service Public ✺ Assemblée nationale & communauté
- [ ] `legiwatch-api-parlement` — API Parlement ✺ LegiWatch
- [ ] `legiwatch-database-parlement` — Base de données Parlement ✺ LegiWatch
- [ ] `legiwatch-serveur-mcp-parlement` — Serveur MCP Parlement ✺ LegiWatch

### Galerie

**1. Les lois publiées au Journal officiel.** Point d'entrée : une recherche plein
texte sur les 13 096 lois publiées, pour retrouver instantanément un texte par thème
(narcotrafic, sécurité, environnement…).

![Liste des lois et recherche](images/screenshot_liste.png)

**2. La fiche d'une loi — « Au cœur du débat ».** En tête de chaque loi, Alinéa met en
avant les amendements qui ont suscité les échanges les plus nourris en séance, mesurés à
l'ampleur de leur discussion dans le compte rendu intégral, avant de dérouler le texte
complet (ici : 1 676 amendements sur la loi « Narcotrafic »).

![Fiche d'une loi et amendements les plus débattus](images/screenshot_loi.png)

**3. La lecture « Rayon X ».** Interface deux volets : à gauche le texte officiel, dont
chaque passage issu d'un amendement adopté est surligné ; à droite, au clic sur un
passage, l'amendement qui lui a donné son sens et l'intention reconstituée.

![Vue Rayon X : texte surligné et amendement à l'origine](images/screenshot_intention.png)

**4. La fiche amendement — « Ce que ça change ».** Pour chaque amendement, une analyse IA
structurée (intention, concept juridique, score d'impact, ambiguïté) suivie de l'exposé
sommaire officiel et du résumé du débat en commission — avec lien vers le compte rendu
intégral brut pour vérification.

![Fiche amendement avec analyse IA et débat](images/screenshot_amendement.png)

**5. Les filtres.** Recherche par mot-clé, statut (adoptés / rejetés), score d'impact
estimé par l'IA et catégorie de modification (coordination, rédactionnel, précision,
conséquence, fond…) pour naviguer dans des centaines d'amendements.

![Filtres par mot-clé, statut, impact et catégorie](images/screenshot_filtres.png)

### Documents
