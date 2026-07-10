=== Codegenie Pulse Connector ===
Contributors: codegenie
Tags: monitoring, error monitoring, uptime, fatal errors, deployment tracking
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Verbind WordPress veilig met Codegenie Pulse voor websiteverificatie, applicatiefouten en deployment tracking.

== Description ==

Codegenie Pulse Connector maakt de koppeling tussen een WordPress-website en een Codegenie Pulse-account klein en veilig.

De plugin biedt:

* websiteverificatie via `/.well-known/codegenie-pulse.txt`;
* een eenvoudige DSN-koppeling met een verbindingstest;
* automatische rapportage van fatale PHP-fouten;
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

== External service ==

Deze plugin verstuurt gegevens naar de Codegenie Pulse-installatie waarvan een WordPress-beheerder bewust een DSN invoert. De host van die DSN verwerkt de gebeurtenissen volgens de voorwaarden en privacyverklaring van die Codegenie Pulse-installatie. Die documenten zijn beschikbaar op de platformhost via `/terms` en `/privacy`.

Er wordt geen verbinding met Codegenie Pulse gemaakt voordat een beheerder een DSN opslaat. De websiteverificatie-URL is een publiek tekstendpoint met uitsluitend het verificatietoken dat de beheerder invoert.

== Changelog ==

= 1.0.0 =

* Eerste productiegerichte release.
* DSN-koppeling en handmatige verbindingstest.
* Fatale PHP-fouten, WordPress mailfouten en REST 5xx-detectie.
* Websiteverificatie via een well-known endpoint.
* WordPress deployment tracking.
* Privacyredactie, versleutelde tokenopslag en lokale backoff.

