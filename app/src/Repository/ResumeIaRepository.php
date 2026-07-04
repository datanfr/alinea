<?php

namespace App\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Résumés générés par IA, dans le schéma applicatif « alinea » (séparé des
 * schémas open data legifrance/assemblee, en lecture seule — voir sql/resume_ia.sql).
 *
 * Une même table couvre deux cibles polymorphes :
 *   - la synthèse globale d'une loi   (type_cible = 'loi',        cible_id = id JORFTEXT…) ;
 *   - l'analyse d'un amendement       (type_cible = 'amendement', cible_id = uid AMANR5…) :
 *     ce que l'amendement change (< 120 caractères), résumé détaillé éventuel,
 *     l'intention réelle de l'auteur, le degré d'ambiguïté entre objectif
 *     affiché et effet réel, la catégorie (coordination, rédactionnel, …, fond)
 *     et un score d'impact sur 100.
 */
class ResumeIaRepository
{
    private const LOI = 'loi';
    private const AMENDEMENT = 'amendement';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Synthèse globale d'une loi (id JORFTEXT…), ou null si non encore générée.
     */
    public function resumeLoi(string $loiId): ?string
    {
        $resume = $this->connection->fetchOne(
            'SELECT resume FROM alinea.resume_ia WHERE type_cible = ? AND cible_id = ?',
            [self::LOI, $loiId]
        );

        return $resume === false ? null : $resume;
    }

    /**
     * Analyses IA d'un lot d'amendements, indexées par uid.
     *
     * @param list<string> $uids
     *
     * @return array<string, array{resume: string, resume_detaille: ?string, intention: ?string, ambiguite: ?int, categorie: ?string, score_impact: ?int}>
     */
    public function analysesAmendements(array $uids): array
    {
        if ($uids === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociativeIndexed(
            'SELECT cible_id, resume, resume_detaille, intention, ambiguite, categorie, score_impact
             FROM alinea.resume_ia
             WHERE type_cible = ? AND cible_id IN (?)',
            [self::AMENDEMENT, $uids],
            [ParameterType::STRING, ArrayParameterType::STRING]
        );

        foreach ($rows as &$row) {
            $row['ambiguite'] = $row['ambiguite'] !== null ? (int) $row['ambiguite'] : null;
            $row['score_impact'] = $row['score_impact'] !== null ? (int) $row['score_impact'] : null;
        }

        return $rows;
    }

    /**
     * Enregistre (ou remplace) la synthèse d'une loi.
     */
    public function enregistrerLoi(string $loiId, string $resume, ?string $modele = null): void
    {
        $this->upsert(self::LOI, $loiId, ['resume' => $resume, 'modele' => $modele]);
    }

    /**
     * Enregistre (ou remplace) l'analyse IA d'un amendement.
     *
     * @param array{resume: string, resume_detaille: ?string, intention: ?string, ambiguite: ?int, categorie: ?string, score_impact: ?int} $analyse
     */
    public function enregistrerAmendement(string $uid, array $analyse, ?string $modele = null): void
    {
        $this->upsert(self::AMENDEMENT, $uid, [
            'resume' => $analyse['resume'],
            'resume_detaille' => $analyse['resume_detaille'],
            'intention' => $analyse['intention'],
            'ambiguite' => $analyse['ambiguite'],
            'categorie' => $analyse['categorie'],
            'score_impact' => $analyse['score_impact'],
            'modele' => $modele,
        ]);
    }

    /**
     * @param array<string, mixed> $champs colonnes à écrire (resume obligatoire)
     */
    private function upsert(string $typeCible, string $cibleId, array $champs): void
    {
        $colonnes = array_keys($champs);
        $maj = implode(', ', array_map(static fn (string $c): string => "$c = EXCLUDED.$c", $colonnes));

        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO alinea.resume_ia (type_cible, cible_id, %s)
                 VALUES (:type, :cible, :%s)
                 ON CONFLICT (type_cible, cible_id) DO UPDATE SET %s, maj_le = now()',
                implode(', ', $colonnes),
                implode(', :', $colonnes),
                $maj
            ),
            ['type' => $typeCible, 'cible' => $cibleId] + $champs
        );
    }
}
