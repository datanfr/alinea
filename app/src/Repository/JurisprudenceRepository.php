<?php

namespace App\Repository;

use Doctrine\DBAL\Connection;

/**
 * Décisions de justice citant une loi, importées de l'API Judilibre par
 * app:import:jurisprudences (lecture seule — l'écriture vit dans la commande).
 */
class JurisprudenceRepository
{
    private const JURIDICTIONS = [
        'cc' => 'Cour de cassation',
        'ca' => "Cour d'appel",
        'tj' => 'Tribunal judiciaire',
        'tcom' => 'Tribunal de commerce',
    ];

    private const CHAMBRES = [
        'civ1' => '1ʳᵉ chambre civile',
        'civ2' => '2ᵉ chambre civile',
        'civ3' => '3ᵉ chambre civile',
        'comm' => 'Chambre commerciale',
        'soc' => 'Chambre sociale',
        'cr' => 'Chambre criminelle',
        'creun' => 'Chambres réunies',
        'mi' => 'Chambre mixte',
        'pl' => 'Assemblée plénière',
        'ordo' => 'Ordonnance du premier président',
        'allciv' => 'Chambres civiles',
    ];

    /** Taxonomie « solution » de l'API Judilibre (GET /taxonomy?id=solution). */
    private const SOLUTIONS = [
        'cassation' => 'Cassation',
        'rejet' => 'Rejet',
        'annulation' => 'Annulation',
        'avis' => 'Avis',
        'decheance' => 'Déchéance',
        'designation' => 'Désignation de juridiction',
        'irrecevabilite' => 'Irrecevabilité',
        'nonlieu' => 'Non-lieu à statuer',
        'qpc' => 'QPC renvoi',
        'qpcother' => 'QPC',
        'rabat' => 'Rabat',
        'reglement' => 'Règlement des juges',
        'renvoi' => 'Renvoi',
        'other' => 'Autre',
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Bilan de synchronisation : total annoncé par Judilibre et date de mise
     * à jour, ou null si la loi n'a jamais été synchronisée.
     *
     * @return array{total: int, maj: string}|null
     */
    public function syncPourLoi(?string $numLoi): ?array
    {
        if ($numLoi === null || $numLoi === '') {
            return null;
        }

        $sync = $this->connection->fetchAssociative(
            'SELECT total_api AS total, maj FROM alinea.jurisprudence_sync WHERE loi_num = ?',
            [$numLoi]
        );

        return $sync === false ? null : ['total' => (int) $sync['total'], 'maj' => $sync['maj']];
    }

    /**
     * Les décisions les plus pertinentes citant la loi.
     *
     * @return list<array<string, mixed>>
     */
    public function findPourLoi(?string $numLoi, int $limite = 5): array
    {
        if ($numLoi === null || $numLoi === '') {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT judilibre_id, juridiction, chambre, formation, type, numero, ecli,
                    date_decision::text AS date_decision, solution, sommaire, publication
             FROM alinea.jurisprudence
             WHERE loi_num = ?
             ORDER BY date_decision DESC NULLS LAST
             LIMIT ' . max(1, $limite),
            [$numLoi]
        );

        return array_map(static fn (array $r): array => $r + [
            'juridiction_libelle' => self::JURIDICTIONS[$r['juridiction']] ?? $r['juridiction'],
            'chambre_libelle' => self::CHAMBRES[$r['chambre']] ?? $r['chambre'],
            'solution_libelle' => self::SOLUTIONS[$r['solution']] ?? $r['solution'],
            'url' => 'https://www.courdecassation.fr/decision/' . $r['judilibre_id'],
        ], $rows);
    }

    /**
     * Existence de la table (la commande d'import ne l'a peut-être jamais
     * créée) : évite une erreur 500 sur les fiches loi tant que le premier
     * import n'a pas tourné.
     */
    public function estDisponible(): bool
    {
        return (bool) $this->connection->fetchOne(
            "SELECT to_regclass('alinea.jurisprudence_sync') IS NOT NULL"
        );
    }
}
