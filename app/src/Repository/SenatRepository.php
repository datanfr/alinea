<?php

namespace App\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Volet sénatorial d'une loi : dossier Dosleg, séances publiques importées
 * dans alinea (chambre = 'senat', paroles taguées du loicod via les marqueurs
 * cri:pdl de senat.fr) et amendements Ameli les plus débattus.
 *
 * Le pont avec la loi affichée est le numéro Légifrance (« 2025-532 ») :
 * senat.dosleg_loi.numero le porte tel quel, puis signet (« ppl23-735 »)
 * relie Dosleg à Ameli (doslegsignet des textes).
 */
class SenatRepository
{
    /**
     * Ameli mêle deux jeux de codes de sort (lettres pour les campagnes
     * récentes, chiffres pour les anciennes ; ameli_sor les liste tous) :
     * on les traite ensemble. A/1 = Adopté, B = vote unique, 2 = avec
     * modification ; J/3 = Rejeté, K = vote unique.
     */
    private const SORTS_ADOPTES = ['A', 'B', '1', '2'];
    private const SORTS_REJETES = ['J', 'K', '3'];

    /**
     * Colonnes communes aux requêtes d'amendements : identification, sort,
     * subdivision visée, textes (dispositif/objet) et auteur. L'auteur vit
     * soit dans ameli_amdsen (premier signataire, rng = 1 ; table synchronisée
     * pour les dépôts récents seulement), soit via nomentid dans ameli_ent :
     * typ E = sénateur, C = commission, G = groupe politique, B = Gouvernement
     * (entité unique sans libellé).
     */
    private const SELECT_AMENDEMENTS = <<<'SQL'
        SELECT 'SEN' || a.id AS uid,
               trim(a.num) || CASE WHEN coalesce(a.rev, 0) > 0 THEN ' rect.' ELSE '' END AS numero,
               trim(a.num) AS num_brut,
               trim(coalesce(a.sorid, '')) AS sorid,
               sor.lib AS sort_lib,
               irr.lib AS irr_lib,
               left(a.datdep::text, 10) AS date_depot,
               nullif(trim(sub.lib), '') AS division,
               a.dis AS dispositif,
               a.obj AS expose_sommaire,
               nullif(trim(a.libgrp), '') AS groupe,
               ses.ann AS annee,
               trim(t.num) AS texte_num,
               t.id AS texte_ref,
               sen.qua AS sig_qua, sen.prenomuse AS sig_prenom, sen.nomuse AS sig_nom,
               e.typ AS ent_typ,
               com.lil AS ent_commission,
               grp.libcou AS ent_groupe,
               esen.prenomuse AS ent_prenom, esen.nomuse AS ent_nom
        FROM senat.ameli_amd a
        JOIN senat.ameli_txt_ameli t ON t.id = a.txtid
        JOIN senat.ameli_ses ses ON ses.id = t.sesinsid
        LEFT JOIN senat.ameli_sor sor ON sor.id = a.sorid
        LEFT JOIN senat.ameli_irr irr ON irr.id = a.irrid
        LEFT JOIN senat.ameli_sub sub ON sub.id = a.subid
        LEFT JOIN senat.ameli_amdsen sen ON sen.amdid = a.id AND sen.rng = 1
        LEFT JOIN senat.ameli_ent e ON e.id = a.nomentid
        LEFT JOIN senat.ameli_com_ameli com ON com.entid = e.id
        LEFT JOIN senat.ameli_grppol_ameli grp ON grp.entid = e.id
        LEFT JOIN senat.ameli_sen_ameli esen ON esen.entid = e.id
        WHERE trim(t.doslegsignet) = :signet
        SQL;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Dossier législatif du Sénat pour une loi promulguée.
     *
     * @return array{loicod: string, signet: string, titre: ?string, url: string}|null
     */
    public function findDossierPourLoi(?string $numLoi): ?array
    {
        if ($numLoi === null || $numLoi === '') {
            return null;
        }

        $dossier = $this->connection->fetchAssociative(
            'SELECT trim(loicod) AS loicod, trim(signet) AS signet, loiint AS titre
             FROM senat.dosleg_loi
             WHERE trim(numero) = ? AND signet IS NOT NULL
             LIMIT 1',
            [$numLoi]
        );

        if ($dossier === false) {
            return null;
        }

        $dossier['url'] = sprintf('https://www.senat.fr/dossier-legislatif/%s.html', $dossier['signet']);

        return $dossier;
    }

    /**
     * Séances publiques du Sénat importées où la loi a été discutée, avec
     * l'ampleur de la discussion (paroles taguées du loicod).
     *
     * @return list<array<string, mixed>>
     */
    public function findSeancesPourLoi(string $loicod): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT c.uid, (c.date_seance AT TIME ZONE 'Europe/Paris')::date::text AS date_seance, c.url_page,
                    count(*) AS nb_paroles,
                    count(DISTINCT p.orateur_nom) AS nb_orateurs
             FROM alinea.compte_rendu c
             JOIN alinea.cr_parole p ON p.compte_rendu_uid = c.uid AND p.loi_ref = ?
             WHERE c.chambre = 'senat'
             GROUP BY c.uid, c.date_seance, c.url_page
             ORDER BY c.date_seance",
            [$loicod]
        );
    }

    /**
     * Nombre d'amendements déposés au Sénat sur les textes du dossier
     * (séance et commission confondues).
     */
    public function countAmendements(string $signet): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT count(*)
             FROM senat.ameli_amd a
             JOIN senat.ameli_txt_ameli t ON t.id = a.txtid
             WHERE trim(t.doslegsignet) = ?',
            [$signet]
        );
    }

    /**
     * Amendements du dossier adoptés ou rejetés, avec dispositif et objet :
     * le pendant sénatorial de AmendementRepository::findParSortPourDossier.
     * Les adoptés nourrissent le Rayon X (leur dispositif cite entre
     * guillemets le texte inséré, même convention qu'à l'AN), l'ensemble
     * alimente les cartes de la page de la loi.
     *
     * @return list<array<string, mixed>>
     */
    public function findJugesPourDossier(string $signet): array
    {
        $rows = $this->connection->fetchAllAssociative(
            self::SELECT_AMENDEMENTS . <<<'SQL'

              AND trim(coalesce(a.sorid, '')) IN (:sorts)
            ORDER BY t.datdep, sub.pos NULLS LAST, a.id
            SQL,
            ['signet' => $signet, 'sorts' => [...self::SORTS_ADOPTES, ...self::SORTS_REJETES]],
            ['sorts' => ArrayParameterType::STRING]
        );

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * Tous les amendements du dossier, groupés par phase d'examen (chaque
     * texte Ameli est une étape : commission ou séance d'une lecture), dans
     * l'ordre des subdivisions visées ; le pendant sénatorial de
     * AmendementRepository::findPourDossier.
     *
     * @return list<array{label: string, texte_num: string, chambre: string, amendements: list<array<string, mixed>>}>
     */
    public function findPourDossier(string $signet): array
    {
        $rows = $this->connection->fetchAllAssociative(
            self::SELECT_AMENDEMENTS . "\nORDER BY t.datdep, sub.pos NULLS LAST, a.id",
            ['signet' => $signet]
        );

        $phases = [];
        foreach ($rows as $row) {
            $a = $this->hydrate($row);
            $phases[$row['texte_ref']]['label'] ??= $a['phase'];
            $phases[$row['texte_ref']]['texte_num'] ??= $row['texte_num'];
            $phases[$row['texte_ref']]['chambre'] ??= 'senat';
            $phases[$row['texte_ref']]['amendements'][] = $a;
        }

        return array_values($phases);
    }

    private function hydrate(array $row): array
    {
        // Le préfixe COM- des numéros distingue les amendements examinés en
        // commission de ceux de la séance publique ; sur senat.fr, leurs
        // pages vivent sous /amendements/commissions/.
        $commission = str_starts_with($row['num_brut'], 'COM-');

        $row['chambre'] = 'senat';
        $row['phase'] = $commission ? 'Sénat (commission)' : 'Sénat (séance publique)';
        $row['sort'] = match (true) {
            \in_array($row['sorid'], self::SORTS_ADOPTES, true) => 'Adopté',
            \in_array($row['sorid'], self::SORTS_REJETES, true) => 'Rejeté',
            default => null,
        };
        $row['statut'] = $this->libelleStatut($row);
        $row['sort_classe'] = $this->classerStatut($row);
        $row['auteur'] = $this->libelleAuteur($row);
        $row['url_senat'] = sprintf(
            'https://www.senat.fr/amendements/%s%d-%d/%s/Amdt_%s.html',
            $commission ? 'commissions/' : '',
            $row['annee'],
            $row['annee'] + 1,
            $row['texte_num'],
            $row['num_brut']
        );

        return $row;
    }

    /**
     * Le sort Ameli n'est renseigné que pour les amendements arrivés en
     * discussion ; à défaut, l'irrecevabilité (ameli_irr : art. 40, cavalier,
     * entonnoir…) tient lieu de statut, sinon l'amendement n'a simplement
     * jamais été appelé.
     */
    private function libelleStatut(array $row): string
    {
        if ($row['sort_lib'] !== null && trim($row['sort_lib']) !== '') {
            return trim($row['sort_lib']);
        }

        if ($row['irr_lib'] !== null) {
            return 'Irrecevable (' . trim($row['irr_lib']) . ')';
        }

        return 'Non discuté';
    }

    private function classerStatut(array $row): string
    {
        return match (true) {
            \in_array($row['sorid'], self::SORTS_ADOPTES, true) => 'adopte',
            \in_array($row['sorid'], self::SORTS_REJETES, true) => 'rejete',
            $row['sorid'] === '' && $row['irr_lib'] !== null => 'irrecevable',
            default => 'autre', // Retiré, Tombé, Non soutenu, Satisfait, non discuté…
        };
    }

    private function libelleAuteur(array $row): string
    {
        // Premier signataire quand la table des signataires couvre le dépôt…
        if ($row['sig_nom'] !== null) {
            return trim(sprintf('%s %s %s', $row['sig_qua'] ?? '', $row['sig_prenom'], self::recaser($row['sig_nom'])));
        }

        // …sinon l'entité auteur : sénateur, commission, groupe ou Gouvernement.
        return match ($row['ent_typ']) {
            'E' => trim(($row['ent_prenom'] ?? '') . ' ' . self::recaser($row['ent_nom'] ?? '')),
            'C' => $row['ent_commission'] !== null ? mb_ucfirst(trim($row['ent_commission'])) : 'Commission',
            'G' => $row['ent_groupe'] !== null ? mb_ucfirst(trim($row['ent_groupe'])) : 'Groupe politique',
            'B' => 'Le Gouvernement',
            default => '—',
        };
    }

    /**
     * Les amendements de séance du Sénat les plus discutés, mesurés sur les
     * CRI importés — le pendant sénatorial de DebatRepository::classerParDebat,
     * même logique de grappes de mentions (« n° 242 », « amendement 242 »),
     * mais ancré par le tag loi_ref au lieu du seanceDiscussionRef.
     *
     * Requête lourde (regex sur les paroles) : à mettre en cache côté appelant.
     *
     * @return list<array{numero: string, sort: string, auteur: ?string, nb_cit: int, span: int, url: string}>
     */
    public function classerParDebat(string $loicod, string $signet, int $limite = 3): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
            WITH amdts AS (
                SELECT a.id,
                       trim(a.num) || CASE WHEN a.rev > 0 THEN ' rect.' ELSE '' END AS numero,
                       substring(a.num FROM '^\d+') AS num,
                       sor.lib AS sort,
                       nullif(trim(sen.prenomuse || ' ' || sen.nomuse), '') AS auteur,
                       ses.ann AS annee,
                       trim(t.num) AS texte_num
                FROM senat.ameli_amd a
                JOIN senat.ameli_txt_ameli t ON t.id = a.txtid
                JOIN senat.ameli_ses ses ON ses.id = t.sesinsid
                JOIN senat.ameli_sor sor ON sor.id = a.sorid
                LEFT JOIN senat.ameli_amdsen sen ON sen.amdid = a.id AND sen.rng = 1
                WHERE trim(t.doslegsignet) = :signet
                  AND a.sorid IN ('A', 'B', 'J', 'K')
                  AND substring(a.num FROM '^\d+') IS NOT NULL
            ),
            mentions AS (
                SELECT am.id, am.numero, am.sort, am.auteur, am.annee, am.texte_num, am.num,
                       p.compte_rendu_uid AS cr_uid, p.ordre_absolu_seance AS ord
                FROM amdts am
                JOIN alinea.cr_parole p ON p.loi_ref = :loicod
                 AND p.texte_brut ILIKE '%amendement%'
                 AND p.texte_brut ~ ('(n[°ºo]s? ?|amendements?[^0-9]{0,10})' || am.num || '([^0-9a-zàâäçéèêëîïôöùûüÿœæ°]|$)')
            ),
            gaps AS (
                SELECT *, CASE WHEN ord - lag(ord) OVER (PARTITION BY id, cr_uid ORDER BY ord) > 100
                               THEN 1 ELSE 0 END AS ng
                FROM mentions
            ),
            grp AS (
                SELECT *, sum(ng) OVER (PARTITION BY id, cr_uid ORDER BY ord) AS g
                FROM gaps
            ),
            grappe AS (
                SELECT id, numero, sort, auteur, annee, texte_num, num,
                       min(ord) AS lo, max(ord) AS hi, count(*) AS nb_cit
                FROM grp
                GROUP BY id, numero, sort, auteur, annee, texte_num, num, cr_uid, g
            ),
            best AS (
                SELECT DISTINCT ON (id) id, numero, sort, auteur, annee, texte_num, num,
                       nb_cit, (hi - lo) AS span
                FROM grappe
                ORDER BY id, nb_cit DESC, (hi - lo) DESC
            )
            SELECT numero, sort, auteur, annee, texte_num, num, nb_cit, span
            FROM best
            WHERE nb_cit >= 2
            ORDER BY nb_cit DESC, span DESC
            LIMIT
            SQL . ' ' . max(1, $limite),
            ['signet' => $signet, 'loicod' => $loicod]
        );

        return array_map(static fn (array $r): array => [
            'numero' => $r['numero'],
            'sort' => $r['sort'],
            'auteur' => $r['auteur'] === null ? null : self::recaser($r['auteur']),
            'nb_cit' => (int) $r['nb_cit'],
            'span' => (int) $r['span'],
            'url' => sprintf(
                'https://www.senat.fr/amendements/%d-%d/%s/Amdt_%s.html',
                $r['annee'],
                $r['annee'] + 1,
                $r['texte_num'],
                $r['num']
            ),
        ], $rows);
    }

    /**
     * Extrait du CRI de séance publique du Sénat où un amendement a été
     * discuté : le pendant sénatorial de DebatRepository::findExtrait, ancré
     * par le tag loi_ref des paroles (posé à l'import) au lieu du
     * seanceDiscussionRef de l'open data AN.
     *
     * Même stratégie que l'AN : mentions du numéro en contexte
     * (« n° 242 », « amendement 242 »), regroupées en grappes contiguës ;
     * on préfère le premier compte rendu où le numéro apparaît au moins deux
     * fois (annonce + vote), une mention isolée ne servant que de secours.
     * Les amendements de commission (COM-) n'ont pas d'extrait : seuls les
     * CRI de séance publique du Sénat sont importés.
     *
     * @return array{compte_rendu: array<string, mixed>, paroles: list<array<string, mixed>>, nb_mentions: int}|null
     */
    public function findExtrait(string $loicod, string $numero, ?string $dateDepot = null): ?array
    {
        if (preg_match('/^(\d+)/', trim($numero), $m) !== 1) {
            return null;
        }
        $regex = '(n[°ºo]s? ?|amendements?[^0-9]{0,10})' . $m[1] . '([^0-9a-zàâäçéèêëîïôöùûüÿœæ°]|$)';

        // Séances où la loi a été discutée, chronologiques, à partir du dépôt
        // (le numéro d'un amendement n'est unique que par lecture : le filtre
        // par date évite l'homonyme d'une lecture précédente).
        $comptesRendus = $this->connection->fetchAllAssociative(
            "SELECT DISTINCT c.uid, (c.date_seance AT TIME ZONE 'Europe/Paris')::date::text AS date_seance, c.url_page
             FROM alinea.compte_rendu c
             JOIN alinea.cr_parole p ON p.compte_rendu_uid = c.uid AND p.loi_ref = ?
             WHERE c.chambre = 'senat'" . ($dateDepot !== null ? ' AND c.date_seance::date >= ?' : '') . '
             ORDER BY date_seance',
            $dateDepot !== null ? [$loicod, $dateDepot] : [$loicod]
        );

        $secours = null;
        foreach ($comptesRendus as $compteRendu) {
            $extrait = $this->extraireFenetre($compteRendu, $loicod, $regex);
            if ($extrait === null) {
                continue;
            }
            if ($extrait['nb_mentions'] >= 2) {
                return $extrait;
            }
            $secours ??= $extrait;
        }

        return $secours;
    }

    /**
     * Fenêtre de discussion de l'amendement dans un compte rendu donné :
     * grappe principale des mentions (coupure au-delà de 100 paroles d'écart),
     * puis restitution des paroles entre la première et la dernière.
     */
    private function extraireFenetre(array $compteRendu, string $loicod, string $regex): ?array
    {
        $mentions = $this->connection->fetchFirstColumn(
            "SELECT ordre_absolu_seance
             FROM alinea.cr_parole
             WHERE compte_rendu_uid = ? AND loi_ref = ?
               AND texte_brut ILIKE '%amendement%'
               AND texte_brut ~ ?
             ORDER BY ordre_absolu_seance",
            [$compteRendu['uid'], $loicod, $regex]
        );

        if ($mentions === []) {
            return null;
        }

        $grappes = [[array_shift($mentions)]];
        foreach ($mentions as $ordre) {
            if ($ordre - end($grappes[array_key_last($grappes)]) > 100) {
                $grappes[] = [];
            }
            $grappes[array_key_last($grappes)][] = $ordre;
        }
        usort($grappes, static fn (array $a, array $b): int => [\count($a), $a[0]] <=> [\count($b), $b[0]]);
        $grappe = end($grappes);

        $paroles = $this->connection->fetchAllAssociative(
            'SELECT ordre_absolu_seance, orateur_nom, orateur_qualite, texte_brut,
                    (texte_brut ~ ?) AS mentionne
             FROM alinea.cr_parole
             WHERE compte_rendu_uid = ? AND ordre_absolu_seance BETWEEN ? AND ?
             ORDER BY ordre_absolu_seance
             LIMIT 200',
            [$regex, $compteRendu['uid'], $grappe[0], end($grappe)]
        );

        return ['compte_rendu' => $compteRendu, 'paroles' => $paroles, 'nb_mentions' => \count($grappe)];
    }

    /**
     * « Jérôme DURAIN » → « Jérôme Durain » : Ameli livre le nom de famille
     * en capitales, on le remet en casse de titre pour l'affichage.
     */
    private static function recaser(string $nom): string
    {
        return preg_replace_callback(
            '/\b(\p{Lu}[\p{Lu}\'’-]+)(?=\s|$)/u',
            static fn (array $m): string => mb_convert_case(mb_strtolower($m[1]), MB_CASE_TITLE),
            $nom
        );
    }
}
