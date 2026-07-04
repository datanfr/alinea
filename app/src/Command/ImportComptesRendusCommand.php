<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Importe les comptes rendus intégraux (CRI) de la séance publique depuis
 * l'open data de l'Assemblée nationale (« syceronbrut ») vers le schéma
 * « alinea » de la base canutes.
 *
 * Inspiré de l'ImportComptesRendusCommand de PoliticAnalysis : chaque fichier
 * XML SYCERON est un compte rendu de séance dont les <paragraphe> sont les
 * paroles individuelles, ordonnées par l'attribut ordre_absolu_seance. On
 * n'en retient que le nécessaire au rattachement amendement → débat :
 * l'orateur, sa qualité et le texte brut.
 */
#[AsCommand(name: 'app:import:comptes-rendus', description: 'Importe les CRI de séance publique (open data AN) dans alinea.cr_parole')]
class ImportComptesRendusCommand extends Command
{
    private const URL = 'https://data.assemblee-nationale.fr/static/openData/repository/%d/vp/syceronbrut/syseron.xml.zip';

    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('legislature', 'l', InputOption::VALUE_REQUIRED, 'Législature à importer', '17')
            ->addOption('skip-download', null, InputOption::VALUE_NONE, 'Réutiliser le zip déjà téléchargé');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $legislature = (int) $input->getOption('legislature');

        $workDir = sprintf('%s/var/import/syseron-%d', $this->projectDir, $legislature);
        $zip = $workDir . '/syseron.xml.zip';

        if (!is_dir($workDir) && !mkdir($workDir, 0775, true)) {
            $io->error('Impossible de créer ' . $workDir);

            return self::FAILURE;
        }

        if (!$input->getOption('skip-download') || !is_file($zip)) {
            $url = sprintf(self::URL, $legislature);
            $io->section('Téléchargement de ' . $url);
            if (!$this->telecharger($url, $zip)) {
                $io->error('Échec du téléchargement.');

                return self::FAILURE;
            }
        }

        $io->section('Extraction');
        exec(sprintf('unzip -oq %s -d %s 2>&1', escapeshellarg($zip), escapeshellarg($workDir)), $sortie, $code);
        if ($code !== 0) {
            $io->error('Échec de l\'extraction : ' . implode("\n", $sortie));

            return self::FAILURE;
        }

        $fichiers = glob($workDir . '/xml/compteRendu/*.xml') ?: [];
        if ($fichiers === []) {
            $io->error('Aucun fichier compteRendu trouvé dans ' . $workDir . '/xml/compteRendu');

            return self::FAILURE;
        }

        $io->section(sprintf('Import de %d comptes rendus', \count($fichiers)));
        $this->creerTables();

        $total = 0;
        $paroles = 0;
        foreach ($fichiers as $i => $fichier) {
            $paroles += $this->importerFichier($fichier);
            ++$total;
            if (($i + 1) % 100 === 0) {
                $io->writeln(sprintf('  %d / %d…', $i + 1, \count($fichiers)));
            }
        }

        $io->success(sprintf('%d comptes rendus importés (%d paroles).', $total, $paroles));

        return self::SUCCESS;
    }

    private function telecharger(string $url, string $destination): bool
    {
        exec(sprintf('curl -fsSL -o %s %s 2>&1', escapeshellarg($destination), escapeshellarg($url)), $sortie, $code);

        return $code === 0 && is_file($destination);
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
        ];

        foreach ($ddl as $sql) {
            $this->connection->executeStatement($sql);
        }
    }

    /**
     * @return int nombre de paroles importées
     */
    private function importerFichier(string $fichier): int
    {
        $xml = @simplexml_load_file($fichier, options: LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        if ($xml === false || (string) $xml->uid === '') {
            return 0;
        }

        $uid = (string) $xml->uid;
        $meta = $xml->metadonnees;

        // dateSeance au format 20250321150000000
        $dateSeance = null;
        $brut = (string) ($meta->dateSeance ?? '');
        if (\strlen($brut) >= 14) {
            $dateSeance = sprintf(
                '%s-%s-%s %s:%s:%s',
                substr($brut, 0, 4), substr($brut, 4, 2), substr($brut, 6, 2),
                substr($brut, 8, 2), substr($brut, 10, 2), substr($brut, 12, 2)
            );
        }

        $this->connection->beginTransaction();
        try {
            $this->connection->executeStatement(
                <<<'SQL'
                INSERT INTO alinea.compte_rendu (uid, seance_ref, session_libelle, num_seance_jour, date_seance, legislature)
                VALUES (?, ?, ?, ?, ?, ?)
                ON CONFLICT (uid) DO UPDATE SET
                    seance_ref = excluded.seance_ref,
                    session_libelle = excluded.session_libelle,
                    num_seance_jour = excluded.num_seance_jour,
                    date_seance = excluded.date_seance,
                    legislature = excluded.legislature
                SQL,
                [
                    $uid,
                    (string) $xml->seanceRef ?: null,
                    (string) ($meta->session ?? '') ?: null,
                    (string) ($meta->numSeanceJour ?? '') ?: null,
                    $dateSeance,
                    (int) ($meta->legislature ?? 0) ?: null,
                ]
            );

            $this->connection->executeStatement('DELETE FROM alinea.cr_parole WHERE compte_rendu_uid = ?', [$uid]);

            $nb = 0;
            $valeurs = [];
            $params = [];
            // Les fichiers SYCERON déclarent un namespace par défaut : XPath
            // doit matcher par local-name() pour trouver les <paragraphe>.
            foreach ($xml->xpath('//*[local-name() = "paragraphe"]') ?: [] as $paragraphe) {
                $texte = isset($paragraphe->texte) ? trim(strip_tags($paragraphe->texte->asXML())) : '';
                if ($texte === '') {
                    continue;
                }

                $orateur = $paragraphe->orateurs->orateur ?? null;

                $valeurs[] = '(?, ?, ?, ?, ?)';
                array_push(
                    $params,
                    $uid,
                    (int) ($paragraphe['ordre_absolu_seance'] ?? 0),
                    trim((string) ($orateur->nom ?? '')) ?: null,
                    trim((string) ($orateur->qualite ?? '')) ?: null,
                    $texte
                );
                ++$nb;

                if (\count($valeurs) === 500) {
                    $this->insererParoles($valeurs, $params);
                    $valeurs = [];
                    $params = [];
                }
            }

            if ($valeurs !== []) {
                $this->insererParoles($valeurs, $params);
            }

            $this->connection->commit();

            return $nb;
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }
    }

    private function insererParoles(array $valeurs, array $params): void
    {
        $this->connection->executeStatement(
            'INSERT INTO alinea.cr_parole (compte_rendu_uid, ordre_absolu_seance, orateur_nom, orateur_qualite, texte_brut) VALUES '
                . implode(', ', $valeurs),
            $params
        );
    }
}
