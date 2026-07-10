# Codegenie Pulse Connector

WordPress-connector voor het bestaande Codegenie Pulse ingestiecontract.

Versie 1.2.0 ondersteunt daarnaast een Pulse-first koppelingsflow. Een klant vult de WordPress-URL in Pulse in, keurt de koppeling in WordPress goed en krijgt verificatie, foutmonitoring en deployment tracking automatisch ingesteld volgens het actieve plan.

## Automatisch koppelen

1. Installeer en activeer de plugin.
2. Kies in Pulse `Website toevoegen > WordPress koppelen`.
3. Vul de publieke HTTPS-home-URL in.
4. Keur de aanvraag goed in `Instellingen > Codegenie Pulse`.
5. Pulse maakt of koppelt de website en levert de configuratie eenmalig server-to-server terug.

De permanente DSN of ingestiontoken staat nooit in de browser-URL. Pulse haalt eerst een unieke site proof op via het discovery-endpoint. WordPress kan die proof alleen opnieuw berekenen met de eigen auth salts. De browser bevat alleen een kortlevend request token en een challenge die zonder de proof niet bruikbaar zijn.

## Platformcontract

De plugin gebruikt zonder extra platform-API:

* `POST /api/ingest/errors/{token}` voor foutgebeurtenissen en de verbindingstest
* `POST /api/ingest/deployments/{token}` voor WordPress-, plugin- en themawijzigingen
* `GET /.well-known/codegenie-pulse.txt` op de WordPress-site voor eigendomsverificatie

Voor automatisch koppelen gebruikt versie 1.2.0 aanvullend:

* `GET /wp-json/codegenie-pulse/v1/discovery` op de WordPress-site
* `POST /api/connectors/wordpress/exchange` op de gekozen Pulse-installatie

De deployment-URL wordt lokaal afgeleid van de fout-DSN. Dezelfde 64 tekens lange foutbrontoken wordt gebruikt, overeenkomstig het Codegenie Pulse-contract.

## Ondersteunde automatische gebeurtenissen

* fatale PHP shutdown errors
* onverwerkte exceptions die als fatale PHP-fout eindigen
* PHP warnings in de modus Uitgebreid of Debug
* PHP notices, strict- en deprecated-meldingen in de modus Debug
* `wp_mail_failed`, zonder ontvangers, headers of berichtinhoud
* WordPress REST-responses met HTTP-status 500 tot en met 599
* WordPress core updates
* plugin- en thema-updates
* pluginactivatie en -deactivatie
* themawissels

## PHP-foutcapturemodi

| Modus | Automatisch gerapporteerd | Aanbevolen gebruik |
| --- | --- | --- |
| Uitgeschakeld | Geen automatische PHP- of WordPress-signalen | Alleen expliciete helper-calls |
| Productie | Fatale fouten, onverwerkte exceptions, ingeschakelde WordPress-signalen | Productie, aanbevolen |
| Uitgebreid | Productie plus `E_WARNING` en `E_USER_WARNING` | Tijdelijke extra diagnose |
| Debug | Uitgebreid plus notices, strict en deprecated | Alleen tijdelijk op staging |

De connector leest geen bestaand `debug.log`- of PHP `error_log`-bestand. Alleen nieuwe fouten die na activatie plaatsvinden en door de PHP `error_reporting`-instelling zijn geactiveerd worden onderschept. De bestaande PHP error handler en normale PHP-logging blijven behouden.

Om eventstorms te beperken wordt een identieke niet-fatale fout standaard maximaal één keer per minuut verzonden. Daarnaast gelden maximaal tien unieke niet-fatale events per request.

## Expliciet rapporteren vanuit eigen code

```php
try {
    risky_operation();
} catch ( Throwable $throwable ) {
    codegenie_pulse_report_exception( $throwable, array(
        'feature' => 'checkout',
    ) );
}
```

Of zonder exception:

```php
codegenie_pulse_report_message(
    'De productimport is gestopt.',
    'error',
    array( 'job' => 'product_import' )
);
```

Gebruik alleen privacy-veilige context. De connector verwijdert bekende gevoelige sleutels aanvullend, maar een ontwikkelaar blijft verantwoordelijk voor de data die hij bewust meegeeft.

## Uitbreidingspunten

```php
add_filter( 'codegenie_pulse_http_timeout', function () {
    return 5.0;
} );
```

De niet-fatale sampling en requestlimiet kunnen gecontroleerd worden aangepast:

```php
add_filter( 'codegenie_pulse_non_fatal_sample_seconds', function () {
    return 120;
} );

add_filter( 'codegenie_pulse_non_fatal_per_request_limit', function () {
    return 5;
} );
```

HTTP voor een strikt lokale ontwikkelomgeving kan bewust worden toegestaan:

```php
add_filter( 'codegenie_pulse_allow_insecure_dsn', '__return_true' );
```

Gebruik die tweede filter nooit op productie.

Na een levering worden deze actions uitgevoerd zonder endpoint of token:

```php
add_action( 'codegenie_pulse_delivery_succeeded', function ( $kind, $status ) {
    // $kind is error of deployment.
}, 10, 2 );

add_action( 'codegenie_pulse_delivery_failed', function ( $kind, $status, $code ) {
    // Bevat uitsluitend een token-veilige foutcode.
}, 10, 3 );
```

## Compatibiliteit

* WordPress 6.2 of nieuwer
* getest tegen de API's van WordPress 7.0
* PHP 7.4 of nieuwer
* PHP OpenSSL met AES-256-GCM
* een publiek bereikbare HTTPS Codegenie Pulse-installatie

## Privacy- en securitygrenzen

* DSN versleuteld met een sleutel afgeleid van WordPress auth salts
* expliciete `manage_options`-toestemming en WordPress nonce voor automatisch koppelen
* een site proof die niet in de browserredirect staat
* geen permanente Pulse-secrets in browsergeschiedenis, querystrings of referrers
* uitsluitend HTTPS en een exact Pulse ingestiepad
* `wp_safe_remote_post()` en nul redirects, zodat een token niet naar een redirectdoel gaat
* maximaal 10 seconden configureerbare HTTP-timeout, standaard 3 seconden
* geen queue, cronjob, bezoekerstracking of telemetry
* geen cookies, authorization headers, request bodies, formulierdata of user-agent
* URL-querystrings worden verwijderd
* gevoelige contextsleutels en bekende secrets in tekst worden lokaal geredigeerd
* lokale backoff na netwerk-, limiet- en authenticatieproblemen
* sampling van identieke warnings en notices over requests heen
* maximaal tien unieke niet-fatale events per request, standaard
* bestaand PHP error handler- en loggedrag blijft behouden
* geen uitlezing of import van bestaande logbestanden
* uninstall verwijdert instellingen, status, backoff- en samplingdata
