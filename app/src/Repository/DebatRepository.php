<?php

namespace App\Repository;

use Doctrine\DBAL\Connection;

/**
 * Extraits des comptes rendus intégraux (CRI) de séance publique,
 * depuis la base « analysedebat » du projet PoliticAnalysis
 * (CRI parsés en paroles individuelles ordonnées).
 *
 * Stratégie inspirée de PoliticAnalysis (ScrutinDecrypteur) : on repère
 * les paroles qui mentionnent le numéro de l'amendement (« l'amendement
 * n° 739 », « les amendements identiques nos 627, …, 739 et 860 ») et on
 * restitue la fenêtre de discussion complète entre la première et la
 * dernière mention.
 */
class DebatRepository
{
    private const MAX_PAROLES = 200;

    public function __construct(private readonly Connection $debatsConnection)
    {
    }

    /**
     * Extrait du CRI correspondant à un amendement.
     *
     * @return array{compte_rendu: array<string, mixed>, paroles: list<array<string, mixed>>}|null
     */
    public function findExtrait(?string $seanceRef, string $numero): ?array
    {
        if ($seanceRef === null || $seanceRef === '' || !preg_match('/^\d+$/', $numero)) {
            return null;
        }

        $compteRendu = $this->debatsConnection->fetchAssociative(
            'SELECT uid, date_seance, date_seance_jour, num_seance_jour, session_libelle, legislature
             FROM compte_rendu WHERE seance_ref = ? LIMIT 1',
            [$seanceRef]
        );

        if ($compteRendu === false) {
            return null;
        }

        // Le numéro doit apparaître comme nombre isolé dans une parole qui
        // mentionne un amendement (couvre « n° 739 » comme les listes
        // d'identiques « nos 627, 638, …, 739 et 860 »).
        $regex = '(^|[^0-9])' . $numero . '([^0-9]|$)';

        $fenetre = $this->debatsConnection->fetchAssociative(
            "SELECT min(ordre_absolu_seance) AS debut, max(ordre_absolu_seance) AS fin
             FROM cr_parole
             WHERE compte_rendu_uid = ?
               AND texte_brut LIKE '%amendement%'
               AND texte_brut REGEXP ?",
            [$compteRendu['uid'], $regex]
        );

        if ($fenetre === false || $fenetre['debut'] === null) {
            return null;
        }

        $paroles = $this->debatsConnection->fetchAllAssociative(
            'SELECT ordre_absolu_seance, orateur_nom, orateur_qualite, texte_brut,
                    (texte_brut REGEXP ?) AS mentionne
             FROM cr_parole
             WHERE compte_rendu_uid = ? AND ordre_absolu_seance BETWEEN ? AND ?
             ORDER BY ordre_absolu_seance
             LIMIT ' . self::MAX_PAROLES,
            [$regex, $compteRendu['uid'], $fenetre['debut'], $fenetre['fin']]
        );

        $compteRendu['url_an'] = sprintf(
            'https://www.assemblee-nationale.fr/dyn/%d/comptes-rendus/seance/%s',
            $compteRendu['legislature'],
            $compteRendu['uid']
        );

        return ['compte_rendu' => $compteRendu, 'paroles' => $paroles];
    }
}
