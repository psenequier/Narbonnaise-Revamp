<?php

declare(strict_types=1);

namespace App\Domain\Emails;

use Closure;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * Envoi par la fonction mail() de PHP (MAIL_MAILER=php_mail), comme GRR.
 *
 * Répétition du 15 nov. 2026 : depuis l'hébergement OVH, les envois SMTP authentifiés sont
 * acceptés puis perdus sans erreur, alors que le même message envoyé d'ailleurs arrive.
 * mail() passe par le service « Scripts e-mail » de l'hébergement, suivi dans l'espace client
 * (compteurs, e-mails en erreur). Laravel ne propose plus ce mode, d'où ce transport.
 *
 * Un appel par destinataire de l'enveloppe : les copies cachées restent cachées, et le
 * plafond de la boîte d'envoi (150 destinataires par heure) compte déjà chaque destinataire.
 */
final class TransportMailPhp extends AbstractTransport
{
    /** @var Closure(string, string, string, string, string): bool */
    private readonly Closure $envoyer;

    /** @param (Closure(string, string, string, string, string): bool)|null $envoyer remplacé dans les tests */
    public function __construct(?Closure $envoyer = null)
    {
        parent::__construct();
        $this->envoyer = $envoyer ?? static fn (string $a, string $sujet, string $corps, string $entetes, string $parametres): bool => mail($a, $sujet, $corps, $entetes, $parametres);
    }

    protected function doSend(SentMessage $message): void
    {
        [$entetes, $corps] = self::decouper($message->toString());
        $sujet = '';
        $gardees = [];
        // Une ligne d'en-tête peut se poursuivre sur les lignes qui commencent par une espace.
        foreach (preg_split('/\n(?![ \t])/', $entetes) ?: [] as $ligne) {
            if (preg_match('/^Subject:[ \t]*(.*)$/is', $ligne, $m) === 1) {
                $sujet = (string) preg_replace('/\n[ \t]+/', ' ', $m[1]);

                continue;
            }
            // mail() écrit lui-même To et Subject ; Bcc ne doit jamais partir dans les en-têtes.
            if (preg_match('/^(To|Bcc):/i', $ligne) === 1) {
                continue;
            }
            $gardees[] = str_replace("\n", "\r\n", $ligne);
        }

        $enveloppe = $message->getEnvelope();
        $expediteur = '-f'.$enveloppe->getSender()->getAddress();
        foreach ($enveloppe->getRecipients() as $destinataire) {
            if (! ($this->envoyer)($destinataire->getAddress(), $sujet, $corps, implode("\r\n", $gardees), $expediteur)) {
                throw new TransportException('La fonction mail() de PHP a refusé l\'e-mail pour '.$destinataire->getAddress().'.');
            }
        }
    }

    public function __toString(): string
    {
        return 'php_mail://default';
    }

    /** @return array{0: string, 1: string} en-têtes et corps, fins de ligne Unix (attendues par mail() sous Linux) */
    private static function decouper(string $brut): array
    {
        $parties = explode("\n\n", str_replace("\r\n", "\n", $brut), 2);

        return [$parties[0], $parties[1] ?? ''];
    }
}
