<?php

declare(strict_types=1);

namespace Fastsite\Form;

/**
 * What the engine says back to a visitor who submits a form.
 *
 * These strings are the NO-JAVASCRIPT path: a page's own script usually
 * validates first and shows nicer inline errors in the site's own words. But
 * when it doesn't — JS off, a bot, a hand-authored form with no script — the
 * engine answers, and it was answering in English on every site regardless of
 * language. On a Danish site that reads "Dato for festen is required", which
 * is worse than either language alone.
 *
 * Two layers, so the common case needs no configuration:
 *
 * 1. A built-in pack per locale, picked from the submission's locale (or the
 *    site's primary locale). English is the fallback for anything unshipped.
 * 2. A per-form override (`forms.messages`), merged on top. That exists
 *    because a built-in pack can only be neutral-polite, and a site's own
 *    voice usually isn't — "Skriv jeres navn" reads like a person, "Navn skal
 *    udfyldes" reads like a database. It is also the escape hatch for a locale
 *    with no built-in pack, and for languages where a single template cannot
 *    agree in gender with every field label (Spanish "obligatorio/obligatoria",
 *    Italian the same) — the packs use the masculine form, which is the
 *    convention for form messages, and the override fixes the rest.
 */
final class FormMessages
{
    /** Every key a form can need. Also the shape of a valid override. */
    public const KEYS = [
        'required', 'email', 'url', 'number', 'choice', 'consent',
        'challenge_title', 'challenge',
        'error_title', 'sent_title', 'sent_body',
        'throttled_title', 'throttled_body',
        'notfound_title', 'notfound_body', 'back',
    ];

    /**
     * `{field}` is the field's own label; `{consent}` the consent text.
     *
     * @var array<string, array<string, string>>
     */
    private const PACKS = [
        'en' => [
            'required' => '{field} is required.',
            'email' => '{field} is not a valid email address.',
            'url' => '{field} is not a valid URL.',
            'number' => '{field} must be a number.',
            'choice' => '{field} is not one of the available choices.',
            'consent' => 'Please accept {consent}.',
            'challenge_title' => 'Anti-spam check failed',
            'challenge' => 'The anti-spam check did not pass. Please go back and try once more.',
            'error_title' => 'Please check the form',
            'sent_title' => 'Message sent',
            'sent_body' => 'Thanks — your message has been sent. We will get back to you shortly.',
            'throttled_title' => 'Too many submissions',
            'throttled_body' => 'You have sent this form several times already. Please wait a little while before trying again.',
            'notfound_title' => 'Not found',
            'notfound_body' => 'This form does not exist, or is no longer accepting submissions.',
            'back' => 'Back to the site',
        ],
        'da' => [
            'required' => '{field} skal udfyldes.',
            'email' => '{field} er ikke en gyldig e-mailadresse.',
            'url' => '{field} er ikke en gyldig webadresse.',
            'number' => '{field} skal være et tal.',
            'choice' => '{field} er ikke et af de mulige valg.',
            'consent' => 'Accepter venligst {consent}.',
            'challenge_title' => 'Anti-spam-tjekket mislykkedes',
            'challenge' => 'Anti-spam-tjekket gik ikke igennem. Gå tilbage, og prøv igen.',
            'error_title' => 'Tjek lige formularen',
            'sent_title' => 'Beskeden er sendt',
            'sent_body' => 'Tak — vi har modtaget din besked og vender tilbage hurtigst muligt.',
            'throttled_title' => 'For mange forsøg',
            'throttled_body' => 'Du har allerede sendt denne formular flere gange. Vent et øjeblik, før du prøver igen.',
            'notfound_title' => 'Ikke fundet',
            'notfound_body' => 'Denne formular findes ikke længere.',
            'back' => 'Tilbage til siden',
        ],
        'de' => [
            'required' => '{field} ist erforderlich.',
            'email' => '{field} ist keine gültige E-Mail-Adresse.',
            'url' => '{field} ist keine gültige URL.',
            'number' => '{field} muss eine Zahl sein.',
            'choice' => '{field} ist keine der verfügbaren Optionen.',
            'consent' => 'Bitte akzeptieren Sie {consent}.',
            'challenge_title' => 'Spam-Prüfung fehlgeschlagen',
            'challenge' => 'Die Spam-Prüfung war nicht erfolgreich. Bitte gehen Sie zurück und versuchen Sie es erneut.',
            'error_title' => 'Bitte prüfen Sie das Formular',
            'sent_title' => 'Nachricht gesendet',
            'sent_body' => 'Danke — wir haben Ihre Nachricht erhalten und melden uns schnellstmöglich.',
            'throttled_title' => 'Zu viele Versuche',
            'throttled_body' => 'Sie haben dieses Formular bereits mehrfach gesendet. Bitte warten Sie einen Moment, bevor Sie es erneut versuchen.',
            'notfound_title' => 'Nicht gefunden',
            'notfound_body' => 'Dieses Formular existiert nicht mehr.',
            'back' => 'Zurück zur Website',
        ],
        'sv' => [
            'required' => '{field} måste fyllas i.',
            'email' => '{field} är inte en giltig e-postadress.',
            'url' => '{field} är inte en giltig webbadress.',
            'number' => '{field} måste vara ett tal.',
            'choice' => '{field} är inte ett av de tillgängliga valen.',
            'consent' => 'Godkänn {consent}.',
            'challenge_title' => 'Skräppostkontrollen misslyckades',
            'challenge' => 'Skräppostkontrollen gick inte igenom. Gå tillbaka och försök igen.',
            'error_title' => 'Kontrollera formuläret',
            'sent_title' => 'Meddelandet är skickat',
            'sent_body' => 'Tack — vi har tagit emot ditt meddelande och återkommer så snart vi kan.',
            'throttled_title' => 'För många försök',
            'throttled_body' => 'Du har redan skickat det här formuläret flera gånger. Vänta en stund innan du försöker igen.',
            'notfound_title' => 'Hittades inte',
            'notfound_body' => 'Det här formuläret finns inte längre.',
            'back' => 'Tillbaka till webbplatsen',
        ],
        'nb' => [
            'required' => '{field} må fylles ut.',
            'email' => '{field} er ikke en gyldig e-postadresse.',
            'url' => '{field} er ikke en gyldig nettadresse.',
            'number' => '{field} må være et tall.',
            'choice' => '{field} er ikke et av de tilgjengelige valgene.',
            'consent' => 'Godta {consent}.',
            'challenge_title' => 'Anti-spam-kontrollen mislyktes',
            'challenge' => 'Anti-spam-kontrollen gikk ikke gjennom. Gå tilbake og prøv igjen.',
            'error_title' => 'Sjekk skjemaet',
            'sent_title' => 'Meldingen er sendt',
            'sent_body' => 'Takk — vi har mottatt meldingen din og svarer så snart vi kan.',
            'throttled_title' => 'For mange forsøk',
            'throttled_body' => 'Du har allerede sendt dette skjemaet flere ganger. Vent litt før du prøver igjen.',
            'notfound_title' => 'Ikke funnet',
            'notfound_body' => 'Dette skjemaet finnes ikke lenger.',
            'back' => 'Tilbake til nettstedet',
        ],
        'nl' => [
            'required' => '{field} is verplicht.',
            'email' => '{field} is geen geldig e-mailadres.',
            'url' => '{field} is geen geldige URL.',
            'number' => '{field} moet een getal zijn.',
            'choice' => '{field} is geen van de beschikbare opties.',
            'consent' => 'Accepteer {consent}.',
            'challenge_title' => 'Antispamcontrole mislukt',
            'challenge' => 'De antispamcontrole is niet geslaagd. Ga terug en probeer het opnieuw.',
            'error_title' => 'Controleer het formulier',
            'sent_title' => 'Bericht verzonden',
            'sent_body' => 'Bedankt — we hebben je bericht ontvangen en nemen zo snel mogelijk contact op.',
            'throttled_title' => 'Te veel pogingen',
            'throttled_body' => 'Je hebt dit formulier al meerdere keren verzonden. Wacht even voordat je het opnieuw probeert.',
            'notfound_title' => 'Niet gevonden',
            'notfound_body' => 'Dit formulier bestaat niet meer.',
            'back' => 'Terug naar de site',
        ],
        'fr' => [
            'required' => '{field} est obligatoire.',
            'email' => "{field} n'est pas une adresse e-mail valide.",
            'url' => "{field} n'est pas une URL valide.",
            'number' => '{field} doit être un nombre.',
            'choice' => '{field} ne fait pas partie des choix proposés.',
            'consent' => 'Veuillez accepter {consent}.',
            'challenge_title' => 'Échec de la vérification anti-spam',
            'challenge' => 'La vérification anti-spam n\'a pas abouti. Merci de revenir en arrière et de réessayer.',
            'error_title' => 'Veuillez vérifier le formulaire',
            'sent_title' => 'Message envoyé',
            'sent_body' => 'Merci — nous avons bien reçu votre message et vous répondrons dans les meilleurs délais.',
            'throttled_title' => 'Trop de tentatives',
            'throttled_body' => 'Vous avez déjà envoyé ce formulaire plusieurs fois. Merci de patienter avant de réessayer.',
            'notfound_title' => 'Introuvable',
            'notfound_body' => "Ce formulaire n'existe plus.",
            'back' => 'Retour au site',
        ],
        'es' => [
            'required' => '{field} es obligatorio.',
            'email' => '{field} no es una dirección de correo válida.',
            'url' => '{field} no es una URL válida.',
            'number' => '{field} debe ser un número.',
            'choice' => '{field} no es una de las opciones disponibles.',
            'consent' => 'Acepta {consent}.',
            'challenge_title' => 'Verificación anti-spam fallida',
            'challenge' => 'La verificación anti-spam no se ha superado. Vuelve atrás e inténtalo de nuevo.',
            'error_title' => 'Revisa el formulario',
            'sent_title' => 'Mensaje enviado',
            'sent_body' => 'Gracias — hemos recibido tu mensaje y te responderemos lo antes posible.',
            'throttled_title' => 'Demasiados intentos',
            'throttled_body' => 'Ya has enviado este formulario varias veces. Espera un momento antes de volver a intentarlo.',
            'notfound_title' => 'No encontrado',
            'notfound_body' => 'Este formulario ya no existe.',
            'back' => 'Volver al sitio',
        ],
        'it' => [
            'required' => '{field} è obbligatorio.',
            'email' => "{field} non è un indirizzo e-mail valido.",
            'url' => '{field} non è un URL valido.',
            'number' => '{field} deve essere un numero.',
            'choice' => '{field} non è una delle opzioni disponibili.',
            'consent' => 'Accetta {consent}.',
            'challenge_title' => 'Verifica anti-spam non riuscita',
            'challenge' => 'La verifica anti-spam non è riuscita. Torna indietro e riprova.',
            'error_title' => 'Controlla il modulo',
            'sent_title' => 'Messaggio inviato',
            'sent_body' => 'Grazie — abbiamo ricevuto il tuo messaggio e ti risponderemo al più presto.',
            'throttled_title' => 'Troppi tentativi',
            'throttled_body' => 'Hai già inviato questo modulo più volte. Attendi un momento prima di riprovare.',
            'notfound_title' => 'Non trovato',
            'notfound_body' => 'Questo modulo non esiste più.',
            'back' => 'Torna al sito',
        ],
    ];

    /**
     * @param array<string, string> $strings
     */
    private function __construct(private readonly array $strings)
    {
    }

    /**
     * Which locales ship a built-in pack — for the dashboard's honest note.
     *
     * @return list<string>
     */
    public static function builtInLocales(): array
    {
        return array_keys(self::PACKS);
    }

    /**
     * @param array<string, string> $overrides per-form, merged over the pack
     */
    public static function for(string $locale, array $overrides = []): self
    {
        $pack = self::pack($locale);
        foreach ($overrides as $key => $value) {
            $value = trim((string) $value);
            if ($value !== '' && \in_array((string) $key, self::KEYS, true)) {
                $pack[(string) $key] = $value;
            }
        }

        return new self($pack);
    }

    /**
     * `da-DK` and `da` both resolve to the Danish pack; anything unshipped
     * falls back to English rather than to an empty string.
     *
     * @return array<string, string>
     */
    private static function pack(string $locale): array
    {
        $locale = strtolower(trim($locale));
        if ($locale === '') {
            return self::PACKS['en'];
        }
        if (isset(self::PACKS[$locale])) {
            return self::PACKS[$locale];
        }

        $base = explode('-', $locale)[0];

        // Norwegian is written `no`, `nb` or `nn` depending on who is asking.
        if ($base === 'no' || $base === 'nn') {
            $base = 'nb';
        }

        return self::PACKS[$base] ?? self::PACKS['en'];
    }

    /**
     * @param array<string, string> $replacements
     */
    public function get(string $key, array $replacements = []): string
    {
        $text = $this->strings[$key] ?? (self::PACKS['en'][$key] ?? $key);
        if ($replacements === []) {
            return $text;
        }

        $map = [];
        foreach ($replacements as $name => $value) {
            $map['{' . $name . '}'] = $value;
        }

        return strtr($text, $map);
    }

    /**
     * Validates and normalises an override map before it is stored — unknown
     * keys are dropped rather than silently kept, so a typo cannot look saved
     * while doing nothing.
     *
     * @param mixed $raw
     * @return array<string, string>
     */
    public static function sanitizeOverrides(mixed $raw): array
    {
        if (\is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = \is_array($decoded) ? $decoded : [];
        }
        if (!\is_array($raw)) {
            return [];
        }

        $out = [];
        foreach (self::KEYS as $key) {
            $value = $raw[$key] ?? null;
            if (\is_scalar($value) && trim((string) $value) !== '') {
                $out[$key] = mb_substr(trim((string) $value), 0, 500);
            }
        }

        return $out;
    }
}
