# Codegenie Pulse Connector

WordPress-connector voor het bestaande Codegenie Pulse ingestiecontract.

## Platformcontract

De plugin gebruikt zonder extra platform-API:

* `POST /api/ingest/errors/{token}` voor foutgebeurtenissen en de verbindingstest
* `POST /api/ingest/deployments/{token}` voor WordPress-, plugin- en themawijzigingen
* `GET /.well-known/codegenie-pulse.txt` op de WordPress-site voor eigendomsverificatie

De deployment-URL wordt lokaal afgeleid van de fout-DSN. Dezelfde 64 tekens lange foutbrontoken wordt gebruikt, overeenkomstig het Codegenie Pulse-contract.

## Ondersteunde automatische gebeurtenissen

* fatale PHP shutdown errors
* `wp_mail_failed`, zonder ontvangers, headers of berichtinhoud
* WordPress REST-responses met HTTP-status 500 tot en met 599
* WordPress core updates
* plugin- en thema-updates
* pluginactivatie en -deactivatie
* themawissels

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
* uitsluitend HTTPS en een exact Pulse ingestiepad
* `wp_safe_remote_post()` en nul redirects, zodat een token niet naar een redirectdoel gaat
* maximaal 10 seconden configureerbare HTTP-timeout, standaard 3 seconden
* geen queue, cronjob, bezoekerstracking of telemetry
* geen cookies, authorization headers, request bodies, formulierdata of user-agent
* URL-querystrings worden verwijderd
* gevoelige contextsleutels en bekende secrets in tekst worden lokaal geredigeerd
* lokale backoff na netwerk-, limiet- en authenticatieproblemen
* uninstall verwijdert instellingen, status en backoff-data

