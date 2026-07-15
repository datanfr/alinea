<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Prévient par email qu'un visiteur a demandé l'analyse IA d'une loi
 * (mode différé : la génération se fait sur une machine locale, pas en prod).
 * L'email contient le lien de la page et la commande à lancer.
 *
 * Un échec d'envoi ne doit jamais faire échouer la demande elle-même :
 * l'erreur est journalisée, la demande reste en base et sera de toute façon
 * visible via GET /api/ia/demandes.
 */
class NotificationAnalyse
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        #[Autowire(env: 'NOTIFICATION_EMAIL')] private readonly string $destinataire,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array{id: string, titre?: ?string} $loi
     */
    public function demandeDeposee(array $loi, string $dossierUid, int $nbAmendements): void
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
                Amendements jugés à analyser : {$nbAmendements}

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
}
