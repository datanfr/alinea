<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Lancement et suivi du traitement IA complet d'un dossier en arrière-plan :
 * la page d'une loi propose un bouton qui déclenche app:ia:analyser en tâche
 * de fond (nohup), et affiche l'avancement au fil des rechargements.
 *
 * L'état « en cours » repose sur un verrou flock par dossier (var/ia/) :
 * la commande le détient pendant toute sa durée, et il tombe de lui-même
 * si le processus meurt — pas d'état orphelin à nettoyer.
 */
class AnalyseArrierePlan
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Un traitement de ce dossier est-il en train de tourner ? Vrai si le
     * verrou est détenu, ou si un lancement date de moins de deux minutes
     * (le temps que la tâche de fond démarre et prenne le verrou).
     */
    public function estEnCours(string $dossierUid): bool
    {
        $poignee = @fopen($this->fichierVerrou($dossierUid), 'c');
        if ($poignee === false) {
            return false;
        }

        $libre = flock($poignee, \LOCK_EX | \LOCK_NB);
        if ($libre) {
            flock($poignee, \LOCK_UN);
        }
        fclose($poignee);

        if (!$libre) {
            return true;
        }

        $demarrage = @filemtime($this->fichierDemarrage($dossierUid));

        return $demarrage !== false && time() - $demarrage < 120;
    }

    /**
     * Prend le verrou du dossier pour la durée du traitement (commande
     * app:ia:analyser). Null si un autre traitement le détient déjà.
     * La poignée doit rester ouverte jusqu'à la fin ; le verrou est libéré
     * à sa fermeture ou à la mort du processus.
     *
     * @return resource|null
     */
    public function verrouiller(string $dossierUid)
    {
        $poignee = fopen($this->fichierVerrou($dossierUid), 'c');
        if ($poignee === false || !flock($poignee, \LOCK_EX | \LOCK_NB)) {
            if (\is_resource($poignee)) {
                fclose($poignee);
            }

            return null;
        }

        return $poignee;
    }

    /**
     * Lance app:ia:analyser sur le dossier en tâche de fond, détachée de la
     * requête web. Faux si un traitement est déjà en cours.
     */
    public function lancer(string $dossierUid): bool
    {
        if (preg_match('/^[A-Z0-9]+$/', $dossierUid) !== 1 || $this->estEnCours($dossierUid)) {
            return false;
        }

        $commande = sprintf(
            'nohup php %s app:ia:analyser %s >> %s 2>&1 &',
            escapeshellarg($this->projectDir . '/bin/console'),
            escapeshellarg($dossierUid),
            escapeshellarg($this->projectDir . '/var/log/ia_' . $dossierUid . '.log')
        );
        exec($commande);
        touch($this->fichierDemarrage($dossierUid));

        $this->logger->info('Analyse IA lancée en arrière-plan', ['dossier' => $dossierUid]);

        return true;
    }

    private function fichierVerrou(string $dossierUid): string
    {
        return $this->repertoire() . '/' . $dossierUid . '.lock';
    }

    private function fichierDemarrage(string $dossierUid): string
    {
        return $this->repertoire() . '/' . $dossierUid . '.demarrage';
    }

    private function repertoire(): string
    {
        $dir = $this->projectDir . '/var/ia';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }
}
