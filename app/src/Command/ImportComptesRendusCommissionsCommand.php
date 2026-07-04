<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Dom\Element;
use Dom\HTMLDocument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Importe les comptes rendus des réunions de commission dans le schéma
 * « alinea » (mêmes tables que la séance publique, type = 'commission').
 *
 * Contrairement à la séance publique (dataset XML SYCERON en masse), les CR
 * de commission ne sont publiés qu'à l'unité : le contenu HTML servi au site
 * de l'AN est accessible sur https://www.assemblee-nationale.fr/dyn/docs/{uid}.raw.
 * Chaque <p> devient une parole ; l'orateur est le préfixe en gras du
 * paragraphe (« Éric Pauget, rapporteur. », « Ugo Bernalicis (LFI-NFP). »).
 */
#[AsCommand(name: 'app:import:comptes-rendus-commissions', description: 'Importe les CR des réunions de commission (site AN) dans alinea.cr_parole')]
class ImportComptesRendusCommissionsCommand extends Command
{
    private const URL_CONTENU = 'https://www.assemblee-nationale.fr/dyn/docs/%s.raw';
    private const URL_PAGE = 'https://www.assemblee-nationale.fr/dyn/%d/comptes-rendus/%s/l%d%s%s%s_compte-rendu';

    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('legislature', 'l', InputOption::VALUE_REQUIRED, 'Législature à importer', '17')
            ->addOption('dossier', 'd', InputOption::VALUE_REQUIRED, 'Limiter à un dossier législatif (uid DLR…)')
            ->addOption('reprise', null, InputOption::VALUE_NONE, 'Ignorer les CR déjà importés');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->creerTables();

        $comptesRendus = $this->listerComptesRendus(
            (int) $input->getOption('legislature'),
            $input->getOption('dossier')
        );

        if ($input->getOption('reprise')) {
            $dejaImportes = array_flip($this->connection->fetchFirstColumn(
                "SELECT uid FROM alinea.compte_rendu WHERE type = 'commission'"
            ));
            $comptesRendus = array_values(array_filter(
                $comptesRendus,
                static fn (array $cr): bool => !isset($dejaImportes[$cr['cr_uid']])
            ));
        }

        $io->section(sprintf('Import de %d comptes rendus de commission', \count($comptesRendus)));

        $total = 0;
        $paroles = 0;
        $echecs = 0;
        foreach ($comptesRendus as $i => $cr) {
            $nb = $this->importer($cr);
            if ($nb === null) {
                ++$echecs;
            } else {
                ++$total;
                $paroles += $nb;
            }
            if (($i + 1) % 100 === 0) {
                $io->writeln(sprintf('  %d / %d…', $i + 1, \count($comptesRendus)));
            }
            usleep(150000); // politesse envers le site de l'AN
        }

        $io->success(sprintf('%d CR de commission importés (%d paroles), %d échecs.', $total, $paroles, $echecs));

        return self::SUCCESS;
    }

    /**
     * CR de commission à importer, avec leur réunion et leur organe.
     *
     * @return list<array<string, mixed>>
     */
    private function listerComptesRendus(int $legislature, ?string $dossierUid): array
    {
        $sql = <<<'SQL'
            SELECT r.data->>'compteRenduRef' AS cr_uid,
                   min(r.uid) AS reunion_uid,
                   min(r.data->>'timestampDebut') AS debut,
                   min(o.data->>'libelle') AS organe_libelle,
                   min(o.data->>'libelleAbrev') AS organe_abrev,
                   r.legislature
            FROM assemblee.reunions r
            LEFT JOIN assemblee.organes o ON o.uid = r.data->>'organeReuniRef'
            WHERE r.data->>'compteRenduRef' LIKE 'CRC%' AND r.legislature = :legislature
            SQL;
        $params = ['legislature' => $legislature];

        if ($dossierUid !== null) {
            $sql .= <<<'SQL'

              AND r.uid IN (
                SELECT acte->>'reunionRef'
                FROM assemblee.dossiers d,
                     jsonb_path_query(d.data, '$.actesLegislatifs.** ? (@.xsiType == "DiscussionCommission_Type")') AS acte
                WHERE d.uid = :dossier
              )
            SQL;
            $params['dossier'] = $dossierUid;
        }

        $sql .= "\nGROUP BY r.data->>'compteRenduRef', r.legislature ORDER BY min(r.data->>'timestampDebut')";

        return $this->connection->fetchAllAssociative($sql, $params);
    }

    /**
     * @return int|null nombre de paroles, ou null si le téléchargement a échoué
     */
    private function importer(array $cr): ?int
    {
        $html = $this->telecharger(sprintf(self::URL_CONTENU, $cr['cr_uid']));
        if ($html === null) {
            return null;
        }

        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');

        $this->connection->beginTransaction();
        try {
            $this->connection->executeStatement(
                <<<'SQL'
                INSERT INTO alinea.compte_rendu (uid, seance_ref, session_libelle, num_seance_jour, date_seance, legislature, type, url_page)
                VALUES (?, ?, ?, NULL, ?, ?, 'commission', ?)
                ON CONFLICT (uid) DO UPDATE SET
                    seance_ref = excluded.seance_ref,
                    session_libelle = excluded.session_libelle,
                    date_seance = excluded.date_seance,
                    legislature = excluded.legislature,
                    type = excluded.type,
                    url_page = excluded.url_page
                SQL,
                [
                    $cr['cr_uid'],
                    $cr['reunion_uid'],
                    $cr['organe_libelle'],
                    $cr['debut'],
                    $cr['legislature'],
                    $this->urlPage($cr),
                ]
            );
            $this->connection->executeStatement('DELETE FROM alinea.cr_parole WHERE compte_rendu_uid = ?', [$cr['cr_uid']]);

            $nb = 0;
            $ordre = 0;
            foreach ($document->querySelectorAll('p') as $paragraphe) {
                $texte = trim(preg_replace('/\s+/u', ' ', $paragraphe->textContent));
                if ($texte === '') {
                    continue;
                }

                $orateur = $this->extraireOrateur($paragraphe);
                if ($orateur !== null && str_starts_with($texte, $orateur['brut'])) {
                    $texte = trim(substr($texte, \strlen($orateur['brut'])));
                }

                $this->connection->executeStatement(
                    'INSERT INTO alinea.cr_parole (compte_rendu_uid, ordre_absolu_seance, orateur_nom, orateur_qualite, texte_brut)
                     VALUES (?, ?, ?, NULL, ?)',
                    [$cr['cr_uid'], ++$ordre, $orateur['nom'] ?? null, $texte]
                );
                ++$nb;
            }

            $this->connection->commit();

            return $nb;
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }
    }

    /**
     * Orateur = préfixe en gras (non italique) du paragraphe.
     *
     * @return array{nom: string, brut: string}|null
     */
    private function extraireOrateur(Element $paragraphe): ?array
    {
        $brut = '';
        foreach ($paragraphe->childNodes as $noeud) {
            if (!$noeud instanceof Element || $noeud->localName !== 'span') {
                break;
            }
            $style = $noeud->getAttribute('style');
            if (!str_contains($style, 'font-weight:bold') || str_contains($style, 'italic')) {
                break;
            }
            $brut .= $noeud->textContent;
        }

        $nom = trim(preg_replace('/\s+/u', ' ', $brut), " .\u{00A0}");
        if ($nom === '' || mb_strlen($nom) > 90) {
            return null;
        }

        return ['nom' => $nom, 'brut' => trim(preg_replace('/\s+/u', ' ', $brut))];
    }

    /**
     * Page publique du CR, ex. …/cion_lois/l17cion_lois2425042_compte-rendu
     * (slug = libellé abrégé de l'organe, session 2025 → « 2425 »).
     */
    private function urlPage(array $cr): ?string
    {
        if ($cr['organe_abrev'] === null
            || !preg_match('/S(\d{4})PO\d+N(\d+)$/', $cr['cr_uid'], $m)
        ) {
            return null;
        }

        $slug = strtolower($cr['organe_abrev']);
        $annee = (int) $m[1];

        return sprintf(
            self::URL_PAGE,
            $cr['legislature'],
            $slug,
            $cr['legislature'],
            $slug,
            sprintf('%02d%02d', ($annee - 1) % 100, $annee % 100),
            $m[2]
        );
    }

    private function telecharger(string $url): ?string
    {
        exec(sprintf('curl -fsSL --max-time 30 %s', escapeshellarg($url)), $lignes, $code);

        return $code === 0 ? implode("\n", $lignes) : null;
    }

    private function creerTables(): void
    {
        $ddl = [
            'CREATE SCHEMA IF NOT EXISTS alinea',
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS alinea.compte_rendu (
                uid text PRIMARY KEY,
                seance_ref text,
                session_libelle text,
                num_seance_jour text,
                date_seance timestamptz,
                legislature integer
            )
            SQL,
            'CREATE INDEX IF NOT EXISTS compte_rendu_seance_ref_idx ON alinea.compte_rendu (seance_ref)',
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS alinea.cr_parole (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                compte_rendu_uid text NOT NULL REFERENCES alinea.compte_rendu (uid) ON DELETE CASCADE,
                ordre_absolu_seance integer NOT NULL,
                orateur_nom text,
                orateur_qualite text,
                texte_brut text NOT NULL
            )
            SQL,
            'CREATE INDEX IF NOT EXISTS cr_parole_cr_ordre_idx ON alinea.cr_parole (compte_rendu_uid, ordre_absolu_seance)',
            "ALTER TABLE alinea.compte_rendu ADD COLUMN IF NOT EXISTS type text NOT NULL DEFAULT 'seance'",
            'ALTER TABLE alinea.compte_rendu ADD COLUMN IF NOT EXISTS url_page text',
        ];

        foreach ($ddl as $sql) {
            $this->connection->executeStatement($sql);
        }
    }
}
