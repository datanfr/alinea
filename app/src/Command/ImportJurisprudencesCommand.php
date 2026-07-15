<?php

namespace App\Command;

use App\Service\JudilibreClient;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Importe dans alinea.jurisprudence les décisions de justice citant chaque
 * loi (API Judilibre — Cour de cassation, cours d'appel), pour le bloc
 * « La loi devant les juges » de la fiche loi.
 *
 * Les décisions sont recherchées par l'expression exacte « loi n° X » : les
 * lois récentes remontent peu de résultats (délai du contentieux), c'est
 * attendu. On stocke le total annoncé par l'API et le détail des premières
 * décisions par pertinence.
 */
#[AsCommand(name: 'app:import:jurisprudences', description: 'Importe les décisions citant chaque loi (API Judilibre) dans alinea.jurisprudence')]
class ImportJurisprudencesCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly JudilibreClient $judilibre,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('loi', null, InputOption::VALUE_REQUIRED, 'Limiter à une loi (numéro, ex. 2021-1729)')
            ->addOption('depuis', null, InputOption::VALUE_REQUIRED, 'Lois promulguées depuis cette date', '2020-01-01')
            ->addOption('reprise', null, InputOption::VALUE_NONE, 'Ignorer les lois déjà synchronisées');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->judilibre->estConfigure()) {
            $io->error('API Judilibre non configurée (JUDILIBRE_* dans .env.local).');

            return self::FAILURE;
        }

        $this->creerTables();

        $lois = $this->listerLois($input->getOption('loi'), $input->getOption('depuis'));
        if ($input->getOption('reprise')) {
            $dejaFaites = array_flip($this->connection->fetchFirstColumn(
                'SELECT DISTINCT loi_num FROM alinea.jurisprudence'
            ));
            $lois = array_values(array_filter($lois, static fn (string $num): bool => !isset($dejaFaites[$num])));
        }

        $io->section(sprintf('Recherche Judilibre pour %d lois', \count($lois)));

        $totalDecisions = 0;
        foreach ($lois as $i => $num) {
            try {
                $resultat = $this->judilibre->rechercherLoi($num);
            } catch (\RuntimeException $e) {
                $io->error($e->getMessage());

                return self::FAILURE;
            }

            $this->enregistrer($num, $resultat['decisions'], $resultat['total']);
            $totalDecisions += \count($resultat['decisions']);
            if ($resultat['total'] > 0) {
                $io->writeln(sprintf(
                    '  loi %s : %d décision%s (%d stockée%s)',
                    $num,
                    $resultat['total'],
                    $resultat['total'] > 1 ? 's' : '',
                    \count($resultat['decisions']),
                    \count($resultat['decisions']) > 1 ? 's' : ''
                ));
            }
            if (($i + 1) % 50 === 0) {
                $io->writeln(sprintf('  %d / %d…', $i + 1, \count($lois)));
            }
            usleep(120000); // quotas PISTE
        }

        $io->success(sprintf('%d lois synchronisées, %d décisions stockées.', \count($lois), $totalDecisions));

        return self::SUCCESS;
    }

    /**
     * Numéros des lois à synchroniser, les plus récentes d'abord.
     *
     * @return list<string>
     */
    private function listerLois(?string $numLoi, string $depuis): array
    {
        if ($numLoi !== null && $numLoi !== '') {
            return [$numLoi];
        }

        return $this->connection->fetchFirstColumn(
            <<<'SQL'
            SELECT data->'META'->'META_SPEC'->'META_TEXTE_CHRONICLE'->>'NUM'
            FROM legifrance.texte_version
            WHERE nature = 'LOI' AND id LIKE 'JORFTEXT%'
              AND data->'META'->'META_SPEC'->'META_TEXTE_CHRONICLE'->>'NUM' IS NOT NULL
              AND data->'META'->'META_SPEC'->'META_TEXTE_CHRONICLE'->>'DATE_TEXTE' BETWEEN :depuis AND '2998-12-31'
            ORDER BY data->'META'->'META_SPEC'->'META_TEXTE_CHRONICLE'->>'DATE_TEXTE' DESC
            SQL,
            ['depuis' => $depuis]
        );
    }

    /**
     * @param list<array<string, mixed>> $decisions
     */
    private function enregistrer(string $numLoi, array $decisions, int $total): void
    {
        $this->connection->beginTransaction();
        try {
            $this->connection->executeStatement('DELETE FROM alinea.jurisprudence WHERE loi_num = ?', [$numLoi]);
            $this->connection->executeStatement(
                'INSERT INTO alinea.jurisprudence_sync (loi_num, total_api, maj) VALUES (?, ?, now())
                 ON CONFLICT (loi_num) DO UPDATE SET total_api = excluded.total_api, maj = excluded.maj',
                [$numLoi, $total]
            );

            foreach ($decisions as $d) {
                $this->connection->executeStatement(
                    <<<'SQL'
                    INSERT INTO alinea.jurisprudence
                        (judilibre_id, loi_num, juridiction, chambre, formation, type, numero, ecli, date_decision, solution, sommaire, publication)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON CONFLICT (loi_num, judilibre_id) DO NOTHING
                    SQL,
                    [
                        $d['id'], $numLoi, $d['juridiction'], $d['chambre'], $d['formation'], $d['type'],
                        $d['numero'], $d['ecli'], $d['date_decision'], $d['solution'], $d['sommaire'], $d['publication'],
                    ]
                );
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }
    }

    private function creerTables(): void
    {
        $ddl = [
            'CREATE SCHEMA IF NOT EXISTS alinea',
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS alinea.jurisprudence (
                judilibre_id text NOT NULL,
                loi_num text NOT NULL,
                juridiction text,
                chambre text,
                formation text,
                type text,
                numero text,
                ecli text,
                date_decision date,
                solution text,
                sommaire text,
                publication text,
                PRIMARY KEY (loi_num, judilibre_id)
            )
            SQL,
            'CREATE INDEX IF NOT EXISTS jurisprudence_loi_idx ON alinea.jurisprudence (loi_num)',
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS alinea.jurisprudence_sync (
                loi_num text PRIMARY KEY,
                total_api integer NOT NULL,
                maj timestamptz NOT NULL
            )
            SQL,
        ];

        foreach ($ddl as $sql) {
            $this->connection->executeStatement($sql);
        }
    }
}
