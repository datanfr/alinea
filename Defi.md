<p align="center">
  <img src="images/logo.png" alt="Alinéa" width="320">
</p>

# Défi — Projet Alinéa

> Hackathon Assemblée Nationale
> **Reconstituer l'intention du législateur à partir des débats parlementaires.**

## 1. Le défi

Concevoir un outil qui exploite les données des débats parlementaires (texte adopté,
amendements, comptes rendus, acteurs) pour **extraire et structurer l'intention du
législateur**.

L'idée : transformer des contenus bruts (souvent illisibles pour le citoyen) en
informations exploitables pour comprendre **l'esprit d'une loi** — pas seulement *ce
qui* a été voté, mais *pourquoi* et *à la suite de quel débat*.

On combine :
- récupération/parsing des données législatives,
- analyse sémantique par IA,
- synthèse des positions parlementaires (forces en présence, concessions, consensus).

> **Note de cadrage :** on **zappe la transcription vidéo** — les comptes rendus
> écrits (CRI) existent déjà. On part directement du texte.

## 2. Le périmètre : une seule loi

On se concentre sur **une loi très débattue** pour avoir matière à démontrer le concept.

### Loi cible retenue : **Loi Duplomb**

*Proposition de loi visant à lever les contraintes à l'exercice du métier d'agriculteur.*

| Élément | Valeur |
|---|---|
| Législature | 17e |
| Dossier législatif | `DLR5L17N50819` |
| Auteurs (Sénat) | Laurent Duplomb (LR), Franck Menonville (UDI) |
| Amendements déposés | ~3 400 |
| Fait marquant 1 | Motion de rejet préalable adoptée le **26 mai 2025** (274 pour / 121 contre) — « à front renversé », par les soutiens du texte, pour contrer le mur d'amendements |
| Fait marquant 2 | Adoption du texte de la CMP le **8 juillet 2025** |
| Fait marquant 3 | Promulgation le **11 août 2025** |
| Fait marquant 4 | **Censure** par le Conseil constitutionnel le **7 août 2025** de la réintroduction de l'acétamipride (néonicotinoïde), jugée contraire à la Charte de l'environnement |
| Sujet phare | Réintroduction dérogatoire et conditionnelle de l'**acétamipride** |

**Pourquoi cette loi :** récente, fort storytelling (motion de rejet auto-infligée,
pétition record, censure constitutionnelle), sujet concret et clivant — idéal pour
illustrer la mécanique « débat → amendement → texte final ».

> ⚠️ **Point de vigilance data :** loi de 2025, examen écourté par la motion de rejet
> en séance (peu de votes d'amendements en hémicycle côté AN). La matière « N vs N-1 »
> se trouvera surtout en **commission** et au **Sénat**. À vérifier au moment du parsing.

## 3. À parser (sources de données)

Tout vient de l'**Open Data Assemblée Nationale** — <https://data.assemblee-nationale.fr/>
(licence ouverte, formats XML / JSON).

| Donnée | Contenu | Formats |
|---|---|---|
| **Texte adopté** | Le texte final tel que voté / publié | HTML, PDF (notice XML/JSON) |
| **Amendements** | Auteur, contenu, exposé sommaire, **sort (adopté / rejeté / retiré / tombé)** | XML, JSON, PDF |
| **CRI** (comptes rendus de séance) | Interventions horodatées : orateur (député/ministre), texte du débat, n° de séance | HTML/PDF + notice |
| **Acteurs / Organes** | Référentiel des députés, groupes, ministres | XML/JSON |

Récupération des amendements possible par : archive complète de législature, **liste par
dossier législatif**, ou flux temps réel (latence ~1 min).

Données complémentaires déjà disponibles sur le site **Datan** (votes, acteurs).

## 4. Le cœur IA

Entre chaque version **N** et **N-1** du texte, comparer les changements et **expliquer
chaque modification** à la lumière des amendements adoptés et des interventions du débat.

**Prompt type :**
> « Voici l'Article 4 initial. Voici l'Amendement n°124 déposé par [Député X]. Voici la
> prise de parole du Ministre en réponse. L'amendement a été ADOPTÉ. »

**Sortie attendue (JSON structuré) :**

```json
{
  "intention_principale": "max 15 mots — ex: 'Exclure les TPE de l'obligation fiscale pour éviter les faillites'",
  "concept_juridique_defendu": "ex: 'Principe de proportionnalité'",
  "concession_faite": "ce que le gouvernement a accepté de lâcher",
  "verbatim_cle": "la phrase exacte du débat qui résume ce consensus"
}
```

> Clé Anthropic/Mistral à provisionner ; clé / espace `politic-analysis` à préparer.

## 5. Le frontend

Interface en deux volets :

**À gauche — Le texte officiel**
Le texte de loi tel que publié au Journal Officiel.

**À droite — « Rayon X de la loi »** *(l'innovation)*
Au clic sur un paragraphe / article :
- le paragraphe se **surligne** ;
- affichage de l'explication :
  > « Ce paragraphe a été inséré via l'amendement 412 du groupe [X] pour répondre au
  > problème de [Y]. »
- **jauge des forces en présence** :
  `Gouvernement : Favorable` · `Commission : Défavorable` · `Rebond de séance : Adopté à 4 voix près`

## 6. Stack & visibilité

| Sujet | Décision |
|---|---|
| **Stack** | PHP / **Symfony** (back + parsing), front soigné pour la démo |
| **Données** | Open Data Assemblée Nationale (XML/JSON) + Datan |
| **IA** | API Anthropic/Mistral — clé à provisionner |
| **Nom du projet** | **Alinéa** |

## 7. Liens utiles

- Open Data AN : <https://data.assemblee-nationale.fr/>
- Dossier législatif Duplomb (17e lég.) : <https://www.assemblee-nationale.fr/dyn/17/dossiers/DLR5L17N50819>
- Loi Duplomb (Wikipédia) : <https://fr.wikipedia.org/wiki/Loi_Duplomb>
- Vote AN sur Datan : <https://datan.fr/votes/legislature-17/vote_2957>
- Dossier LCP « Loi Duplomb » : <https://lcp.fr/dossiers/loi-duplomb-391202>
