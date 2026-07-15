<?php

namespace App\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Accès en lecture aux lois de la base « canutes » (schéma legifrance).
 *
 * Les textes sont stockés en JSONB tels que fournis par l'open data
 * Légifrance : les métadonnées utiles sont extraites directement en SQL.
 */
class LoiRepository
{
    private const TITRE = "data->'META'->'META_SPEC'->'META_TEXTE_VERSION'->>'TITREFULL'";
    private const NUM = "data->'META'->'META_SPEC'->'META_TEXTE_CHRONICLE'->>'NUM'";
    private const DATE_TEXTE = "data->'META'->'META_SPEC'->'META_TEXTE_CHRONICLE'->>'DATE_TEXTE'";

    // Légifrance utilise 2999-01-01 comme date inconnue : à écarter du tri.
    private const DATE_TRI = "NULLIF(" . self::DATE_TEXTE . ", '2999-01-01')";

    /**
     * L'état de vigueur vit sur la version consolidée (LEGITEXT), pas sur la
     * publication au JO (JORFTEXT) : on les relie par le CID de la chronique.
     * Une loi peut avoir plusieurs versions consolidées (MODIFIE puis
     * VIGUEUR…) : seule la plus récente — DATE_DEBUT maximal — fait foi.
     * Les lois sans version consolidée (anciennes, lois de finances…) sont
     * réputées en vigueur.
     */
    private const CTE_ETATS = <<<'SQL'
        WITH etats AS (
            SELECT DISTINCT ON (cid) cid, etat
            FROM (
                SELECT data->'META'->'META_SPEC'->'META_TEXTE_CHRONICLE'->>'CID' AS cid,
                       data->'META'->'META_SPEC'->'META_TEXTE_VERSION'->>'ETAT' AS etat,
                       data->'META'->'META_SPEC'->'META_TEXTE_VERSION'->>'DATE_DEBUT' AS debut
                FROM legifrance.texte_version
                WHERE nature = 'LOI' AND id LIKE 'LEGITEXT%'
            ) versions
            ORDER BY cid, debut DESC NULLS LAST
        )
        SQL;

    private const ETATS_HORS_VIGUEUR = ['ABROGE', 'PERIME', 'VIGUEUR_DIFF', 'ANNULE'];

    private const LIBELLES_HORS_VIGUEUR = [
        'ABROGE' => 'Abrogée',
        'PERIME' => 'Périmée',
        'VIGUEUR_DIFF' => 'Pas encore en vigueur',
        'ANNULE' => 'Annulée',
    ];

    private const MOIS = [
        1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
        'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Liste paginée des lois publiées au JO, les plus récentes d'abord.
     *
     * Par défaut, seules les lois en vigueur sont listées ; $inclureHorsVigueur
     * ajoute les abrogées, périmées et celles à l'entrée en vigueur différée.
     *
     * @return array{lois: list<array<string, mixed>>, total: int}
     */
    public function search(?string $query, int $page, int $perPage, bool $inclureHorsVigueur = false): array
    {
        $where = "nature = 'LOI' AND id LIKE 'JORFTEXT%'";
        $params = [];
        $types = [];

        if ($query !== null && trim($query) !== '') {
            $where .= " AND text_search @@ websearch_to_tsquery('french', :query)";
            $params['query'] = trim($query);
        }

        if (!$inclureHorsVigueur) {
            $where .= " AND coalesce(e.etat, 'VIGUEUR') NOT IN (:horsVigueur)";
            $params['horsVigueur'] = self::ETATS_HORS_VIGUEUR;
            $types['horsVigueur'] = ArrayParameterType::STRING;
        }

        $from = 'legifrance.texte_version LEFT JOIN etats e ON e.cid = id';

        $total = (int) $this->connection->fetchOne(
            self::CTE_ETATS . " SELECT count(*) FROM $from WHERE $where",
            $params,
            $types
        );

        $lois = $this->connection->fetchAllAssociative(
            self::CTE_ETATS . sprintf(
                ' SELECT id, %s AS titre, %s AS num, %s AS date_texte, e.etat
                 FROM %s
                 WHERE %s
                 ORDER BY %s DESC NULLS LAST, id DESC
                 LIMIT %d OFFSET %d',
                self::TITRE,
                self::NUM,
                self::DATE_TEXTE,
                $from,
                $where,
                self::DATE_TRI,
                $perPage,
                ($page - 1) * $perPage
            ),
            $params,
            $types
        );

        return ['lois' => array_map($this->hydrate(...), $lois), 'total' => $total];
    }

    /**
     * Métadonnées et contenus d'entête (visas, signataires) d'une loi.
     */
    public function find(string $id): ?array
    {
        $loi = $this->connection->fetchAssociative(
            self::CTE_ETATS . sprintf(
                " SELECT id, %s AS titre, %s AS num, %s AS date_texte, e.etat,
                        data->'META'->'META_SPEC'->'META_TEXTE_CHRONICLE'->>'ORIGINE_PUBLI' AS origine_publi,
                        data->'META'->'META_COMMUN'->>'ID_ELI' AS eli,
                        data->'VISAS'->>'CONTENU' AS visas,
                        data->'SIGNATAIRES'->>'CONTENU' AS signataires
                 FROM legifrance.texte_version LEFT JOIN etats e ON e.cid = id
                 WHERE id = :id AND nature = 'LOI'",
                self::TITRE,
                self::NUM,
                self::DATE_TEXTE
            ),
            ['id' => $id]
        );

        return $loi === false ? null : $this->hydrate($loi);
    }

    /**
     * Articles d'une loi, dans l'ordre du texte.
     *
     * La version publiée au JO (JORFARTI) est privilégiée ; à défaut,
     * la version consolidée (LEGIARTI) est utilisée.
     *
     * @return list<array<string, mixed>>
     */
    public function findArticles(string $cid): array
    {
        $sql = "SELECT id, num, data->'BLOC_TEXTUEL'->>'CONTENU' AS contenu
                FROM legifrance.article
                WHERE data->'CONTEXTE'->'TEXTE'->>'@cid' = :cid AND id LIKE :prefix
                ORDER BY (substring(num FROM '^[0-9]+'))::int NULLS LAST, num";

        foreach (['JORFARTI%', 'LEGIARTI%'] as $prefix) {
            $articles = $this->connection->fetchAllAssociative($sql, ['cid' => $cid, 'prefix' => $prefix]);
            if ($articles !== []) {
                return $articles;
            }
        }

        return [];
    }

    private function hydrate(array $loi): array
    {
        $loi['id'] = trim($loi['id']);
        $loi['date_fr'] = $this->formatDate($loi['date_texte'] ?? null);
        $loi['etat_libelle'] = self::LIBELLES_HORS_VIGUEUR[$loi['etat'] ?? ''] ?? null;

        return $loi;
    }

    private function formatDate(?string $date): ?string
    {
        if ($date === null || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || $date === '2999-01-01') {
            return null;
        }

        return sprintf('%d %s %d', (int) $m[3], self::MOIS[(int) $m[2]], (int) $m[1]);
    }
}
