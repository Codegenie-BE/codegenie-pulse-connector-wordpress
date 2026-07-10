=== Codegenie Pulse Connector ===
Contributors: codegenie
Tags: monitoring, error monitoring, uptime, fatal errors, deployment tracking
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Verbind WordPress veilig met Codegenie Pulse voor websiteverificatie, applicatiefouten en deployment tracking.

== Description ==

Codegenie Pulse Connector maakt de koppeling tussen een WordPress-website en een Codegenie Pulse-account klein en veilig.

De plugin biedt:

* automatische Pulse-first koppeling met expliciete toestemming van een WordPress-beheerder;
* een eenmalige, kortlevende autorisatie zonder permanente secrets in de browser-URL;
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

De plugin heeft geen Composer-package, queue, cronjob of externe JavaScript nodig. Site- en versiegegevens worden pas verstuurd nadat een WordPress-beheerder de koppeling bewust goedkeurt. Foutgebeurtenissen worden alleen verstuurd wanneer een geldige DSN beschikbaar is.

== Installation ==

1. Upload de plugin-ZIP via `Plugins > Nieuwe plugin > Plugin uploaden`.
2. Activeer `Codegenie Pulse Connector`.
3. Open Codegenie Pulse en kies `Website toevoegen > WordPress koppelen`.
4. Vul de publieke HTTPS-home-URL van de WordPress-site in.
5. Log indien nodig in op WordPress en keur de koppeling als beheerder goed.
6. Pulse stelt websiteverificatie en alle functies van het actieve abonnement automatisch in.

De bestaande verificatietoken- en DSN-velden blijven beschikbaar als handmatige fallback.

== Frequently Asked Questions ==

= Welke gegevens verstuurt de plugin? =

Voor fouten verstuurt de plugin een begrensde foutmelding, foutklasse, opgeschoond bestandspad, regelnummer, queryvrije URL, requestmethode, statuscode, een begrensde stacktrace en technische versies. Cookies, autorisatieheaders, formulierdata, request bodies en WordPress-gebruikers worden niet verstuurd.

= Waarom maakt de verbindingstest een event aan? =

Het bestaande Codegenie Pulse ingestie-endpoint bevestigt een koppeling door een geldig event te aanvaarden. De test gebruikt niveau `info` en telt als één verwerkt event binnen het plan.

= Kan de plugin op een lokale HTTP-omgeving worden gebruikt? =

Standaard niet. Productie-DSN's en automatische platformkoppelingen moeten HTTPS gebruiken en naar een veilige publieke URL wijzen. Ontwikkelaars kunnen de filters `codegenie_pulse_allow_insecure_dsn` en `codegenie_pulse_allow_insecure_platform_origin` uitsluitend in een gecontroleerde lokale omgeving activeren.

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

Deze plugin verstuurt gegevens naar de Codegenie Pulse-installatie die een WordPress-beheerder via de automatische koppeling goedkeurt of handmatig via een DSN configureert. Die installatie verwerkt de gebeurtenissen volgens de voorwaarden en privacyverklaring op de platformhost via `/terms` en `/privacy`.

Het publieke discovery-endpoint geeft alleen connectorinformatie en een eenmalige site proof terug aan de aanvragende Pulse-installatie. WordPress verstuurt site- en technische versies pas nadat een beheerder de koppeling bewust goedkeurt. De websiteverificatie-URL is een publiek tekstendpoint met uitsluitend het verificatietoken.

== Upgrade Notice ==

= 1.2.0 =

Voegt een veilige Pulse-first WordPress-koppeling toe die verificatie, foutmonitoring en deployment tracking automatisch instelt volgens het actieve abonnement.

= 1.1.0 =

Voegt veilige optionele capture van PHP warnings, notices en deprecated-meldingen toe. Bestaande 1.0.0-instellingen worden automatisch behouden als Productie of Uitgeschakeld.

== Changelog ==

= 1.2.0 =

* Automatische koppeling gestart vanuit Codegenie Pulse.
* Expliciet toestemmingsscherm voor WordPress-beheerders.
* Out-of-band site proof op basis van WordPress auth salts.
* Kortlevende eenmalige request tokens, zonder DSN of ingestiontoken in de browser-URL.
* Automatische websiteverificatie en plan-afhankelijke provisioning.
* Starter ondersteunt de websitekoppeling zonder fout-DSN; Pro en Agency krijgen automatisch foutmonitoring.
* Handmatige verificatietoken- en DSN-flow blijft als fallback beschikbaar.

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
