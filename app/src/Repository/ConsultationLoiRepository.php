<?php

namespace App\Repository;

use Doctrine\DBAL\Connection;

/**
 * Consultations des pages de lois, agrégées par jour dans le schéma
 * applicatif « alinea » (voir sql/consultation_loi.sql). Le compteur sert
 * uniquement à prioriser le traitement IA de fond : les lois les plus
 * consultées sont analysées d'abord (app:ia:demandes --toutes).
 */
class ConsultationLoiRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Incrémente le compteur du jour pour une loi. Silencieux si la table
     * n'existe pas encore (sql/consultation_loi.sql non joué) : le compteur
     * ne doit jamais casser l'affichage d'une page.
     */
    public function compter(string $loiId): void
    {
        try {
            $this->connection->executeStatement(
                'INSERT INTO alinea.consultation_loi (loi_id) VALUES (:loi)
                 ON CONFLICT (loi_id, jour) DO UPDATE SET nb = consultation_loi.nb + 1',
                ['loi' => $loiId]
            );
        } catch (\Throwable) {
            // Table absente ou base en lecture seule : tant pis pour le point.
        }
    }

    /**
     * Consultations cumulées par loi sur les derniers jours, les plus
     * consultées d'abord. Tableau vide si la table n'existe pas.
     *
     * @return array<string, int> loi_id => consultations
     */
    public function totalParLoi(int $jours = 90): array
    {
        try {
            $rows = $this->connection->fetchAllKeyValue(
                'SELECT loi_id, sum(nb) FROM alinea.consultation_loi
                 WHERE jour >= CURRENT_DATE - ?::int
                 GROUP BY loi_id ORDER BY 2 DESC',
                [$jours]
            );
        } catch (\Throwable) {
            return [];
        }

        return array_map(intval(...), $rows);
    }
}
