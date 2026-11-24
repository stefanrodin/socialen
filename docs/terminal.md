# Socialen via SSH

Terminalstödet använder den befintliga SSH-inloggningen. Inga nya publika API:er, konton eller nycklar behövs. Plattformstoken och databasuppgifter stannar i Socialens privata konfiguration. Det vanliga cron-jobbet publicerar fortfarande kön; administrationskommandot anropar inte plattformarnas publicerings-API:er.

## Anslutning

Kopiera klienten `bin/socialen.py` till din arbetsdator och skapa en lokal JSON-fil enligt `docs/socialen-ssh.example.json`. Ange den befintliga SSH-nyckelns **sökväg**, aldrig dess innehåll. Serverns värdnyckel måste redan vara verifierad i `known_hosts`. Klienten använder `StrictHostKeyChecking=yes`, `BatchMode=yes` och inget automatiskt återförsök av ändringsanrop.

```sh
python3 bin/socialen.py --config /sökväg/socialen-terminal.json health
python3 bin/socialen.py --config /sökväg/socialen-terminal.json channels
python3 bin/socialen.py --config /sökväg/socialen-terminal.json posts --network facebook --status scheduled
python3 bin/socialen.py --config /sökväg/socialen-terminal.json show 12
```

`posts` returnerar högst 100 poster som standard, högst 500 per sida. Använd `next_before_id` som `--before-id` för nästa sida när `has_more` är sant. Datumet `scheduled_at` är UTC; `scheduled_local` visar tiden i postens tidszon. `version` behövs vid ändring av befintliga poster.

## Ladda upp

```sh
python3 bin/socialen.py --config /sökväg/socialen-terminal.json upload /sökväg/film.mp4
python3 bin/socialen.py --config /sökväg/socialen-terminal.json upload /sökväg/film.mp4 --apply
```

Utan `--apply` görs endast en metadataförhandskontroll mot Socialens filgräns; filens innehåll överförs inte och typen verifieras inte fullständigt. Med `--apply` strömmas filen genom SSH, SHA256 verifieras och samma typ-/bildvalidering används som i webbgränssnittet. Appens befintliga filgräns gäller även här. MP4-filer ändras inte; bilder normaliseras som i det vanliga flödet. Uppladdning schemalägger eller publicerar ingenting.

Ett kvitto kopplar originalfilens SHA256 till medie-ID:t. Samma fil återanvänds vid ett nytt försök. Befintliga oförändrade filer från webbuppladdningar kan också återanvändas genom innehållsjämförelse. Tillämpad uppladdning returnerar endast säker mediemetadata, aldrig privata lagringsvägar eller delningsnycklar.

## Skapa utkast eller schema

Spara ett UTF-8-manifest, till exempel `post.json`. Kanal- och medie-ID:n hämtas med `channels` respektive `media`/`upload`.

```json
{
  "title": "Bakgårdsfesten – Blängsylta",
  "caption": "Exakt godkänd svensk inläggstext.",
  "channel_ids": [3],
  "media_ids": [12],
  "status": "draft",
  "timezone": "Europe/Stockholm",
  "scheduled_at": "2026-12-10T19:24",
  "visibility": "private",
  "made_for_kids": ""
}
```

```sh
python3 bin/socialen.py --config /sökväg/socialen-terminal.json save post.json
python3 bin/socialen.py --config /sökväg/socialen-terminal.json save post.json --apply
```

Förhandsgranskningen kör Socialens riktiga postvalidering och lämnar poster och publiceringskö oförändrade. Sätt `status` till `scheduled` för ett godkänt schema. YouTube behöver en uttrycklig synlighet och `made_for_kids` satt till `yes` eller `no`. För uppdatering via manifest ska både `id` och den aktuella `version` finnas med; ett fullständigt manifest ersätter hela postens innehåll.

Klienten räknar fram ett stabilt request-ID från den exakta begäran. Använd samma manifest och samma request-ID när ett svar har försvunnit. Ett upprepat sparande returnerar det befintliga resultatet. Samma request-ID med annat innehåll avvisas. Posten och kvittot sparas i samma transaktion. Detta skyddar återförsök av samma begäran, men ersätter inte en redaktionell dubblettkontroll av samma film med olika texter eller tider. Ange bara ett eget `--request-id` om en separat, uttryckligt godkänd avsikt behöver skiljas från den tidigare.

## Ändra endast tid eller återgå till utkast

```sh
python3 bin/socialen.py --config /sökväg/socialen-terminal.json reschedule 12 --version 4 --at 2026-12-10T20:17
python3 bin/socialen.py --config /sökväg/socialen-terminal.json reschedule 12 --version 4 --at 2026-12-10T20:17 --apply
python3 bin/socialen.py --config /sökväg/socialen-terminal.json draft 12 --version 5 --apply
```

`reschedule` bevarar text, mediefiler, kanaler och YouTube-inställningar. Versionen kontrolleras under databaslås. Samtidiga ändringar och redan påbörjad publicering avvisas enligt samma regler som i gränssnittet. Ogiltiga eller tvetydiga tider vid sommartidsbyte avvisas. Läs alltid tillbaka med `show ID` och kontrollera det exakta resultatet.

## Serverinstallation och gränser

Serverkommandot ligger i `bin/admin.php`, utanför webbens dokumentrot. Endast PHP CLI får köra det. Första raden på stdin är ett JSON-objekt, högst 256 KiB. Vid tillämpad uppladdning följer exakt det angivna antalet binära byte. Ett privat fillås samordnar CLI-ändringar. Befintliga `settings` används för kvitton; ingen schemamigrering behövs. En befintlig administratör och redan godkänd aktuell policy krävs. Kommandot accepterar aldrig policyn automatiskt och har inget kommando för att läsa token, ändra behörigheter eller starta publiceringsjobbet.

Fältet `actor_id` motsvarar appens befintliga administratör. Ägare av SSH-kontot har redan åtkomst till den privata konfigurationen och utgör behörighetsgränsen. Installera inte kommandot under en ny delad/publik SSH-nyckel utan ett separat beslut om åtkomst.

Testerna `python3 tests/admin_flow.py` använder en disponibel SQLite-databas och en lokal SSH-ersättare. De täcker förhandsgranskning, kvitton och återförsök, versionskonflikter, påbörjade jobb, avbrutna filöverföringar, SHA256, återanvändning och klientens hantering av text. Inga verkliga plattformar anropas av testerna.
