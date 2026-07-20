<?php

namespace App\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Page de contact : le message part par email vers NOTIFICATION_EMAIL (même
 * destinataire que les notifications d'analyse IA), avec l'adresse du
 * visiteur en Reply-To. Rien n'est stocké en base : l'email est le seul canal.
 *
 * Pas de symfony/form ni de validator dans le projet : validation à la main,
 * champ pot de miel contre les robots, et re-rendu du formulaire avec les
 * valeurs saisies en cas d'erreur.
 */
class ContactController extends AbstractController
{
    private const MESSAGE_MAX = 5000;

    #[Route('/contact', name: 'contact', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('contact/index.html.twig', [
            'valeurs' => [],
            'erreurs' => [],
        ]);
    }

    #[Route('/contact', name: 'contact_envoyer', methods: ['POST'])]
    public function envoyer(
        Request $request,
        MailerInterface $mailer,
        LoggerInterface $logger,
        #[Autowire(env: 'NOTIFICATION_EMAIL')] string $destinataire,
    ): Response {
        if (!$this->isCsrfTokenValid('contact', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $payload = $request->getPayload();

        // Pot de miel : champ invisible pour un humain. S'il est rempli, on
        // fait mine d'accepter sans rien envoyer.
        if ($payload->getString('site') !== '') {
            $this->addFlash('contact_succes', 'Merci, votre message a bien été envoyé.');

            return $this->redirectToRoute('contact');
        }

        $valeurs = [
            'nom' => trim($payload->getString('nom')),
            'email' => trim($payload->getString('email')),
            'sujet' => trim($payload->getString('sujet')),
            'message' => trim($payload->getString('message')),
        ];

        $erreurs = $this->valider($valeurs);
        if ($erreurs !== []) {
            return $this->render('contact/index.html.twig', [
                'valeurs' => $valeurs,
                'erreurs' => $erreurs,
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $sujet = $valeurs['sujet'] !== '' ? $valeurs['sujet'] : 'nouveau message';
        $email = (new Email())
            ->from('alinea@remikel.fr')
            ->replyTo(new Address($valeurs['email'], $valeurs['nom']))
            ->to($destinataire)
            ->subject(sprintf('Alinéa, contact : %s', mb_substr($sujet, 0, 120)))
            ->text(<<<TXT
                Message reçu via la page de contact d'Alinéa.

                De : {$valeurs['nom']} <{$valeurs['email']}>
                Sujet : {$sujet}

                {$valeurs['message']}
                TXT);

        try {
            if ($destinataire === '') {
                throw new \RuntimeException('NOTIFICATION_EMAIL non configuré.');
            }
            $mailer->send($email);
        } catch (TransportExceptionInterface|\RuntimeException $e) {
            $logger->error("Page contact : envoi de l'email en échec", ['exception' => $e]);

            return $this->render('contact/index.html.twig', [
                'valeurs' => $valeurs,
                'erreurs' => ['global' => "L'envoi a échoué, merci de réessayer dans quelques instants."],
            ], new Response(status: Response::HTTP_SERVICE_UNAVAILABLE));
        }

        $this->addFlash('contact_succes', 'Merci, votre message a bien été envoyé.');

        return $this->redirectToRoute('contact');
    }

    /**
     * @param array{nom: string, email: string, sujet: string, message: string} $valeurs
     *
     * @return array<string, string>
     */
    private function valider(array $valeurs): array
    {
        $erreurs = [];

        if ($valeurs['nom'] === '') {
            $erreurs['nom'] = 'Merci d\'indiquer votre nom.';
        }
        if ($valeurs['email'] === '' || filter_var($valeurs['email'], \FILTER_VALIDATE_EMAIL) === false) {
            $erreurs['email'] = 'Merci d\'indiquer une adresse email valide.';
        }
        if ($valeurs['message'] === '') {
            $erreurs['message'] = 'Le message est vide.';
        } elseif (mb_strlen($valeurs['message']) > self::MESSAGE_MAX) {
            $erreurs['message'] = sprintf('Le message dépasse %d caractères.', self::MESSAGE_MAX);
        }

        return $erreurs;
    }
}
