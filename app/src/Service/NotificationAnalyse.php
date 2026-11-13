<?php

namespace App\Service;

use App\Repository\AbonneAnalyseRepository;
use App\Repository\LoiRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Les deux emails du flux d'analyse IA :
 *
 *   - demandeDeposee() prévient l'auteur du site qu'un visiteur a demandé
 *     l'analyse d'une loi (mode différé : la génération se fait sur une
 *     machine locale, pas en prod) ;
 *   - analyseTerminee() prévient les visiteurs qui ont laissé leur adresse
 *     que l'analyse qu'ils attendaient est en ligne.
 *
 * Un échec d'envoi ne doit jamais faire échouer ce qui a déclenché l'email :
 * l'erreur est journalisée, la demande reste en base et sera de toute façon
 * visible via GET /api/ia/demandes ; une alerte non partie garde son
 * inscription, qui sera retentée à la prochaine fin d'analyse du dossier.
 */
class NotificationAnalyse
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        #[Autowire(env: 'NOTIFICATION_EMAIL')] private readonly string $destinataire,
        private readonly LoggerInterface $logger,
        private readonly AbonneAnalyseRepository $abonnes,
        private readonly LoiRepository $lois,
    ) {
    }

    /**
     * @param array{id: string, titre?: ?string} $loi
     */
    public function demandeDeposee(array $loi, string $dossierUid, int $nbManquantes): void
    {
        if ($this->destinataire === '') {
            return;
        }

        $url = $this->urlGenerator->generate(
            'loi_show',
            ['id' => $loi['id']],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $titre = $loi['titre'] ?? $loi['id'];

        $email = (new Email())
            ->from('alinea@remikel.fr')
            ->to($this->destinataire)
            ->subject(sprintf('Alinéa — analyse demandée : %s', mb_substr($titre, 0, 120)))
            ->text(<<<TXT
                Un visiteur a demandé l'analyse IA d'une loi.

                Loi : {$titre}
                Page : {$url}
                Dossier AN : {$dossierUid}
                Analyses manquantes : {$nbManquantes}

                Pour la traiter depuis la machine locale (Ollama) :

                    bin/console app:ia:demandes

                La commande récupère toutes les demandes en attente, génère les
                analyses localement et les pousse en prod via l'API.
                TXT);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error("Notification d'analyse : envoi de l'email en échec", [
                'loi' => $loi['id'],
                'exception' => $e,
            ]);
        }
    }

    /**
     * Prévient les visiteurs inscrits sur ce dossier que l'analyse est
     * disponible, et efface leur adresse : elle n'a servi qu'à cet envoi.
     * Appelée aux deux endroits où un dossier se termine, la clôture d'une
     * demande (analyses poussées par l'agent local) et la fin de
     * app:ia:analyser (génération sur place).
     *
     * @return int nombre d'alertes envoyées
     */
    public function analyseTerminee(string $dossierUid): int
    {
        $abonnes = $this->abonnes->enAttente($dossierUid);
        if ($abonnes === []) {
            return 0;
        }

        // Le titre d'une loi coûte une requête : une seule par loi, même si
        // plusieurs personnes attendent la même.
        $titres = [];
        $servis = [];

        foreach ($abonnes as $abonne) {
            $loiId = $abonne['loi_id'];
            $titres[$loiId] ??= $this->lois->find($loiId)['titre'] ?? $loiId;

            if ($this->envoyerAlerte($loiId, $titres[$loiId], $abonne['email'])) {
                $servis[] = $abonne['id'];
            }
        }

        $this->abonnes->oublier($servis);

        $this->logger->info('Alertes de fin d\'analyse envoyées', [
            'dossier' => $dossierUid,
            'envoyees' => \count($servis),
            'attendues' => \count($abonnes),
        ]);

        return \count($servis);
    }

    /**
     * Faux si l'envoi a échoué : l'inscription est alors conservée, pour une
     * nouvelle tentative à la prochaine fin d'analyse du dossier.
     */
    private function envoyerAlerte(string $loiId, string $titre, string $adresse): bool
    {
        $url = $this->urlGenerator->generate(
            'loi_show',
            ['id' => $loiId],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $email = (new Email())
            ->from('alinea@remikel.fr')
            ->to($adresse)
            ->subject(sprintf('Alinéa : l\'analyse de « %s » est prête', mb_substr($titre, 0, 100)))
            ->text(<<<TXT
                Bonjour,

                L'analyse IA des amendements de cette loi est terminée :

                {$titre}
                {$url}

                Vous recevez ce message parce que vous aviez laissé votre adresse
                sur Alinéa pour être prévenu. Elle n'a servi qu'à cet envoi et
                vient d'être effacée : il n'y aura pas d'autre message.
                TXT);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error("Alerte de fin d'analyse : envoi de l'email en échec", [
                'loi' => $loiId,
                'exception' => $e,
            ]);

            return false;
        }

        return true;
    }
}
