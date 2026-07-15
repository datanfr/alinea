<?php

namespace App\Repository;

use Doctrine\DBAL\Connection;

/**
 * Demandes d'analyse IA déposées depuis le bouton de la page d'une loi,
 * dans le schéma applicatif « alinea » (voir sql/demande_analyse.sql).
 *
 * En mode différé (prod sans Ollama), le bouton enregistre une demande au
 * lieu de lancer le traitement sur place ; l'agent local les liste via
 * l'API /api/ia, génère les analyses avec le modèle local, les pousse,
 * puis clôture la demande.
 */
class DemandeAnalyseRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Dépose une demande pour un dossier. Vrai si elle vient d'être créée,
     * faux si une demande était déjà ouverte (l'index partiel absorbe le
     * doublon : pas de seconde ligne, pas de second email).
     */
    public function deposer(string $dossierUid, string $loiId): bool
    {
        $insere = $this->connection->executeStatement(
            'INSERT INTO alinea.demande_analyse (dossier_uid, loi_id)
             VALUES (:dossier, :loi)
             ON CONFLICT (dossier_uid) WHERE traite_le IS NULL DO NOTHING',
            ['dossier' => $dossierUid, 'loi' => $loiId]
        );

        return $insere > 0;
    }

    /**
     * Une demande est-elle en attente pour ce dossier ?
     */
    public function enAttente(string $dossierUid): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM alinea.demande_analyse WHERE dossier_uid = ? AND traite_le IS NULL',
            [$dossierUid]
        );
    }

    /**
     * Demandes en attente, de la plus ancienne à la plus récente.
     *
     * @return list<array{dossier_uid: string, loi_id: string, demande_le: string}>
     */
    public function listerEnAttente(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT dossier_uid, loi_id, demande_le
             FROM alinea.demande_analyse
             WHERE traite_le IS NULL
             ORDER BY demande_le'
        );
    }

    /**
     * Clôture la demande ouverte d'un dossier (analyses poussées).
     */
    public function cloturer(string $dossierUid): void
    {
        $this->connection->executeStatement(
            'UPDATE alinea.demande_analyse SET traite_le = now()
             WHERE dossier_uid = ? AND traite_le IS NULL',
            [$dossierUid]
        );
    }
}
