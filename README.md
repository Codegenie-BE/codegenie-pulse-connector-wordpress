# Codegenie Pulse Connector

Onderhoudbare bronrepository voor de WordPress-plugin Codegenie Pulse Connector. De runtime heeft geen Composer-dependencies, queue, cronjob, telemetry of extern JavaScript.

## Vaste identiteiten

Deze drie namen verschillen bewust en mogen niet als opschoonactie gelijkgetrokken worden:

| Contract | Waarde | Reden |
| --- | --- | --- |
| Git-repository | `codegenie-pulse-connector-wordpress` | Benoemt de WordPress-implementatie en het hostingproject. |
| WordPress-pluginmap, installatieslug en text domain | `codegenie-pulse-connector` | Stabiele WordPress-identiteit, installatiepad en vertaalnamespace. |
| Protocol connector-ID | `codegenie-pulse-connector-wordpress` | Stabiele identificatie in discovery- en autorisatiepayloads. |

De pluginversie is `1.2.1`, vereist WordPress `6.2+` en PHP `7.4+`, en wordt in CI tegen WordPress `6.2` en de publieke WordPress `7.0.2`-patch getest.

## Ontwikkeling en QA

```sh
composer install
composer qa
```

De QA-opdracht controleert alle PHP-syntax, PHP 7.4-compatibiliteit, WordPress Coding Standards, contracttests, versieconsistentie, repositoryhygiëne, bekende secretformaten en Composer-advisories. De echte WordPress-integratiematrix en de officiële Plugin Check draaien aanvullend in GitHub Actions.

Een installatiepakket wordt uitsluitend vanuit een schone commit gebouwd:

```sh
composer build
```

Het resultaat bestaat uit `dist/codegenie-pulse-connector-1.2.1.zip` voor WordPress en `dist/codegenie-pulse-connector-wordpress-1.2.1-source.zip` voor broncontrole, beide met een bestandsmanifest en SHA-256. De installatie-ZIP bevat exact één hoofdmap `codegenie-pulse-connector/`. Zie [CONTRIBUTING.md](CONTRIBUTING.md) en [docs/RELEASING.md](docs/RELEASING.md).

De afzonderlijke workflow `Prepare release artifacts` start alleen voor een expliciete versietag of een handmatige dry-run. Hij publiceert niets en gebruikt uitsluitend read-only repositoryrechten. Release notes staan in [docs/releases/1.2.1.md](docs/releases/1.2.1.md); de uitvoerbare WordPress.org-checklist en lokale SVN-mapping staan in [docs/WORDPRESS_ORG_RELEASE.md](docs/WORDPRESS_ORG_RELEASE.md).

## Runtimeoverzicht

WordPress-connector voor het bestaande Codegenie Pulse ingestiecontract.

Versie 1.2.1 ondersteunt een Pulse-first koppelingsflow. Een klant vult de WordPress-URL in Pulse in, keurt de koppeling in WordPress goed en krijgt verificatie, foutmonitoring en deployment tracking automatisch ingesteld volgens het actieve plan.

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

Voor automatisch koppelen gebruikt versie 1.2.1 aanvullend:

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

add_filter( 'codegenie_pulse_connection_timeout', function () {
    return 12.0;
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
add_filter( 'codegenie_pulse_allow_insecure_platform_origin', '__return_true' );
```

Gebruik deze filters nooit op productie.

Na een levering worden deze actions uitgevoerd zonder endpoint of token:

```php
add_action( 'codegenie_pulse_delivery_succeeded', function ( $kind, $status ) {
    // $kind is error of deployment.
}, 10, 2 );

add_action( 'codegenie_pulse_delivery_failed', function ( $kind, $status, $code ) {
    // Bevat uitsluitend een token-veilige foutcode.
}, 10, 3 );

add_action( 'codegenie_pulse_connector_loaded', function ( $plugin ) {
    // De connector is volledig samengesteld en geladen.
} );
```

## Compatibiliteit

* WordPress 6.2 of nieuwer
* getest tegen WordPress 7.0.2
* PHP 7.4 of nieuwer
* PHP OpenSSL met AES-256-GCM
* een publiek bereikbare HTTPS Codegenie Pulse-installatie

## Privacy- en securitygrenzen

* na beheerderstoestemming: site-URL, sitenaam, WordPress-versie, connectorversie, PHP-versie, omgevingstype, multisite-status en eenmalige technische autorisatiegegevens naar de expliciet gekozen Pulse-installatie
* geen inventaris van alle geïnstalleerde plugins of pluginversies tijdens de koppeling
* foutpayloads kunnen geredigeerde en begrensde melding-, klasse-, pad-, regel-, URL-, methode-, status-, stacktrace- en contextvelden bevatten
* deployment tracking kan wijzigingssoort, componentslug en versie versturen; bewust meegegeven aangepaste context kan na redactie worden verstuurd
* de sitebeheerder blijft verantwoordelijk voor transparantie; retentie en privacyrechten volgen de voorwaarden en privacyverklaring van de gekozen Pulse-installatie
* DSN versleuteld met een sleutel afgeleid van WordPress auth salts
* expliciete `manage_options`-toestemming en WordPress nonce voor automatisch koppelen
* een site proof die niet in de browserredirect staat
* geen permanente Pulse-secrets in browsergeschiedenis, querystrings of referrers
* uitsluitend HTTPS en een exact Pulse ingestiepad
* `wp_safe_remote_post()` en nul redirects, zodat een token niet naar een redirectdoel gaat
* maximaal 10 seconden configureerbare HTTP-timeout, standaard 3 seconden
* geen queue, cronjob, bezoekerstracking of telemetry
* geen cookies, authorization headers, binnenkomende request bodies, formulierdata, e-mailontvangers of berichtinhoud, of WordPress-gebruikers
* URL-querystrings worden verwijderd
* gevoelige contextsleutels en bekende secrets in tekst worden lokaal geredigeerd
* lokale backoff na netwerk-, limiet- en authenticatieproblemen
* sampling van identieke warnings en notices over requests heen
* maximaal tien unieke niet-fatale events per request, standaard
* bestaand PHP error handler- en loggedrag blijft behouden
* geen uitlezing of import van bestaande logbestanden
* uninstall verwijdert instellingen, status, backoff- en samplingdata
