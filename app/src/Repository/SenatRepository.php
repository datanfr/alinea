<?php

namespace App\Repository;

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
