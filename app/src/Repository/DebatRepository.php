<?php

namespace App\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Extraits des comptes rendus intégraux (CRI) de séance publique, importés
 * dans le schéma « alinea » par la commande app:import:comptes-rendus
 * (open data AN parsé en paroles individuelles ordonnées).
 *
 * Stratégie inspirée du code de PoliticAnalysis (ScrutinDecrypteur) : on
 * repère les paroles qui mentionnent le numéro de l'amendement
 * (« l'amendement n° 739 », « les amendements identiques nos 627, …, 739
 * et 860 ») et on restitue la fenêtre de discussion complète entre la
 * première et la dernière mention.
 *
 * L'ancrage se fait par le seanceDiscussionRef de l'amendement quand il est
 * renseigné ; sinon on parcourt les comptes rendus de toutes les séances
 * publiques du dossier postérieures au dépôt (le numéro d'un amendement
 * n'est unique que par lecture : le filtre par date évite de tomber sur
 * l'homonyme d'une lecture précédente).
 */
class DebatRepository
{
    private const MAX_PAROLES = 200;

    /**
     * En-tête de section d'un compte rendu de commission (« Amendement CL268
     * de M. … », « Article 2 (art. 19…) », « Après l'article 1er »…). Sert à
     * borner la discussion d'un amendement sans déborder sur la suivante.
     */
    private const REGEX_ENTETE_SECTION = '^(Amendements? |Sous-amendements? |Article |Après l’article|Avant l’article|Chapitre|Titre |Section )';

    /**
     * Décision de la commission sur un amendement (« La commission adopte
     * l'amendement. », « L'amendement est retiré. », « Contre l'avis du
     * rapporteur, la commission rejette l'amendement. »…) : clôt la fenêtre.
     */
    private const REGEX_DECISION_COMMISSION = '^(La commission|Successivement|Contre l|Suivant l|L’amendement|Les amendements|Le sous-amendement)';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Extrait du CRI correspondant à un amendement.
     *
     * @param list<string> $seancesDossier réunions de repli si l'amendement n'a pas de séance propre
     *
     * @return array{compte_rendu: array<string, mixed>, paroles: list<array<string, mixed>>}|null
     */
    public function findExtrait(
        ?string $seanceRef,
        string $numero,
        array $seancesDossier = [],
        ?string $dateDepot = null,
        array $crCommissions = [],
    ): ?array {
        // Amendements de commission : numéro préfixé par l'organe (CL151,
        // CE751…). Le numéro n'est cité qu'une fois — dans l'en-tête
        // « Amendement CL151 de M. … » qui ouvre sa discussion — donc la
        // fenêtre se délimite par les en-têtes de section, pas par une
        // seconde mention (absente ici).
        if (preg_match('/^[A-Z]{2,6}\d+$/', $numero)) {
            $regex = '(^|[^0-9A-Za-z])' . $numero . '([^0-9]|$)';
            foreach ($this->comptesRendusCommission($crCommissions, $dateDepot) as $compteRendu) {
                $extrait = $this->extraireFenetreCommission($compteRendu, $regex);
                if ($extrait !== null) {
                    return $extrait;
                }
            }

            return null;
        }

        // Séance publique. « 856 (Rect) », « 167 (2ème Rect) » : le CRI cite
        // « n° 856 rectifié », seul le numéro compte.
        if (!preg_match('/^(\d+)/', $numero, $m)) {
            return null;
        }
        $candidats = $this->comptesRendus($seanceRef, $seancesDossier, $dateDepot);
        $regex = '(^|[^0-9])' . $m[1] . '([^0-9]|$)';

        // Une mention unique peut être une coïncidence (« 600 000 euros » dans
        // une parole évoquant un amendement) : on préfère le premier compte
        // rendu où le numéro apparaît au moins deux fois (annonce + vote), et
        // on ne se rabat sur une mention isolée qu'à défaut.
        $secours = null;
        foreach ($candidats as $compteRendu) {
            $extrait = $this->extraireFenetre($compteRendu, $regex);
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
     * Comptes rendus des réunions de commission du dossier, chronologiques,
     * à partir du dépôt de l'amendement.
     *
     * @param list<string> $crUids
     *
     * @return list<array<string, mixed>>
     */
    private function comptesRendusCommission(array $crUids, ?string $dateDepot): array
    {
        if ($crUids === []) {
            return [];
        }

        return $this->connection->fetchAllAssociative(
            'SELECT uid, date_seance, num_seance_jour, session_libelle, legislature, type, url_page
             FROM alinea.compte_rendu
             WHERE uid IN (?)' . ($dateDepot !== null ? ' AND date_seance::date >= ?' : '') . '
             ORDER BY date_seance',
            $dateDepot !== null ? [$crUids, $dateDepot] : [$crUids],
            $dateDepot !== null
                ? [ArrayParameterType::STRING, ParameterType::STRING]
                : [ArrayParameterType::STRING]
        );
    }

    /**
     * Comptes rendus candidats, par ordre de priorité : la séance propre de
     * l'amendement d'abord, puis les autres séances du dossier
     * (chronologiques, à partir du dépôt). Le seanceDiscussionRef de l'open
     * data est parfois erroné — l'examen a pu être réservé ou reporté à une
     * autre séance — d'où le repli systématique sur le reste du dossier.
     *
     * @param list<string> $seancesDossier
     *
     * @return list<array<string, mixed>>
     */
    private function comptesRendus(?string $seanceRef, array $seancesDossier, ?string $dateDepot): array
    {
        $candidats = [];

        if ($seanceRef !== null && $seanceRef !== '') {
            $compteRendu = $this->connection->fetchAssociative(
                'SELECT uid, date_seance, num_seance_jour, session_libelle, legislature, type, url_page
                 FROM alinea.compte_rendu WHERE seance_ref = ? LIMIT 1',
                [$seanceRef]
            );
            if ($compteRendu !== false) {
                $candidats[$compteRendu['uid']] = $compteRendu;
            }
        }

        if ($seancesDossier !== []) {
            $suivants = $this->connection->fetchAllAssociative(
                'SELECT uid, date_seance, num_seance_jour, session_libelle, legislature, type, url_page
                 FROM alinea.compte_rendu
                 WHERE seance_ref IN (?)' . ($dateDepot !== null ? ' AND date_seance::date >= ?' : '') . '
                 ORDER BY date_seance',
                $dateDepot !== null ? [$seancesDossier, $dateDepot] : [$seancesDossier],
                $dateDepot !== null
                    ? [ArrayParameterType::STRING, ParameterType::STRING]
                    : [ArrayParameterType::STRING]
            );
            foreach ($suivants as $compteRendu) {
                $candidats[$compteRendu['uid']] ??= $compteRendu;
            }
        }

        return array_values($candidats);
    }

    /**
     * Fenêtre de discussion de l'amendement dans un compte rendu donné.
     *
     * Le regex impose un numéro isolé dans une parole qui mentionne un
     * amendement (couvre « n° 739 » comme les listes d'identiques
     * « nos 627, 638, …, 739 et 860 », ou « l'amendement CL151 »).
     */
    private function extraireFenetre(array $compteRendu, string $regex): ?array
    {
        $mentions = $this->connection->fetchFirstColumn(
            "SELECT ordre_absolu_seance
             FROM alinea.cr_parole
             WHERE compte_rendu_uid = ?
               AND texte_brut ILIKE '%amendement%'
               AND texte_brut ~ ?
             ORDER BY ordre_absolu_seance",
            [$compteRendu['uid'], $regex]
        );

        if ($mentions === []) {
            return null;
        }

        $grappe = $this->grappePrincipale($mentions);
        [$debut, $fin] = [$grappe[0], end($grappe)];

        $paroles = $this->connection->fetchAllAssociative(
            'SELECT ordre_absolu_seance, orateur_nom, orateur_qualite, texte_brut,
                    (texte_brut ~ ?) AS mentionne
             FROM alinea.cr_parole
             WHERE compte_rendu_uid = ? AND ordre_absolu_seance BETWEEN ? AND ?
             ORDER BY ordre_absolu_seance
             LIMIT ' . self::MAX_PAROLES,
            [$regex, $compteRendu['uid'], $debut, $fin]
        );

        return ['compte_rendu' => $this->avecUrlAn($compteRendu), 'paroles' => $paroles, 'nb_mentions' => \count($grappe)];
    }

    /**
     * Fenêtre de discussion d'un amendement de commission.
     *
     * Le numéro n'apparaît qu'une fois, dans l'en-tête « Amendement CL268 de
     * M. … » : la fenêtre s'étend de cet en-tête à la décision de la commission
     * (« La commission adopte l'amendement. »), sans déborder sur l'en-tête de
     * section suivant (autre amendement, article…).
     *
     * @param array<string, mixed> $compteRendu
     *
     * @return array{compte_rendu: array<string, mixed>, paroles: list<array<string, mixed>>, nb_mentions: int}|null
     */
    private function extraireFenetreCommission(array $compteRendu, string $regex): ?array
    {
        $ancre = $this->connection->fetchOne(
            "SELECT ordre_absolu_seance
             FROM alinea.cr_parole
             WHERE compte_rendu_uid = ?
               AND texte_brut ILIKE '%amendement%'
               AND texte_brut ~ ?
             ORDER BY ordre_absolu_seance
             LIMIT 1",
            [$compteRendu['uid'], $regex]
        );
        if ($ancre === false) {
            return null;
        }
        $ancre = (int) $ancre;

        // Début : l'en-tête « Amendement(s) … » qui ouvre la discussion, au
        // niveau ou juste avant la première mention (en discussion commune, le
        // numéro peut n'apparaître qu'au fil des échanges, sous l'en-tête).
        $debut = $this->connection->fetchOne(
            "SELECT ordre_absolu_seance
             FROM alinea.cr_parole
             WHERE compte_rendu_uid = ? AND ordre_absolu_seance <= ?
               AND texte_brut ~ '^Amendements? '
             ORDER BY ordre_absolu_seance DESC
             LIMIT 1",
            [$compteRendu['uid'], $ancre]
        );
        $debut = $debut === false ? $ancre : (int) $debut;

        // Borne haute : l'en-tête de section suivant (autre amendement,
        // article, chapitre…), pour ne pas empiéter sur la discussion voisine.
        $borne = $this->connection->fetchOne(
            'SELECT ordre_absolu_seance
             FROM alinea.cr_parole
             WHERE compte_rendu_uid = ? AND ordre_absolu_seance > ?
               AND texte_brut ~ ?
             ORDER BY ordre_absolu_seance
             LIMIT 1',
            [$compteRendu['uid'], $debut, self::REGEX_ENTETE_SECTION]
        );
        $borne = $borne === false ? $debut + self::MAX_PAROLES : (int) $borne;

        // Fin : la dernière décision de la commission sur l'amendement avant
        // cette borne (adoption, rejet, retrait) ; en discussion commune, elle
        // englobe les décisions successives. À défaut, on s'arrête juste avant
        // la borne.
        $fin = $this->connection->fetchOne(
            "SELECT ordre_absolu_seance
             FROM alinea.cr_parole
             WHERE compte_rendu_uid = ?
               AND ordre_absolu_seance > ? AND ordre_absolu_seance < ?
               AND texte_brut ILIKE '%amendement%'
               AND texte_brut ~ ?
             ORDER BY ordre_absolu_seance DESC
             LIMIT 1",
            [$compteRendu['uid'], $debut, $borne, self::REGEX_DECISION_COMMISSION]
        );
        $fin = $fin === false ? $borne - 1 : (int) $fin;

        $paroles = $this->connection->fetchAllAssociative(
            'SELECT ordre_absolu_seance, orateur_nom, orateur_qualite, texte_brut,
                    (texte_brut ~ ?) AS mentionne
             FROM alinea.cr_parole
             WHERE compte_rendu_uid = ? AND ordre_absolu_seance BETWEEN ? AND ?
             ORDER BY ordre_absolu_seance
             LIMIT ' . self::MAX_PAROLES,
            [$regex, $compteRendu['uid'], $debut, $fin]
        );

        return [
            'compte_rendu' => $this->avecUrlAn($compteRendu),
            'paroles' => $paroles,
            'nb_mentions' => \count($paroles),
        ];
    }

    /**
     * Ajoute l'URL de la page publique du compte rendu (celle de l'open data
     * si connue, sinon reconstruite pour la séance).
     *
     * @param array<string, mixed> $compteRendu
     *
     * @return array<string, mixed>
     */
    private function avecUrlAn(array $compteRendu): array
    {
        $compteRendu['url_an'] = $compteRendu['url_page'] ?? sprintf(
            'https://www.assemblee-nationale.fr/dyn/%d/comptes-rendus/seance/%s',
            $compteRendu['legislature'],
            $compteRendu['uid']
        );

        return $compteRendu;
    }

    /**
     * Regroupe les positions des mentions en grappes (une discussion est
     * contiguë) et garde la plus dense. Un même numéro peut en effet
     * apparaître ailleurs dans la séance — homonyme d'un autre texte,
     * rappel au détour d'une phrase — et une fenêtre min/max naïve
     * engloberait toute la séance.
     *
     * @param non-empty-list<int> $ordres positions triées des mentions
     *
     * @return non-empty-list<int> positions de la grappe retenue
     */
    private function grappePrincipale(array $ordres): array
    {
        $grappes = [[array_shift($ordres)]];
        foreach ($ordres as $ordre) {
            if ($ordre - end($grappes[array_key_last($grappes)]) > 100) {
                $grappes[] = [];
            }
            $grappes[array_key_last($grappes)][] = $ordre;
        }

        usort($grappes, static fn (array $a, array $b): int =>
            [\count($a), $a[0]] <=> [\count($b), $b[0]]
        );

        return end($grappes);
    }
}
