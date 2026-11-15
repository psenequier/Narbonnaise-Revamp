<?php

use App\Domain\Emails\BoiteEnvoi;
use App\Domain\Emails\TransportMailPhp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/** Remplace mail() par une fonction qui note ses appels. */
function mailPhpFactice(bool $accepte = true): ArrayObject
{
    $appels = new ArrayObject;
    Mail::extend('php_mail', fn () => new TransportMailPhp(function (string ...$args) use ($appels, $accepte): bool {
        $appels[] = $args;

        return $accepte;
    }));
    config(['mail.default' => 'php_mail', 'mail.to' => null, 'mail.from.address' => 'webmaster@example.test']);
    Mail::purge('php_mail');

    return $appels;
}

it('déclare le mode d\'envoi php_mail', function () {
    expect(config('mail.mailers.php_mail.transport'))->toBe('php_mail')
        ->and(Mail::mailer('php_mail')->getSymfonyTransport())->toBeInstanceOf(TransportMailPhp::class);
});

it('envoie par mail() de PHP, un appel par destinataire, copies cachées comprises', function () {
    $appels = mailPhpFactice();

    app(BoiteEnvoi::class)->deposer('admin.anomalies', ['membre@example.test'], ['cache@example.test'], ['anomalies' => ['test']], BoiteEnvoi::INFORMATION);
    expect(app(BoiteEnvoi::class)->envoyer())->toBe(1);

    expect($appels)->toHaveCount(2)
        ->and(array_column($appels->getArrayCopy(), 0))->toBe(['membre@example.test', 'cache@example.test']);
    [$a, $sujet, $corps, $entetes, $parametres] = $appels[0];
    expect($sujet)->toContain('Anomalies')
        ->and($entetes)->toContain('From:')->toContain('MIME-Version:')
        ->and($entetes)->not->toMatch('/^(To|Bcc|Subject):/mi')
        ->and($entetes)->not->toContain('cache@example.test')
        ->and($corps)->not->toContain("\r\n")
        ->and($parametres)->toBe('-fwebmaster@example.test');
});

it('note l\'échec dans la boîte d\'envoi quand mail() refuse', function () {
    mailPhpFactice(accepte: false);

    $id = app(BoiteEnvoi::class)->deposer('admin.anomalies', ['membre@example.test'], [], ['anomalies' => ['test']]);
    expect(app(BoiteEnvoi::class)->envoyer())->toBe(0);

    expect((string) DB::table('emails_sortants')->where('id', $id)->value('derniere_erreur'))->toContain('mail()');
});
