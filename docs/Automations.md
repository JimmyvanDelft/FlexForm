# FlexForm-automatiseringen op REL1_43

Voer na installatie van de branch de normale schema-update uit met `php maintenance/run.php update`. FlexForm gebruikt op deze branch MySQL/MariaDB. De tabellen voor pagina's, revisies en `flexform_event` moeten transacties ondersteunen (InnoDB). De optie staat standaard uit. Laad de voorbeeldconfiguratie na FlexForm in LocalSettings.php om haar te activeren.

```php
wfLoadExtension( 'FlexForm' );
require "$IP/extensions/FlexForm/docs/AutomateOptions.php";
```

Pas voor een permanente installatie een eigen kopie van [AutomateOptions.php](AutomateOptions.php) aan. Het voorbeeld gebruikt de MinFin-slotrollen `csp-class-props` en `csp-base-props`. Registreer deze rollen met het wikitextmodel via de bestaande slotconfiguratie. De voorbeelden veronderstellen dat de templates en bronparameters op de wiki bestaan.

## Een reviewregel instellen

1. Open **Speciaal:FlexForm → Automatiseringen**. Sysops krijgen standaard `flexform-manage-automations`. Aanvullende groepen kunnen dat recht krijgen via `$wgGroupPermissions`.
2. Geef de regel een naam en kies **Titel begint met** en `Task/`, of **Reguliere expressie** en `^Task/[0-9]+$`. Test met `Task/123`. De vergelijking gebruikt de genormaliseerde titel inclusief namespace, met spaties en zonder fragment. Geef regex zonder delimiters op. FlexForm beperkt lengte, backtracking en recursiediepte.
3. Kies slot `csp-class-props`, template `Csp class properties`, parameter `Status`, vergelijking **Gelijk aan**, waarde `Code Review`. Gebruik de exacte parameternaam en één exemplaar van de template in dat slot.
4. Voeg **Taak aanmaken** toe, vul weergavetitel en taaktype in en kies voor het project `source` of `fixed`. Sla de regel op. Voor een archiefregel kies je de gewenste status en **Archiveren**. **Parameter instellen** laat alleen de geconfigureerde parameters en tekstwaarden toe.
5. Open een bestaande regel om acties te herschikken, de regel uit te schakelen of te verwijderen. Het main-slot van `Project:FlexForm/Automations.json` gebruikt het JSON-contentmodel met `schemaVersion` en `rules`. De geschiedenis toont de beheerwijzigingen. Bij een revisieconflict moet de beheerder de actuele versie openen.

## Eigen opties

Elke sleutel in `AutomateOptions` is een blijvende optie-ID. `label` bepaalt de naam in het formulier. `fixed` bevat installatievaste waarden. `fields` specificeert per invulveld een optioneel label en een optionele lijst `choices`.

* `set` gebruikt `slot`, `template`, `parameter` en `value`. De installatie geeft met `parameters` de schrijfbare parameters op. Een ontbrekende parameter wordt toegevoegd binnen de bestaande template.
* `create` gebruikt `title` en een map `slots` van slotnaam naar inhoud. Gebruik `$veldnaam` voor vaste of ingevulde waarden. De titel ondersteunt ook `$event` (uniek per actie) en `$revision`. Een bestaande titel veroorzaakt een zichtbare fout. `inherit` koppelt een waarde aan een bronparameter via slot, template en parameter. Het bijbehorende `mode`-veld kiest tussen `source` en `fixed`. Ingevulde waarden zijn platte tekst. Wikitextstructuur staat in de installatieconfiguratie.

## Opslag en uitvoering

Alleen het FlexForm-pad voor `_edit` beoordeelt regels. Losse wiki-edits, imports, normale API-edits en pagina-aanmaak starten geen keten. Iedere werkelijke doelpagina wordt afzonderlijk beoordeeld, ook vanuit een dashboard. Bij add-as-job gebeurt dat zodra de oorspronkelijke editjob draait. Een wijziging tussen inlezen en opslaan geeft een conflict. Zet tijdens een upgrade bestaande editjobs bij voorkeur eerst door, omdat oude jobberichten geen onderscheid maakten tussen een afwezig en een leeg veld.

De actie gebruikt de aangemelde bronbewerker. Rechten op de doelpagina worden bij het schrijven gecontroleerd. Regels lezen één momentopname vóór automatiseringsacties. Acties binnen dezelfde pagina volgen de ingestelde volgorde en delen de bronrevisie. Aanmaakacties leggen de ingevulde slotinhoud vast in dezelfde databasetransactie als de bronrevisie. De jobqueue ontvangt ze na commit. Onafhankelijke jobs kunnen op afzonderlijke workers draaien. De worker vergrendelt het event en commit de nieuwe pagina en het voltooiingskenmerk samen. De bewerkingssamenvatting bevat bronrevisie, regel-ID en actiepositie.

## Wachtrij en capaciteit

Laat de reguliere MediaWiki-jobrunner zowel `FlexFormEdit` als `FlexFormAutomation` verwerken. Draai periodiek `php maintenance/run.php FlexForm:requeueAutomations` om achtergebleven pending-events ouder dan vijf minuten opnieuw aan te bieden. Dubbele aanbiedingen zijn toegestaan. De beheerpagina toont de laatste 50 fouten. Herstel de oorzaak en gebruik `--failed EVENT_ID` voor een gerichte herpoging. De worker gebruikt de vastgelegde opdracht.

Meet gedurende representatieve piekbelasting, met de gebruikelijke workers, slots en SMW-instellingen. Onderstaande MySQL-queries gebruiken seconden en een venster van één uur. Pas bij een tabelprefix de tabelnaam aan.

```sql
SELECT COUNT(*) AS completed_per_hour,
       AVG(started_at - queued_at) AS avg_wait_seconds,
       MAX(started_at - queued_at) AS max_wait_seconds,
       AVG(finished_at - started_at) AS avg_execution_seconds
FROM flexform_event
WHERE state = 'done' AND finished_at >= UNIX_TIMESTAMP() - 3600;

SELECT state, COUNT(*) AS events, MIN(queued_at) AS oldest_enqueue_epoch
FROM flexform_event WHERE state IN ('pending', 'failed') GROUP BY state;
```

250.000 vervolgacties per 30 dagen vergen gemiddeld 347,2 voltooide acties per uur. Vergelijk de instroom, de gerealiseerde doorvoer en de oudste wachtende actie tijdens pieken en tijdens het wegwerken van de achterstand. Bewaar event-ID's zolang taken opnieuw aangeboden kunnen worden.

## Tests

`php extensions/FlexForm/tests/automationRules.php` controleert condities, selectors en optievalidatie. Gebruik voor de integratietest een lege, wegwerpbare wiki met de voorbeeldconfiguratie, bijgewerkt schema en een sysop `TestAdmin`. Voer vanaf de MediaWiki-root uit:

```sh
php maintenance/run.php "$PWD/extensions/FlexForm/tests/runAutomations.php" --disposable-wiki
```

Deze test schrijft configuratie, pagina's en jobs in de testdatabase.
