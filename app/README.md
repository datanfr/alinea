# Alinéa : application Symfony

Les commandes se lancent depuis ce dossier (`app/`) avec `php bin/console`.
Chacune accepte `--help` pour le détail complet de ses options.

## Données (open data)

### `app:sync:canutes`

Met à jour les schémas `assemblee`, `legifrance` et `senat` depuis l'API
PostgREST des Tricoteuses (db.code4code.eu, dump quotidien).

```bash
php bin/console app:sync:canutes
php bin/console app:sync:canutes --table=amendements   # une seule table
php bin/console app:sync:canutes --depuis=2026-07-01   # borne de rafraîchissement Légifrance
```

### `app:import:comptes-rendus`

Importe les comptes rendus intégraux de séance publique de l'AN (open data
« syceronbrut ») dans `alinea.cr_parole`.

```bash
php bin/console app:import:comptes-rendus                  # législature 17
php bin/console app:import:comptes-rendus --legislature=16
php bin/console app:import:comptes-rendus --skip-download  # réutilise le zip déjà téléchargé
```

### `app:import:comptes-rendus-commissions`

Importe les comptes rendus des réunions de commission (site AN).

```bash
php bin/console app:import:comptes-rendus-commissions
php bin/console app:import:comptes-rendus-commissions --dossier=DLR5L17N50169
php bin/console app:import:comptes-rendus-commissions --reprise   # saute les CR déjà importés
```

### `app:import:comptes-rendus-senat`

Importe les comptes rendus intégraux de séance publique du Sénat (senat.fr),
paroles rattachées à la loi via les marqueurs `cri:pdl`.

```bash
php bin/console app:import:comptes-rendus-senat --loi=2025-532    # les lectures Sénat d'une loi
php bin/console app:import:comptes-rendus-senat --depuis=2024-09-01
php bin/console app:import:comptes-rendus-senat --reprise
```

### `app:import:jurisprudences`

Importe les décisions de justice citant chaque loi (API Judilibre) dans
`alinea.jurisprudence`.

```bash
php bin/console app:import:jurisprudences --loi=2021-1729
php bin/console app:import:jurisprudences --depuis=2020-01-01 --reprise
```

## Analyses IA des amendements

Le périmètre d'un dossier couvre les deux chambres : amendements AN
(`AMANR…`) et, si la loi a un dossier sénatorial, amendements du Sénat
(`SEN…`). Le modèle se règle avec `IA_MODELE` (`claude-*` passe par l'API
Anthropic, tout autre nom par Ollama en local).

### `app:ia:analyser` : un dossier, en local

Pré-génère les analyses des amendements adoptés et rejetés d'un dossier,
directement en base locale.

```bash
php bin/console app:ia:analyser DLR5L17N50169
php bin/console app:ia:analyser DLR5L17N50169 --modele=gemma4:31b --limite=50
php bin/console app:ia:analyser DLR5L17N50169 --modele=claude-haiku-4-5 --regenerer
```

Options : `--modele` (défaut `IA_MODELE`), `--limite` (nombre maximal
d'analyses), `--regenerer` (refait aussi celles déjà en base).

### `app:ia:demandes` : l'agent local qui alimente la prod

Génère les analyses sur cette machine puis les pousse vers la prod via son
API (`/api/ia`, jeton partagé `IA_API_JETON`, prod ciblée par
`ALINEA_DISTANT_URL`). Les analyses sont aussi conservées en base locale,
mais c'est la prod qui fait foi sur ce qui reste à faire (voir « Garde-fous
entre local et prod » plus bas).

Trois modes selon les arguments :

```bash
# 1. Les demandes en attente (bouton « Lancer l'analyse » des visiteurs) :
php bin/console app:ia:demandes

# 2. Des dossiers explicites, sans passer par les demandes :
php bin/console app:ia:demandes DLR5L17N50169 --regenerer

# 3. Le stock complet, petit à petit, par ordre de priorité :
php bin/console app:ia:demandes --toutes --lois=2 --limite=350 --modele=gemma4:31b
```

Options du mode `--toutes` :

- `--toutes` : parcourt toutes les lois promulguées au lieu des demandes,
  classées par priorité : consultations des pages sur 90 jours
  (`alinea.consultation_loi`, servie par `/api/ia/interet`), puis demandes
  des visiteurs, puis date de promulgation. Signaux de priorité injoignables :
  la file se rabat sur la récence seule.
- `--lois=N` : nombre maximal de lois traitées dans la passe (défaut 2).
  Les lois déjà complètes sont sautées : chaque passe reprend où la
  précédente s'était arrêtée.
- `--limite=N` : nombre maximal d'analyses générées par loi et par passe.
  Sans limite, une grosse loi peut représenter des centaines d'analyses
  (plusieurs heures de GPU). Une loi plafonnée reste en tête de file pour
  la passe suivante.

Repères : ~24 s par analyse avec gemma4:26b sur un GPU 24 Go (~55 s avec
gemma4:31b), poussée en prod par lots de 10 au fil de l'eau (une passe
interrompue conserve tout ce qu'elle a produit). Exemple de passe nocturne
(~8 h, environ 1 200 analyses) :

```bash
php bin/console app:ia:demandes --toutes --lois=4 --limite=300 --modele=gemma4:26b
```

### Garde-fous entre local et prod

La prod fait foi sur ce qui est publié : sa base contient des analyses que
la machine locale n'a jamais vues (celles qu'elle générait elle-même quand
`IA_SEUIL_ANALYSE_DIRECTE` était non nul). Deux protections en découlent.

1. **La sélection du travail interroge la prod.** Avant de générer, la
   commande demande à `POST /api/ia/analysees` lesquels des amendements
   visés sont déjà analysés. Si la prod est injoignable ou trop ancienne
   pour connaître ce point d'entrée, la commande s'arrête en erreur (code
   de retour 1) plutôt que de refaire à l'aveugle un travail déjà fait.

2. **La prod refuse les régressions de qualité.** `POST /api/ia/analyses`
   n'écrase jamais une analyse d'un modèle distant (`claude-*`) par celle
   d'un modèle local : ces entrées sont ignorées et listées dans la réponse
   (`ignorees`), ce que la commande signale. Les autres cas passent :
   première analyse, modèle local remplacé par un autre, ou montée vers un
   modèle distant. Pour forcer malgré tout (régénération assumée après un
   changement de prompt), le corps de la requête accepte `"force": true`.

### Flux différé côté prod

La prod ne génère jamais elle-même (`IA_ANALYSE_DIFFEREE=1`,
`IA_SEUIL_ANALYSE_DIRECTE=0`) : chaque demande d'un visiteur dépose une
ligne dans `alinea.demande_analyse` et prévient par email
(`NOTIFICATION_EMAIL`). L'agent local les traite au prochain
`app:ia:demandes`, qui clôture les demandes servies.
