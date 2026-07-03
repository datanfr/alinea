<?php

namespace App\Repository;

use Doctrine\DBAL\Connection;

/**
 * Accès aux amendements de l'Assemblée nationale (schéma assemblee).
 *
 * Le pont entre une loi Légifrance et son dossier AN est le numéro de loi
 * (ex. « 2025-532 ») : il figure dans l'acte de promulgation du dossier
 * (actesLegislatifs → Promulgation_Type → codeLoi).
 */
class AmendementRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Dossier législatif AN correspondant à un numéro de loi promulguée.
     */
    public function findDossierPourLoi(?string $numLoi): ?array
    {
        if ($numLoi === null || $numLoi === '') {
            return null;
        }

        $dossier = $this->connection->fetchAssociative(
            <<<'SQL'
            SELECT uid, data->'titreDossier'->>'titre' AS titre, legislature
            FROM assemblee.dossiers
            WHERE jsonb_path_exists(
                data,
                '$.actesLegislatifs.** ? (@.xsiType == "Promulgation_Type" && @.codeLoi == $num)',
                jsonb_build_object('num', :num::text)
            )
            LIMIT 1
            SQL,
            ['num' => $numLoi]
        );

        return $dossier === false ? null : $dossier;
    }

    public function countPourDossier(string $dossierUid): int
    {
        return (int) $this->connection->fetchOne(
            <<<'SQL'
            SELECT count(*)
            FROM assemblee.amendements a
            WHERE a.data->>'texteLegislatifRef' IN (
                SELECT uid FROM assemblee.documents d WHERE d.data->>'dossierRef' = :dossier
            )
            SQL,
            ['dossier' => $dossierUid]
        );
    }

    /**
     * Tous les amendements d'un dossier, groupés par phase d'examen
     * (chaque texte déposé : commission, séance, lectures suivantes),
     * dans l'ordre officiel de discussion (triAmendement).
     *
     * @return list<array{label: string, texte_num: string, amendements: list<array<string, mixed>>}>
     */
    public function findPourDossier(string $dossierUid): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT a.uid,
                   a.legislature,
                   a.data->'identification'->>'numeroLong' AS numero,
                   a.data->'identification'->>'prefixeOrganeExamen' AS prefixe_organe,
                   a.data->'cycleDeVie'->>'sort' AS sort,
                   a.data->'cycleDeVie'->'etatDesTraitements'->'etat'->>'libelle' AS etat,
                   a.data->'cycleDeVie'->'etatDesTraitements'->'sousEtat'->>'libelle' AS sous_etat,
                   left(a.data->'cycleDeVie'->>'dateDepot', 10) AS date_depot,
                   a.data->'pointeurFragmentTexte'->'division'->>'titre' AS division,
                   a.data->>'texteLegislatifRef' AS texte_ref,
                   substring(a.data->>'texteLegislatifRef' FROM '[0-9]+$') AS texte_num,
                   a.data->'signataires'->'auteur'->>'typeAuteur' AS type_auteur,
                   act.data->'etatCivil'->'ident'->>'civ' AS auteur_civ,
                   act.data->'etatCivil'->'ident'->>'prenom' AS auteur_prenom,
                   act.data->'etatCivil'->'ident'->>'nom' AS auteur_nom,
                   grp.data->>'libelleAbrev' AS groupe,
                   org.data->>'libelle' AS organe_examen
            FROM assemblee.amendements a
            LEFT JOIN assemblee.acteurs act ON act.uid = a.data->'signataires'->'auteur'->>'acteurRef'
            LEFT JOIN assemblee.organes grp ON grp.uid = a.data->'signataires'->'auteur'->>'groupePolitiqueRef'
            LEFT JOIN assemblee.organes org ON org.uid = substring(a.data->>'examenRef' FROM 'PO[0-9]+')
            WHERE a.data->>'texteLegislatifRef' IN (
                SELECT uid FROM assemblee.documents d WHERE d.data->>'dossierRef' = :dossier
            )
            ORDER BY a.data->>'texteLegislatifRef', a.data->>'triAmendement'
            SQL,
            ['dossier' => $dossierUid]
        );

        $phases = [];
        foreach ($rows as $row) {
            $phases[$row['texte_ref']]['label'] ??= $this->labelPhase($row['organe_examen']);
            $phases[$row['texte_ref']]['texte_num'] ??= $row['texte_num'];
            $phases[$row['texte_ref']]['amendements'][] = $this->hydrate($row);
        }

        // Ordre chronologique des phases (premier dépôt d'amendement).
        $phases = array_values($phases);
        usort($phases, static fn (array $a, array $b): int =>
            min(array_column($a['amendements'], 'date_depot')) <=> min(array_column($b['amendements'], 'date_depot'))
        );

        return $phases;
    }

    /**
     * Détail complet d'un amendement (dispositif, exposé sommaire, séance de discussion).
     */
    public function findOne(string $uid): ?array
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
            SELECT a.uid,
                   a.legislature,
                   a.data->'identification'->>'numeroLong' AS numero,
                   a.data->'identification'->>'prefixeOrganeExamen' AS prefixe_organe,
                   a.data->'cycleDeVie'->>'sort' AS sort,
                   a.data->'cycleDeVie'->'etatDesTraitements'->'etat'->>'libelle' AS etat,
                   a.data->'cycleDeVie'->'etatDesTraitements'->'sousEtat'->>'libelle' AS sous_etat,
                   left(a.data->'cycleDeVie'->>'dateDepot', 10) AS date_depot,
                   a.data->'pointeurFragmentTexte'->'division'->>'titre' AS division,
                   a.data->>'texteLegislatifRef' AS texte_ref,
                   substring(a.data->>'texteLegislatifRef' FROM '[0-9]+$') AS texte_num,
                   a.data->>'seanceDiscussionRef' AS seance_ref,
                   a.data->'corps'->'contenuAuteur'->>'dispositif' AS dispositif,
                   a.data->'corps'->'contenuAuteur'->>'exposeSommaire' AS expose_sommaire,
                   a.data->'signataires'->>'libelle' AS signataires,
                   a.data->'signataires'->'auteur'->>'typeAuteur' AS type_auteur,
                   act.data->'etatCivil'->'ident'->>'civ' AS auteur_civ,
                   act.data->'etatCivil'->'ident'->>'prenom' AS auteur_prenom,
                   act.data->'etatCivil'->'ident'->>'nom' AS auteur_nom,
                   grp.data->>'libelleAbrev' AS groupe,
                   org.data->>'libelle' AS organe_examen
            FROM assemblee.amendements a
            LEFT JOIN assemblee.acteurs act ON act.uid = a.data->'signataires'->'auteur'->>'acteurRef'
            LEFT JOIN assemblee.organes grp ON grp.uid = a.data->'signataires'->'auteur'->>'groupePolitiqueRef'
            LEFT JOIN assemblee.organes org ON org.uid = substring(a.data->>'examenRef' FROM 'PO[0-9]+')
            WHERE a.uid = :uid
            SQL,
            ['uid' => $uid]
        );

        return $row === false ? null : $this->hydrate($row);
    }

    private function hydrate(array $row): array
    {
        $row['auteur'] = $row['auteur_nom'] !== null
            ? trim(sprintf('%s %s %s', $row['auteur_civ'], $row['auteur_prenom'], $row['auteur_nom']))
            : ($row['type_auteur'] ?? '—');

        $row['statut'] = $this->libelleStatut($row);
        $row['sort_classe'] = $this->classerStatut($row);

        // Page officielle de l'amendement, ex. …/dyn/17/amendements/0907/CION_LOIS/CL151
        $row['url_an'] = sprintf(
            'https://www.assemblee-nationale.fr/dyn/%d/amendements/%s/%s/%s',
            $row['legislature'],
            $row['texte_num'],
            $row['prefixe_organe'],
            $row['numero']
        );

        return $row;
    }

    private function labelPhase(?string $organeExamen): string
    {
        if ($organeExamen !== null && str_starts_with($organeExamen, 'Commission')) {
            return $organeExamen;
        }

        return 'Séance publique';
    }

    /**
     * Le « sort » n'existe que pour les amendements arrivés en discussion
     * (Adopté, Rejeté, Tombé, Non soutenu, Retiré). Pour les autres, le
     * statut réel est dans etatDesTraitements : Irrecevable / Irrecevable 40
     * (motif en sousEtat : Cavalier (45), Charge…), Retiré avant ou après
     * publication, ou réellement en cours (En traitement, A discuter…).
     */
    private function libelleStatut(array $row): string
    {
        if ($row['sort'] !== null) {
            return $row['sort'];
        }

        return match (true) {
            $row['etat'] === null => 'En traitement',
            str_starts_with($row['etat'], 'Irrecevable') => $row['sous_etat'] !== null
                ? sprintf('%s — %s', $row['etat'], $row['sous_etat'])
                : $row['etat'],
            $row['etat'] === 'Retiré' => $row['sous_etat'] ?? 'Retiré',
            default => $row['etat'], // En traitement, A discuter, En recevabilité…
        };
    }

    private function classerStatut(array $row): string
    {
        $sort = $row['sort'];
        $etat = $row['etat'] ?? '';

        return match (true) {
            $sort !== null && str_contains($sort, 'Adopté') => 'adopte',
            $sort !== null && str_contains($sort, 'Rejeté') => 'rejete',
            $sort !== null => 'autre', // Retiré, Non soutenu, Tombé…
            str_starts_with($etat, 'Irrecevable') => 'irrecevable',
            in_array($etat, ['Retiré', 'effacé'], true) => 'autre',
            default => 'attente', // En traitement, A discuter, En recevabilité…
        };
    }
}
