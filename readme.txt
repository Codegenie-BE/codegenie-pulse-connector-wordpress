=== Codegenie Pulse Connector ===
Contributors: codegenie
Tags: monitoring, error monitoring, uptime, fatal errors, deployment tracking
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Verbind WordPress veilig met Codegenie Pulse voor websiteverificatie, applicatiefouten en deployment tracking.

== Description ==

Codegenie Pulse Connector maakt de koppeling tussen een WordPress-website en een Codegenie Pulse-account klein en veilig.

De plugin biedt:

* websiteverificatie via `/.well-known/codegenie-pulse.txt`;
* een eenvoudige DSN-koppeling met een verbindingstest;
* instelbare PHP-foutcapture voor productie, uitgebreid gebruik en tijdelijke debug;
* automatische rapportage van fatale PHP-fouten en onverwerkte exceptions;
* optionele PHP warnings, notices, strict- en deprecated-meldingen;
* optionele rapportage van mislukte WordPress e-mails, zonder ontvangers of berichtinhoud;
* optionele rapportage van REST API serverfouten met status 5xx;
* deployment tracking voor WordPress-, plugin- en themawijzigingen;
* lokale verwijdering van bekende geheimen, e-mailadressen en URL-querystrings;
* versleutelde opslag van de DSN met WordPress salts en AES-256-GCM;
* token-veilige diagnose via WordPress Site Health.

De plugin heeft geen Composer-package, queue, cronjob of externe JavaScript nodig. Hij stuurt niets zolang een beheerder geen Codegenie Pulse DSN instelt.

== Installation ==

1. Upload de plugin-ZIP via `Plugins > Nieuwe plugin > Plugin uploaden`.
2. Activeer `Codegenie Pulse Connector`.
3. Open `Instellingen > Codegenie Pulse`.
4. Voeg je website toe in Codegenie Pulse en plak het websiteverificatietoken in WordPress.
5. Sla op en laat Codegenie Pulse de website verifiëren.
6. Maak in Codegenie Pulse een foutbron voor WordPress productie aan.
7. Kopieer de volledige DSN en plak die in WordPress.
8. Sla op en klik op `Verbinding testen`.

== Frequently Asked Questions ==

= Welke gegevens verstuurt de plugin? =

Voor fouten verstuurt de plugin een begrensde foutmelding, foutklasse, opgeschoond bestandspad, regelnummer, queryvrije URL, requestmethode, statuscode, een begrensde stacktrace en technische versies. Cookies, autorisatieheaders, formulierdata, request bodies en WordPress-gebruikers worden niet verstuurd.

= Waarom maakt de verbindingstest een event aan? =

Het bestaande Codegenie Pulse ingestie-endpoint bevestigt een koppeling door een geldig event te aanvaarden. De test gebruikt niveau `info` en telt als één verwerkt event binnen het plan.

= Kan de plugin op een lokale HTTP-omgeving worden gebruikt? =

Standaard niet. Productie-DSN's moeten HTTPS gebruiken en naar een veilige publieke URL wijzen. Ontwikkelaars kunnen de filter `codegenie_pulse_allow_insecure_dsn` uitsluitend in een gecontroleerde lokale omgeving activeren.

= Wat gebeurt er als WordPress salts wijzigen? =

De DSN kan dan niet meer worden ontsleuteld. Plak de DSN opnieuw in de connectorinstellingen.

= Worden events opnieuw geprobeerd? =

De plugin gebruikt geen wachtrij en herhaalt events niet automatisch. Na netwerk-, limiet- of tokenproblemen past hij wel een korte lokale backoff toe om een foutstorm te voorkomen.

= Welke PHP-foutcapturemodus moet ik kiezen? =

`Productie` is de aanbevolen standaard en rapporteert fatale fouten en onverwerkte exceptions. `Uitgebreid` voegt PHP warnings toe. `Debug` voegt ook notices, strict- en deprecated-meldingen toe en hoort alleen tijdelijk op staging of tijdens diagnose gebruikt te worden. Niet-fatale fouten worden alleen onderschept wanneer de PHP `error_reporting`-instelling ze activeert. `Uitgeschakeld` stopt alle automatische foutcapture, maar expliciete helper-calls blijven beschikbaar.

= Leest de plugin bestaande PHP-logbestanden? =

Nee. De plugin leest of tailt geen bestaand `debug.log`- of PHP `error_log`-bestand en importeert geen historische regels. Hij onderschept geselecteerde nieuwe PHP-fouten op het moment dat ze plaatsvinden. Handmatige `error_log()`-berichten worden niet automatisch onderschept.

= Hoe voorkomt de plugin een eventstorm? =

Identieke niet-fatale fouten worden standaard maximaal één keer per minuut verstuurd. Per request worden maximaal tien unieke niet-fatale fouten verzonden. Het bestaande PHP-errorhandler- en loggedrag blijft daarna behouden.

== External service ==

Deze plugin verstuurt gegevens naar de Codegenie Pulse-installatie waarvan een WordPress-beheerder bewust een DSN invoert. De host van die DSN verwerkt de gebeurtenissen volgens de voorwaarden en privacyverklaring van die Codegenie Pulse-installatie. Die documenten zijn beschikbaar op de platformhost via `/terms` en `/privacy`.

Er wordt geen verbinding met Codegenie Pulse gemaakt voordat een beheerder een DSN opslaat. De websiteverificatie-URL is een publiek tekstendpoint met uitsluitend het verificatietoken dat de beheerder invoert.

== Upgrade Notice ==

= 1.1.0 =

Voegt veilige optionele capture van PHP warnings, notices en deprecated-meldingen toe. Bestaande 1.0.0-instellingen worden automatisch behouden als Productie of Uitgeschakeld.

== Changelog ==

= 1.1.0 =

* Vier capturemodi toegevoegd: uitgeschakeld, productie, uitgebreid en debug.
* Veilige capture van PHP warnings, notices, strict- en deprecated-meldingen.
* Bestaande PHP error handlers blijven aangeroepen en normale PHP-logging blijft behouden.
* Deduplicatie van identieke niet-fatale fouten over requests heen.
* Limiet van tien unieke niet-fatale events per request, configureerbaar via een filter.
* Achterwaartse migratie van de 1.0.0-instelling voor automatische foutcapture.

= 1.0.0 =

* Eerste productiegerichte release.
* DSN-koppeling en handmatige verbindingstest.
* Fatale PHP-fouten, WordPress mailfouten en REST 5xx-detectie.
* Websiteverificatie via een well-known endpoint.
* WordPress deployment tracking.
* Privacyredactie, versleutelde tokenopslag en lokale backoff.
