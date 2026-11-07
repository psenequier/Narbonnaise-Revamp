<?php

use App\Domain\Emails\BoiteEnvoi;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;

/**
 * Garde-fou MAIL_REDIRIGER_VERS : avec une copie de la vraie base, aucun e-mail ne doit
 * partir vers un membre. Tout va à une seule adresse, sans copie ni copie cachée.
 */
function messagesEnvoyes(): \Illuminate\Support\Collection
{
    $transport = Mail::mailer('array')->getSymfonyTransport();
    assert($transport instanceof ArrayTransport);

    return $transport->messages();
}

function preparerTransport(?string $redirection): void
{
    config([
        'mail.default' => 'array',
        'mail.to' => $redirection === null ? null : ['address' => $redirection, 'name' => 'Test'],
    ]);
    Mail::purge('array');
}

it('envoie tous les e-mails à l\'adresse de redirection, sans copie cachée', function () {
    preparerTransport('testeur@example.test');

    app(BoiteEnvoi::class)->deposer('admin.anomalies', ['membre@example.test'], ['cache1@example.test', 'cache2@example.test'], ['anomalies' => ['test']], BoiteEnvoi::INFORMATION);
    expect(app(BoiteEnvoi::class)->envoyer())->toBe(1);

    $messages = messagesEnvoyes();
    expect($messages)->toHaveCount(1);
    $email = $messages->first()->getOriginalMessage();
    assert($email instanceof \Symfony\Component\Mime\Email);
    expect(array_map(fn ($a) => $a->getAddress(), $email->getTo()))->toBe(['testeur@example.test'])
        ->and($email->getBcc())->toBe([])
        ->and($email->getCc())->toBe([])
        ->and(array_map(fn ($a) => $a->getAddress(), $messages->first()->getEnvelope()->getRecipients()))->toBe(['testeur@example.test']);
});

it('laisse les destinataires intacts sans redirection', function () {
    preparerTransport(null);

    app(BoiteEnvoi::class)->deposer('admin.anomalies', ['membre@example.test'], ['cache1@example.test'], ['anomalies' => ['test']], BoiteEnvoi::INFORMATION);
    app(BoiteEnvoi::class)->envoyer();

    $destinataires = array_map(fn ($a) => $a->getAddress(), messagesEnvoyes()->first()->getEnvelope()->getRecipients());
    expect($destinataires)->toBe(['membre@example.test', 'cache1@example.test']);
});

it('lit l\'adresse de redirection dans MAIL_REDIRIGER_VERS', function () {
    $lire = fn (string $valeur) => (function () use ($valeur) {
        $_SERVER['MAIL_REDIRIGER_VERS'] = $_ENV['MAIL_REDIRIGER_VERS'] = $valeur;
        putenv('MAIL_REDIRIGER_VERS='.$valeur);
        try {
            return require config_path('mail.php');
        } finally {
            putenv('MAIL_REDIRIGER_VERS');
            unset($_ENV['MAIL_REDIRIGER_VERS'], $_SERVER['MAIL_REDIRIGER_VERS']);
        }
    })();

    expect($lire(' testeur@example.test ')['to'])->toBe(['address' => 'testeur@example.test', 'name' => 'Test (e-mail redirigé)'])
        ->and($lire('')['to'])->toBeNull();
});
