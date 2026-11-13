<?php

namespace App\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Visiteurs à prévenir quand l'analyse IA d'un dossier est terminée, dans le
 * schéma applicatif « alinea » (voir sql/abonne_analyse.sql).
 *
 * L'adresse est facultative : elle est saisie à côté du bouton « Lancer
 * l'analyse IA », ou sur une loi dont l'analyse est déjà en cours. Elle ne
 * sert qu'à cet envoi unique, après quoi la ligne est supprimée.
 *
 * Comme le compteur de consultations, aucune de ces opérations ne doit
 * casser la page ni le traitement qui l'appelle : une table absente
 * (sql/abonne_analyse.sql non joué) se traduit par « pas d'abonné ».
 */
class AbonneAnalyseRepository
{
    /**
     * Plafond d'adresses en attente par dossier : sans compte ni
     * confirmation, le formulaire ne doit pas pouvoir servir à faire
     * envoyer des messages en nombre.
     */
    private const MAX_PAR_DOSSIER = 50;

    /**
     * Une inscription dont l'analyse n'est jamais venue finit par être
     * oubliée : rien ne conserve une adresse au-delà.
     */
    private const RETENTION_JOURS = 180;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Enregistre une adresse pour ce dossier. Vrai si l'alerte est en place
     * (inscription faite, ou déjà présente : réinscrire n'est pas une
     * erreur), faux si elle n'a pas pu l'être (plafond atteint, base
     * indisponible).
     */
    public function inscrire(string $dossierUid, string $loiId, string $email): bool
    {
        try {
            $this->purger();

            if ($this->nbEnAttente($dossierUid) >= self::MAX_PAR_DOSSIER) {
                return false;
            }

            $this->connection->executeStatement(
                'INSERT INTO alinea.abonne_analyse (dossier_uid, loi_id, email)
                 VALUES (:dossier, :loi, :email)
                 ON CONFLICT (dossier_uid, lower(email)) DO NOTHING',
                ['dossier' => $dossierUid, 'loi' => $loiId, 'email' => $email]
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Adresses en attente pour un dossier.
     *
     * @return list<array{id: int, loi_id: string, email: string}>
     */
    public function enAttente(string $dossierUid): array
    {
        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id, loi_id, email FROM alinea.abonne_analyse
                 WHERE dossier_uid = ? ORDER BY inscrit_le',
                [$dossierUid]
            );
        } catch (\Throwable) {
            return [];
        }

        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'loi_id' => $r['loi_id'], 'email' => $r['email']],
            $rows
        );
    }

    /**
     * Efface les inscriptions servies : l'alerte est partie, l'adresse n'a
     * plus de raison d'être conservée.
     *
     * @param list<int> $ids
     */
    public function oublier(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        try {
            $this->connection->executeStatement(
                'DELETE FROM alinea.abonne_analyse WHERE id IN (?)',
                [$ids],
                [ArrayParameterType::INTEGER]
            );
        } catch (\Throwable) {
            // Rien à faire de plus : la purge d'ancienneté finira le travail.
        }
    }

    private function nbEnAttente(string $dossierUid): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT count(*) FROM alinea.abonne_analyse WHERE dossier_uid = ?',
            [$dossierUid]
        );
    }

    /**
     * Purge d'ancienneté, accrochée aux inscriptions plutôt qu'à une tâche
     * planifiée : rare, peu coûteuse, et suffisante pour qu'aucune adresse
     * ne traîne indéfiniment faute d'analyse.
     */
    private function purger(): void
    {
        $this->connection->executeStatement(
            'DELETE FROM alinea.abonne_analyse WHERE inscrit_le < now() - ?::int * interval \'1 day\'',
            [self::RETENTION_JOURS]
        );
    }
}
