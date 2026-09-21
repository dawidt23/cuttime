# CutTime

Projekt szkolny realizowany obecnie w zakresie **Etapu 1 — bazy danych i dokumentacji**. Repozytorium nie zawiera jeszcze działającej aplikacji internetowej.

## Autorzy

- Dawid Tomaszewski
- Tomasz Talacha

## Tematyka

Internetowy system rezerwacji wizyt w salonie fryzjerskim.

## Opis projektu

Docelowo CutTime umożliwi klientom przeglądanie usług i rezerwowanie wizyt u wybranego pracownika. Pracownicy będą mogli przeglądać terminarz, a administrator zarządzać ofertą salonu, personelem i rezerwacjami. Są to funkcje planowane na kolejne etapy.

## Technologie

Technologie przewidziane dla projektu:

- PHP
- HTML5
- CSS3
- JavaScript
- MySQL/MariaDB
- Git
- GitHub

W Etapie 1 przygotowano skrypt SQL oraz dokumentację Markdown z diagramem Mermaid. Pliki aplikacji PHP, HTML, CSS i JavaScript nie zostały jeszcze utworzone.

## Struktura projektu

Aktualne najważniejsze pliki i katalogi:

```text
CutTime/
├── .gitignore
├── README.md
├── config/
│   └── .gitkeep
├── database/
│   └── database.sql
├── docs/
│   └── erd.md
└── public/
    ├── css/
    │   └── .gitkeep
    ├── js/
    │   └── .gitkeep
    └── assets/
        └── .gitkeep
```

`public/` jest przeznaczony na przyszłe pliki dostępne przez serwer HTTP:
arkusze stylów w `css/`, skrypty w `js/` i grafiki oraz inne zasoby w `assets/`.
`config/` pozostaje poza katalogiem publicznym i jest przeznaczony na konfigurację
aplikacji. Pliki `.gitkeep` pozwalają Gitowi śledzić przygotowane, puste katalogi;
nie są elementami działającej aplikacji.

Lokalne hasła i klucze należy przechowywać w ignorowanym `.env` lub lokalnych
plikach konfiguracji, np. `config/database.php` albo `config/database.local.php`.
Ewentualny `.env.example` może zawierać wyłącznie przykładowe wartości bez sekretów.
`.gitignore` nie wykrywa sekretów w dowolnym pliku, dlatego przed dodaniem zmian
do Git należy sprawdzić ich zawartość. Kolejne etapy można rozwijać w przygotowanych
katalogach, aktualizując równocześnie dokumentację.

## Baza danych

Plik [database/database.sql](database/database.sql) definiuje bazę `cuttime`, przechowującą konta użytkowników, ofertę salonu, personel, tygodniową dostępność i rezerwacje.

| Tabela | Przeznaczenie |
|---|---|
| `users` | Konta klientów, pracowników i administratora; hashe haseł i role. |
| `service_categories` | Kategorie usług. |
| `services` | Usługi, ich kategorie, czas trwania w minutach i ceny. |
| `employees` | Profile pracowników powiązane z kontami użytkowników. |
| `employee_services` | Przypisania usług do pracowników. |
| `employee_availability` | Przedziały godzin pracy według dnia tygodnia: 1 — poniedziałek, 7 — niedziela. |
| `reservations` | Rezerwacje powiązane z użytkownikiem, pracownikiem i usługą, z datą, godzinami i statusem. |

Schemat zawiera klucze główne i obce, ograniczenia `UNIQUE`, `NOT NULL` i `CHECK` oraz indeksy wspierające wyszukiwanie rezerwacji i dostępności. Tabele korzystają z InnoDB i kodowania `utf8mb4`. Statusy rezerwacji to `pending`, `confirmed`, `completed` i `cancelled`.

## Relacje

Relacje 1:N (rekord nadrzędny może mieć zero lub wiele rekordów podrzędnych):

- `service_categories` → `services` — kategoria obejmuje usługi.
- `employees` → `employee_availability` — pracownik ma przedziały dostępności.
- `users` → `reservations` — użytkownik ma rezerwacje.
- `employees` → `reservations` — pracownik obsługuje rezerwacje.
- `services` → `reservations` — usługa występuje w rezerwacjach.
- `employees` → `employee_services` oraz `services` → `employee_services` — przypisania usług do pracowników.

Relacja **N:M pracownicy–usługi** jest realizowana przez `employee_services`. Złożony klucz główny `(employee_id, service_id)` zapobiega powielaniu tej samej pary.

Relacja `users` → `employees` ma liczność **1:0..1**: konto może mieć najwyżej jeden profil pracownika dzięki `UNIQUE` na `employees.user_id`.

## Diagram ERD

[Diagram ERD w składni Mermaid](docs/erd.md) przedstawia wszystkie tabele, kolumny, klucze i relacje. Plik można przeglądać na GitHubie.

## Uruchomienie projektu

Na obecnym etapie uruchomienie polega na przygotowaniu bazy i przeglądaniu jej danych. Nie ma jeszcze strony startowej, konfiguracji połączenia PHP z bazą ani formularza logowania.

1. Pobierz repozytorium lokalnie.
2. Przygotuj serwer MySQL **8.0.16 lub nowszy** albo MariaDB **10.2.1 lub nowszy**, z obsługą InnoDB i egzekwowaniem ograniczeń `CHECK`, zgodnie z wymaganiami skryptu SQL.
3. Uruchom serwer bazy danych i przygotuj konto z uprawnieniami do utworzenia bazy, tabel i wstawienia danych.
4. Zaimportuj `database/database.sql` według instrukcji poniżej.
5. Sprawdź tabele i dane w kliencie SQL lub narzędziu administracyjnym.

Docelowa aplikacja będzie wymagała również środowiska PHP i serwera HTTP obsługującego PHP. Obecny Etap 1 nie wymaga uruchamiania PHP. Można użyć np. XAMPP z odpowiednią wersją bazy lub osobno zainstalowanych komponentów; projekt nie wymaga konkretnego pakietu.

## Import bazy

`database/database.sql` jest kompletnym skryptem przeznaczonym do utworzenia od zera struktury, relacji, ograniczeń, indeksów i danych testowych. Zawiera `CREATE DATABASE IF NOT EXISTS cuttime` oraz `USE cuttime`, więc nie trzeba wcześniej ręcznie tworzyć bazy.

Pełny import wykonaj jednorazowo, gdy baza `cuttime` jeszcze nie istnieje albo jest pusta. Skrypt nie jest migracją ani skryptem do wielokrotnego uruchamiania: ponowny import do wypełnionej bazy spowoduje błędy istniejących tabel lub powielonych danych. Nie usuwa istniejących tabel ani danych.

W terminalu, z głównego katalogu repozytorium, uruchom klienta bazy, zastępując `NAZWA_UZYTKOWNIKA` nazwą lokalnego użytkownika serwera:

```text
mysql --default-character-set=utf8mb4 -u NAZWA_UZYTKOWNIKA -p
```

Jeżeli klient MariaDB jest dostępny pod nazwą `mariadb`, użyj jej zamiast `mysql`. Podaj hasło lokalnego konta serwera bazy, a następnie w konsoli klienta wykonaj:

```sql
SOURCE database/database.sql;
SHOW TABLES FROM cuttime;
SELECT role, COUNT(*) AS liczba_kont FROM cuttime.users GROUP BY role;
SELECT COUNT(*) AS liczba_rezerwacji FROM cuttime.reservations;
```

Oczekiwany wynik po pełnym imporcie: 7 tabel, 1 administrator, 2 pracowników, 2 klientów oraz 8 rezerwacji. Alternatywnie można zaimportować cały plik przez funkcję importu narzędzia administracyjnego, np. phpMyAdmin.

Skrypt i powiązania danych sprawdzono statycznie. Import na działającym MySQL/MariaDB pozostaje do lokalnego potwierdzenia.

## Dane testowe

Poniższe konta istnieją w danych SQL. Będą służyć do testowania logowania po jego implementacji; obecnie nie ma interfejsu, w którym można się zalogować. Są to konta aplikacji, a nie konta dostępu do serwera MySQL/MariaDB.

| Rola | Imię i nazwisko | E-mail | Hasło testowe |
|---|---|---|---|
| Administrator | Aleksandra Nowak | `admin@cuttime.test` | `password` |
| Pracownik | Anna Kowalska | `anna.kowalska@cuttime.test` | `password` |
| Pracownik | Piotr Wiśniewski | `piotr.wisniewski@cuttime.test` | `password` |
| Klient | Maria Zielińska | `maria.zielinska@cuttime.test` | `password` |
| Klient | Jan Wójcik | `jan.wojcik@cuttime.test` | `password` |

Hasło odpowiada komentarzowi w `database/database.sql`. W rekordach użytkowników zapisano hash bcrypt zgodny z PHP `password_verify()`, a nie jawne hasło. Konta są przeznaczone wyłącznie do testów.

Dane przykładowe obejmują również 4 kategorie, 6 usług, 2 profile pracowników, 7 przypisań usług, 10 przedziałów dostępności i 8 rezerwacji. Anna pracuje od poniedziałku do piątku w godzinach 09:00–17:00, a Piotr 10:00–18:00. Rezerwacje mają stałe daty z września 2026 roku i nie przesuwają się automatycznie wraz z bieżącą datą.

## Aktualny stan projektu

Obecnie realizowany jest wyłącznie Etap 1. Przygotowano:

- schemat bazy z 7 tabelami, relacjami 1:N i N:M oraz ograniczeniami integralności;
- indeksy dla terminarza pracownika, wizyt klienta, statusów rezerwacji i dostępności;
- powiązane dane testowe z hashami haseł;
- diagram ERD zgodny ze schematem;
- dokumentację i instrukcję importu.

Nie zaimplementowano jeszcze logowania, rejestracji, paneli klienta, pracownika i administratora ani procesu tworzenia rezerwacji.

Obecne FK zapewniają istnienie powiązanych rekordów, ale nie wymuszają roli klienta lub pracownika, aktywności, przypisania usługi do pracownika, zgodności rezerwacji z dostępnością ani braku nakładających się terminów. Reguły te wymagają obsługi przy implementacji procesu rezerwacji w kolejnym etapie.

## Funkcje dodatkowe

Nie wykonano jeszcze dodatkowych funkcji aplikacyjnych. Będą rozwijane w kolejnych etapach projektu.
