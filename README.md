# Socialen

En fristående svensk publiceringsstudio för PHP 8.3 och MySQL/MariaDB, avsedd för ditt befintliga Inleed Prime #2-konto. Ingen Docker, Node, Redis eller extern kö behövs i drift. Ett vanligt cron-jobb kör publiceringen när webbläsaren är stängd.

## Det som finns

- Kalender med vecko-, månads- och listvy, sökning och kanalfilter.
- Utkast, redigering före påbörjad publicering och kopiering av befintliga inlägg.
- Bilduppladdning och MP4-video, mediebibliotek och förhandsvisning.
- Tidssättning i Europe/Stockholm eller UTC. Datum lagras i UTC. Ogiltiga eller tvetydiga tider vid sommar-/vintertidsbyte avvisas med ett tydligt fel.
- Inloggning för en egen administratör, sessionsskydd och CSRF-skydd.
- Krypterade plattformstoken, skilda från frontend och publika filer.
- Google OAuth och Meta OAuth; kanalidentiteter hämtas från plattformarna.
- YouTube-video med explicit synlighet och uppgift om innehållet är avsett för barn.
- Instagram: en bild, en Reel eller upp till tio bilder i en karusell. Professionellt Instagram-konto kopplat till Facebook-sida krävs i denna version.
- Facebook-sidor: text, en bild eller en video per inlägg.
- Cron-kö, sparade medie-/container-ID:n, återupptagbar YouTube-uppladdning och status per kanal.
- Manuell avstämning när ett muterande API-anrop kan ha lyckats trots att svaret saknas. Sådana inlägg publiceras inte automatiskt en gång till.

Det här är en första fungerande app, inte hela Metricools analys- och rapportprodukt. Anpassad text per nätverk, Stories, första kommentar, statistik, drag-and-drop-flyttning och fleranvändarroller ingår inte i denna version. API-adaptrarna är byggda mot dokumenterade kontrakt och testade med kontrollerade svar; verklig publicering till dina konton är inte verifierad ännu.

## Lokal förhandsvisning

```sh
PUBLICERA_DEMO=1 php -S 127.0.0.1:8098 -t public
```

Öppna `http://127.0.0.1:8098`. Demon använder en separat SQLite-databas i `storage/demo.sqlite`, är märkt som demo och får bara köras på loopback. Den skickar aldrig något till sociala medier. Demokanaler och exempelposter är inte anslutningar till dina verkliga konton.

## Installera på Inleed

### 1. Skapa en separat plats

Använd en ny subdomän, till exempel `social.dindomän.se`, med HTTPS. Lägg appen i en egen katalog och behåll befintliga webbplatser orörda.

**Endast innehållet i `public/` ska vara webbåtkomligt.** `config.php`, `src/`, `bin/`, `tests/` och `storage/` ska ligga utanför dokumentroten.

Två möjliga strukturer:

```text
/home/rondit/apps/publicera/
  config.php
  src/
  bin/
  storage/
  public/     ← subdomänens dokumentrot
```

Om DirectAdmin använder `public_html` kan du i en ny, separat domänkatalog lägga `src`, `bin`, `storage` och `config.php` bredvid `public_html`, och lägga innehållet från `public/` i `public_html/`. De relativa PHP-sökvägarna fungerar även där. Lägg aldrig hela projektet i en publik katalog. `.htaccess` är kompletterande skydd, inte en ersättning för rätt dokumentrot.

### 2. Skapa databas och konfiguration

Skapa en egen MariaDB-databas och databasanvändare i DirectAdmin. Använd `utf8mb4`. Användaren behöver rätt att skapa tabeller vid installation samt läsa/skriva appens data.

Kopiera `config.example.php` till `config.php` och fyll i:

- `url`: subdomänens fullständiga HTTPS-adress, utan avslutande snedstreck.
- `key`: resultatet från `php bin/key.php`. Bevara denna nyckel; den behövs för att läsa sparade plattformstoken.
- `database`: DSN, användarnamn och lösenord för den separata databasen.
- Google/Meta-uppgifterna kan fyllas i senare. Kalender och utkast fungerar innan du ansluter kanaler.

Kör via SSH i appkatalogen:

```sh
chmod 600 config.php
chmod 700 storage
bash bin/install-admin.sh
/usr/local/php81/bin/php bin/doctor.php
```

PHP-sökvägen ovan är den sökväg ditt konto rapporterade; den faktiska versionen rapporterades som PHP 8.3.37. Kontrollera att samma PHP-version och tillägg används för både webb och cron. Installation skapar tabeller utan att radera data och frågar efter administratörens namn, e-post och lösenord i den interaktiva terminalen. Bash-omslaget döljer lösenordet utan att kräva PHP-funktionen shell_exec, som Inleed blockerar. Uppgifterna skickas endast via standard input till PHP. Upprepad installation byter inte befintligt lösenord.

Tillägg: PDO MySQL, cURL, Sodium, mbstring, Fileinfo och GD. `storage` måste vara skrivbar för PHP-kontot. Appens filgräns är 100 MB som standard; PHP:s `upload_max_filesize`, `post_max_size` och webbserverns gränser kan vara lägre. Kontrollera dessa via PHP-inställningarna i DirectAdmin. Stora telefonbilder avvisas före avkodning om de inte ryms inom PHP:s minnesgräns. Bilder normaliseras till JPEG, orienteras efter EXIF när det finns stöd och skalas till högst 2048 pixlar på längsta sidan.

### 3. Koppla Google/YouTube

1. Skapa ett Google Cloud-projekt och aktivera YouTube Data API v3.
2. Skapa en OAuth-klient av typen webbapplikation och konfigurera samtyckesskärmen.
3. Registrera exakt callback-adress: `https://social.dindomän.se/oauth.php?provider=google`.
4. Lägg klient-ID/hemlighet i `config.php` och öppna **Kanaler → Anslut YouTube**.
5. Begärda scopes är `youtube.upload` och `youtube.readonly`.

Appen behöver offlineåtkomst/förnyelsetoken. Googles externa appar i Testing kan ge token som löper ut efter sju dagar för dessa scopes. Följ Googles publicerings-/verifieringsflöde för varaktig drift. YouTube-kvoter och innehållsregler gäller även för en egen app.

Källor: [OAuth för webbserver](https://developers.google.com/identity/protocols/oauth2/web-server), [tokenlivslängd](https://developers.google.com/identity/protocols/oauth2#expiration), [återupptagbara uppladdningar](https://developers.google.com/youtube/data/guides/using_resumable_upload_protocol), [videostatus](https://developers.google.com/youtube/data/reference/rest/v3/videos).

### 4. Koppla Meta/Facebook/Instagram

1. Skapa en Meta-app med ett lämpligt Facebook Login for Business-flöde.
2. Registrera exakt callback-adress: `https://social.dindomän.se/oauth.php?provider=meta`.
3. Ange app-ID och apphemlighet i `config.php`. Graph-version är explicit konfigurerbar; nuvarande standard är `v27.0`. Alternativt kör du `bash bin/configure-meta.sh APP_ID CONFIGURATION_ID` i den interaktiva SSH-terminalen. Skriptet frågar efter apphemligheten med dold inmatning, sparar en privat säkerhetskopia i `storage/` och behåller Google-, databas- och övriga inställningar. Hemligheten ska inte skrivas som argument eller klistras in i en chatt.
4. Om ditt loginflöde kräver ett konfigurations-ID, ange `login_config_id`. Konfigurationen ska använda **User access token**, eftersom appen därefter hämtar Page-token.
5. Begär/konfigurera `pages_show_list`, `pages_read_engagement`, `pages_manage_posts`, `instagram_basic`, `instagram_content_publish` och `business_management` för de funktioner du ska använda. `business_management` krävs för att Metas sidlista även ska innehålla sidor kopplade till en företagsportfolio. Följ Metas aktuella krav på appgranskning och åtkomstnivå för dina konton. Videobehörigheter kan kräva komplettering beroende på appens produkt/åtkomst; API-fel visas vid publiceringen.
6. Koppla ditt professionella Instagram-konto till en Facebook-sida du får publicera till.
7. Öppna **Kanaler → Anslut** och logga in med den personliga Facebook-profil som administrerar sidorna. Efter Meta-inloggningen visar Socialen en lista där du uttryckligen väljer Facebook-sidor och kopplade Instagram-konton. Inga nya konton är förvalda och inget sparas förrän du klickar **Anslut valda konton**. Valet gäller i tio minuter; token ligger krypterade i sessionen och skickas inte till webbläsaren. Avbryt eller hämta kontona igen vid behov. Listan omfattar den åtkomst Meta lämnar ut; saknade sidor behöver kontrolleras hos Meta. Vid fler än 1 000 konton behöver sidåtkomsten begränsas.

Page-token kan återkallas även om den saknar schemalagt utgångsdatum. Vid behörighetsfel ansluter du kanalen igen. En frånkoppling raderar den lokala åtkomsttoken och behåller kanalens publiceringshistorik; den återkallar inte appen hos Meta/Google.

Media ligger privat på servern. Vid publicering får Meta en separat svårgissad läsadress till den valda filen, så att plattformen kan hämta den. Den som har hela denna adress kan läsa just filen. Appens huvudsakliga inloggning exponeras inte. Filadressen är stabil för efterföljande hämtning; behandla den som en delbar länk.

Källor: [Facebook-inloggning](https://developers.facebook.com/documentation/facebook-login/guides/advanced/manual-flow), [långlivade token](https://developers.facebook.com/documentation/facebook-login/guides/access-tokens/get-long-lived), [Instagram-publicering](https://developers.facebook.com/documentation/instagram-platform/content-publishing), [Facebook-sidor](https://developers.facebook.com/documentation/pages-api/posts).

### 5. Aktivera cron

I DirectAdmin: **Advanced Features → Cron Jobs**. Exempel om appen finns på `/home/rondit/apps/publicera`:

```cron
* * * * * /usr/local/php81/bin/php /home/rondit/apps/publicera/bin/worker.php >> /home/rondit/apps/publicera/storage/worker.log 2>&1
```

Använd den riktiga absoluta sökvägen. Kontrollera vilket minsta intervall kontot tillåter. Arbetaren kör högst ungefär 45 sekunder, gör nätverksanrop med högst 20 sekunders tidsgräns, skickar videodata i 4 MB-delar och lämnar fortsättningen till nästa cron-körning. Den skriver bara rutinutdata när jobb behandlats. Rotera/flytta den privata loggen vid behov. Ingen webhook eller publik cron-hemlighet behövs.

Kör först `php bin/worker.php` manuellt och kontrollera **Kanaler → driftstatus**. Ett fillås förhindrar överlappande cron-körningar i den här enserverinstallationen. Starta inte separata installationer med samma databas och olika storage-kataloger. Plattformarnas bearbetning och cron-intervallet innebär att publiceringen inte garanteras på sekunden.

Källa: [Inleeds cron-guide](https://inleed.se/helpcenter/cron-jobbet-kors-inte).

## När något går fel

- **Misslyckat:** plattformen eller valideringen gav ett känt fel. Läs meddelandet, åtgärda orsaken och skapa vid behov en ny kopia.
- **Kontroll behövs:** ett API-anrop kan ha lyckats. Kontrollera kontot hos plattformen och använd **Stäm av resultatet**. Appen skickar inte automatiskt ett nytt inlägg. Vid bekräftad publicering sparar du plattformens inläggs-ID.
- **Väntar:** en video eller mediecontainer bearbetas, eller en återupptagbar uppladdning fortsätter nästa cron-körning.
- **Frånkopplad:** anslut kanalen igen; historiken behålls.

Publicering som redan genomförts tas inte bort om du avbryter återstående kanaler. Redigering låses när ett jobb har påbörjat plattformsanrop. Tokenförnyelse kan kräva ny inloggning hos plattformen. En videofil behöver följa respektive plattforms formatkrav; appen transkoderar inte video.

## Säkerhetskopior och uppdateringar

Säkerhetskopiera databasen, `storage/uploads` och `config.php` tillsammans. Utan rätt appnyckel går befintliga kanaltoken inte att dekryptera. Spara inte konfiguration, databaser, loggar eller uppladdningar i Git eller ett offentligt zip-arkiv. Vid koduppdatering behåll dessa filer, ta först backup och kör `bin/install.php` för idempotent schemahantering. Den första releasen har schemaversion 1; framtida schemaändringar behöver egna versionerade migreringar.

## Verifiering

```sh
php tests/run.php
python3 tests/http_flow.py
```

Testerna använder isolerad SQLite och kontrollerade API-svar. Samma flöden kan köras mot en **separat, disponibel MySQL-testdatabas** vars namn börjar med `publicera_test_` genom `PUBLICERA_TEST_DSN`, `PUBLICERA_TEST_USER` och vid behov `PUBLICERA_TEST_PASSWORD`. Använd aldrig produktionsdatabasen för tester.

Verifierat lokalt: PHP-syntax, JavaScript-syntax, 16 flödestester mot SQLite och MySQL 5.7, isolerade HTTP-flöden för inloggning, sessionbyte, CSRF, utkast, versionskonflikt, uppladdning, privata filer, publika byteintervall och utloggning samt sparande av utkast i webbläsaren. Testerna använder kontrollerade plattformssvar och styrker inte verklig plattformspublicering. Webbläsarens filväljare kunde inte verifieras med datorverktyget; själva uppladdnings-API:t är testat. På Inleed har MariaDB 11.4, databasens läsning/skrivning, administratörskonto, automatisk cron varje minut, giltig HTTPS och PHP-uppladdningsgräns 100 MB verifierats. Verkliga Google-/Meta-konton och plattformarnas hämtning av mediefiler återstår att verifiera. Ingen riktig publicering har utförts.


## Automatisk driftsättning från GitHub

Repo: [stefanrodin/socialen](https://github.com/stefanrodin/socialen).

`.github/workflows/deploy.yml` kör PHP-/JavaScript-syntax, SQLite- och MySQL-flöden, HTTP-tester samt test av driftsättning och återställning. Pull requests kör endast tester. En push till `main` eller en manuell körning på `main` driftsätter först efter godkända tester. GitHub Actions använder vanliga Ubuntu-runners i detta publika repo; inga köpta tillägg eller externa deploytjänster behövs.

GitHub CLI används vid första uppsättningen för att lagra `SOCIALEN_SSH_KEY` och `SOCIALEN_KNOWN_HOSTS` som Actions-hemligheter. SSH-nyckeln är separat från den lokala arbetsnyckeln. Servern binder den till ett fast deploykommando med `restrict,command=...`. Hostnyckeln låses till den tidigare verifierade SSH-servern; arbetsflödet accepterar inte nya hostnycklar automatiskt.

På Inleed används följande struktur:

```text
/home/rondit/domains/socialen.rondit.se/
  config.php                 # privat, behålls
  storage/                   # privat databasrelaterat tillstånd och uppladdningar, behålls
  deploy/                    # fast mottagarskript och domänspecifik .htaccess
  releases/<version>/        # separata kodversioner
  current -> releases/<version>
  public_html -> current/public
  src -> current/src
  bin -> current/bin
```

Paketeringen tar enbart med utvalda, committade applikationsfiler. Servern kontrollerar PHP-syntax och befintligt schema, väntar på publiceringsjobbets fillås och byter sedan `current` atomiskt. Kontroller av den serverade Git-revisionen och appens API måste lyckas. Vid kontrollfel återställs föregående pekare. Tidigare versioner och ursprungliga filer behålls som säkerhetskopior. Databas, konton, token, uppladdningar, HTTPS-regler och uppladdningsgränser skrivs inte över.

Automatisk deploy ändrar inte databasschemat. Denna version kräver schema 1. Framtida schemaändringar behöver en separat, granskningsbar migrering och databasbackup före driftsättning.

Actions-loggen visar sökvägen till föregående version. Manuell återställning via den vanliga SSH-inloggningen:

```sh
cd /home/rondit/domains/socialen.rondit.se
# Ersätt sökvägen med den verifierade föregående versionen från Actions-loggen.
ln -s /absolut/sökväg/till/föregående/version .current.restore
mv -Tf .current.restore current
```

Samordna manuell återställning med `deploy/deploy.lock` och `storage/worker.lock` om ett deploy- eller publiceringsjobb pågår. Kodåterställning ändrar inte databasen eller redan publicerade inlägg.

För första serveruppsättningen körs `bash deploy/bootstrap-inleed.sh` från en utpackad, verifierad Git-revision på Inleed. Skriptet behåller nuvarande kod som första version, säkerhetskopierar tidigare kataloger och lägger till den publika Actions-nyckeln. Den fasta servermottagaren uppdateras med samma bootstrap-skript vid framtida ändringar i deploykontraktet.

Engångsuppsättningen körs från kontots befintliga SSH-terminal. Endast den nya publika Actions-nyckeln installeras på servern. Den befintliga privata arbetsnyckeln behöver aldrig lämna datorn.

## Google, integritet och verifiering

Publika verifieringssidor: `about.php`, `privacy.php`, `terms.php` och `data-deletion.php`. De kan läsas utan inloggning och databasanslutning. Inloggningen och arbetsytan länkar till dem. Administratören godkänner aktuell policyversion aktivt innan funktionerna öppnas. Google-konfiguration och plattformstoken lämnas utanför Git.

Google-anslutningar kontrolleras även utan väntande publiceringar. Serverjobbet uppdaterar kanalidentitet och lagrade videoreferenser, återkallar och raderar vid frånkoppling och rensar Google-data efter bekräftat återkallad åtkomst eller när data inte kunnat hållas aktuell inom 30 dagar. En radering som tillfälligt inte kan återkallas hos Google pausas lokalt och försöks igen, högst i sju dagar. Egna inlägg och mediefiler tas inte bort; inlägg utan kvarvarande publiceringskoppling återgår till utkast. Videor på YouTube påverkas inte.

Driftansvarig hanterar begäran om konto-/filradering och radering i egna respektive webbhotellets säkerhetskopior. Sådana kopior får inte återinföra återkallad åtkomst. Kontrollera backuphanteringen med driftleverantören vid en raderingsbegäran.

För att ta bort testlägets sjudagarsgräns behöver Googles OAuth-app lämna Testing. Anslut kanalen igen efter statusbytet så att en ny förnyelsetoken hämtas. Brand- och eventuell scopeverifiering hanteras separat i Google Cloud; ett godkännande kan inte garanteras av dessa sidor.
