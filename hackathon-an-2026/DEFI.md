<p align="center">
  <img src="images/logo.png" alt="Alinéa" width="320">
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
concessions, consensus).

> Note de cadrage : on **zappe la transcription vidéo** — les comptes rendus écrits (CRI)
> existent déjà. On part directement du texte.

**Le périmètre — une seule loi : la Loi Duplomb.**
*Proposition de loi visant à lever les contraintes à l'exercice du métier d'agriculteur.*

| Élément | Valeur |
|---|---|
| Législature | 17e |
| Dossier législatif | `DLR5L17N50819` |
| Auteurs (Sénat) | Laurent Duplomb (LR), Franck Menonville (UDI) |
| Amendements déposés | ~3 400 |
| Fait marquant | Motion de rejet préalable adoptée le 26 mai 2025 (274 pour / 121 contre), « à front renversé », par les soutiens du texte pour contrer le mur d'amendements |
| Fait marquant | Adoption du texte de la CMP le 8 juillet 2025, promulgation le 11 août 2025 |
| Fait marquant | Censure par le Conseil constitutionnel (7 août 2025) de la réintroduction de l'acétamipride, contraire à la Charte de l'environnement |
| Sujet phare | Réintroduction dérogatoire et conditionnelle de l'acétamipride (néonicotinoïde) |

*Pourquoi cette loi :* récente, fort storytelling, sujet concret et clivant — idéale pour
illustrer la mécanique « débat → amendement → texte final ».
*Point de vigilance :* l'examen en séance à l'AN a été écourté par la motion de rejet ; la
matière « N vs N-1 » la plus riche se trouve donc en **commission** et au **Sénat**.

**Le cœur IA.** Entre chaque version N et N-1 du texte, on compare les changements et on
explique chaque modification à la lumière des amendements adoptés et des interventions du
débat. Sortie JSON structurée par modification :

```json
{
  "intention_principale": "max 15 mots — ex: 'Exclure les TPE de l'obligation pour éviter les faillites'",
  "concept_juridique_defendu": "ex: 'Principe de proportionnalité'",
  "concession_faite": "ce que le gouvernement a accepté de lâcher",
  "verbatim_cle": "la phrase exacte du débat qui résume ce consensus"
}
```

**Le frontend — « Rayon X de la loi ».** Interface en deux volets :
- **À gauche** : le texte officiel tel que publié au Journal officiel.
- **À droite** : au clic sur un paragraphe, celui-ci se surligne et Alinéa affiche
  l'explication (« Ce paragraphe a été inséré via l'amendement 412 du groupe X pour
  répondre au problème de Y ») ainsi que la **jauge des forces en présence**
  (Gouvernement : Favorable · Commission : Défavorable · Rebond de séance : adopté à
  quelques voix près).

**Stack.** Back-end et parsing en PHP / Symfony, front soigné pour la démo, IA via API
(Claude / Mistral), données Open Data Assemblée nationale & Sénat + Datan.

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
- [x] `senat-dispositifs-textes` — Dispositifs des textes déposés ou adoptés au Sénat ✺ Sénat
- [x] `senat-dossiers-legislatifs` — Dossiers législatifs du Sénat ✺ Sénat
- [x] `senat-amendements` — Amendements déposés au Sénat ✺ Sénat
- [x] `senat-senateurs` — Sénateurs ✺ Sénat
- [ ] `senat-questions-gouvernement` — Questions orales et écrites du Sénat au Gouvernement ✺ Sénat
- [x] `senat-comptes-rendus` — Comptes rendus de la séance publique au Sénat ✺ Sénat
- [ ] `an-et-co-database-regroupement-toutes-donnees` — Base de données unifiée Parlement / Législation / Service Public ✺ Assemblée nationale & communauté
- [ ] `an-et-co-serveur-mcp-regroupement-toutes-donnees` — Serveur MCP  - Accès unifié Parlement / Législation / Service Public ✺ Assemblée nationale & communauté
- [ ] `an-et-co-api-regroupement-toutes-donnees` — API - Accès unifié Parlement / Législation / Service Public ✺ Assemblée nationale & communauté
- [ ] `legiwatch-api-parlement` — API Parlement ✺ LegiWatch
- [ ] `legiwatch-database-parlement` — Base de données Parlement ✺ LegiWatch
- [ ] `legiwatch-serveur-mcp-parlement` — Serveur MCP Parlement ✺ LegiWatch

### Galerie

### Documents
