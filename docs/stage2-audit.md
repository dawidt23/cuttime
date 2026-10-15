# Audyt Etapu 2 — 15.10.2026

Zakres: rejestracja, logowanie, sesje, wylogowanie, role, kontrola dostępu, profil i edycja własnych danych. Sprawdzono bieżący kod aplikacji, SQL, konfigurację, dokumentację, testy oraz widoki. Nie dodano funkcjonalności Etapu 3.

## Znalezione i poprawione problemy

- Rejestracja uznawała każdy flash typu `success` za udane utworzenie konta i ukrywała formularz. Przykład: logowanie, a potem bezpośrednie wejście na rejestrację przed odczytaniem komunikatu logowania. Teraz wyłącznie udany INSERT kieruje do logowania z odpowiednim komunikatem; inne komunikaty nie zmieniają formularza.
- Rejestracja i logowanie wyświetlały wszystkie komunikaty flash jako sukces. Jeden wspólny widok rozróżnia błąd, sukces i informację oraz zawsze zabezpiecza treść przez `e()`.
- Nagłówki HTML i fragmenty nawigacji powtarzały się między widokami. Wydzielono `html_response_headers()`, `includes/flash-view.php` i `includes/account-navigation.php`. Profil i edycja mają teraz także bezpośrednie wylogowanie przez POST z CSRF.
- Formularze miały pola z tekstem 15 px, drobne podpowiedzi i nagłówek h1 ukrywany na telefonie razem z hasłem reklamowym. Teraz formularz ma własny widoczny h1, pola 16 px, większe podpowiedzi, a podsumowanie błędów otrzymuje fokus po odpowiedzi serwera. Nawigacja konta ma elementy wysokości co najmniej 44 px; tytuły ról i długie dane mieszczą się w wąskim widoku.
- Kontrole HTTP z poprzednich zadań nie miały własnego skryptu w repozytorium. Dodano `tests/http.php`, który odtwarza aplikację w izolacji i sprawdza wszystkie sześć chronionych stron.

Nie znaleziono nieparametryzowanych zapytań z danymi użytkownika, niechronionej strony z danymi konta ani wyświetlania tych danych bez escapowania. Istniejące zabezpieczenia PDO, sesji, autoryzacji, CSRF i walidacji pozostawiono.

## Wymagania oceny 5

| Wymaganie | Wynik i dowód |
|---|---|
| Prepared statements | Spełnione. Wszystkie 7 zapytań aplikacji używa `prepare()` i `execute()`: 2 w `auth.php`, 2 w `registration.php`, 3 w `profile.php`. Brak konkatenacji danych formularza z SQL. Wspólne PDO używa utf8mb4, wyjątków i natywnych prepared statements. |
| Ochrona wszystkich podstron | Spełnione. Wszystkie 6 stron sprawdza PHP przed HTML; testy HTTP obejmują całą macierz ról i wejście gościa przez GET/POST. |
| Odporność formularzy | Spełnione. PHP sprawdza wymagane pola, białe znaki Unicode, typy, format e-maila i telefonu, długości, hasła, duplikaty i nieprawidłowy UTF-8. Ograniczenia HTML nie zastępują PHP. |
| Bezpieczne błędy | Spełnione przy wymaganej konfiguracji PHP. Wspólny handler usuwa bufor i zwraca ogólny HTTP 500; komunikaty nie zawierają SQL, PDO, stosu, ścieżek ani konfiguracji. Testy obejmują błędy PDO, ostrzeżenia, konfigurację i buforowanie. |
| Ochrona XSS | Spełnione. Dane z formularza, konta i bazy oraz komunikaty przechodzą przy wyświetlaniu przez `e()` z `htmlspecialchars()`. Testy obejmują odbity i zapisany HTML/JavaScript oraz wstrzyknięcie atrybutu. Hasła nie wracają do HTML. |
| Wspólny kod | Spełnione. Jedno PDO, jedno natywne `session_start()`, wspólny moduł autoryzacji, `redirect()`, flash, `e()` i walidacja danych rejestracji/profilu. Wspólne widoki nie zastępują kontroli dostępu w PHP. |
| Czytelny interfejs | Spełnione. Spójne formularze, komunikaty przy polach i podsumowanie, nawigacja, fokus oraz responsywne CSS. Sprawdzono wszystkie widoki przy 1280, 390 i 320 px. |
| Celowo błędne dane | Spełnione. Testy odrzucają wadliwe dane i CSRF, blokują cudze role/URL oraz ignorują ID, rolę i aktywność z GET/POST. Błędne formularze nie zmieniają rekordów. |

Niższe wymagania Etapu 2 również są obecne: zapis rejestracji do istniejących `users`, `password_hash()`, `password_verify()`, trzy rzeczywiste role (`client`, `employee`, `admin`), sesja z ID i rolą, wylogowanie, profil i edycja własnych danych.

Po logowaniu wykonywane jest `session_regenerate_id(true)`. Sesja nie zawiera hasła ani hasha. Bieżąca rola i aktywność pochodzą z bazy. Wylogowanie czyści dane, cookie i zapis sesji; stare cookie nie przywraca dostępu. Edycja korzysta wyłącznie z ID sesji i stałej listy czterech pól, a kontrola duplikatu e-maila pomija bieżącego użytkownika.

## Mapa stron

| Strona | Dostęp |
|---|---|
| `/register.php` | Publiczny formularz, POST z CSRF; zawsze tworzy `client`. |
| `/login.php` | Publiczny formularz; zalogowany trafia do swojej części. |
| `/client.php` | Wyłącznie aktywny `client`. |
| `/employee.php` | Wyłącznie aktywny `employee`. |
| `/admin.php` | Wyłącznie aktywny `admin`; tylko demonstracja roli. |
| `/account.php` | Wszystkie trzy role. |
| `/profile.php`, `/profile-edit.php` | Wszystkie trzy role, wyłącznie własne konto. |
| `/logout.php` | Akcja własnej sesji: POST z poprawnym CSRF, bez konieczności odczytu bazy. |

Pliki `includes/`, `config/`, SQL i testy pozostają poza katalogiem publicznym. Serwer musi udostępniać wyłącznie `public/`. Błędy sprzed uruchomienia bootstrapu wymagają `display_errors=Off` i `display_startup_errors=Off` w aktywnym php.ini, zgodnie z [instrukcją konfiguracji](php-foundation.md). Nie są to ustawienia włączane automatycznie przez przykładowy plik INI.

## Wyniki

Środowisko: PHP 8.5.11, MariaDB 11.4.5, odizolowana baza i kopia aplikacji.

- 85 testów PHP: fundament 17, rejestracja 16, logowanie 13, autoryzacja 26, profil 13.
- 113 kontroli HTTP: role i URL, gość, sesja, błędne formularze, CSRF, limity, rejestracja i przekierowanie, hashowanie, własność profilu, XSS, wylogowanie i bezpieczne błędy konfiguracji.
- Edge: 8 widoków przy 3 szerokościach, bez przewijania w poziomie; widoczny nagłówek h1, pola co najmniej 16 px i wygodna nawigacja. Pusty formularz rejestracji pokazuje 6 błędów i ustawia fokus na podsumowaniu. Rzeczywisty powrót do profilu przez historię po wylogowaniu prowadzi do logowania, bez danych konta.
- Składnia wszystkich 30 plików PHP poprawna.
- Nie znaleziono sekretów w bieżących plikach repozytorium. Konfiguracja przykładowa nie zawiera danych dostępowych; `.gitignore` obejmuje `.env`, konfigurację lokalną, klucze i logi. Hasła demonstracyjne z SQL/README są jawnie oznaczonymi danymi testowymi.

Nie zmieniono SQL, ERD, danych testowych, połączenia PDO ani lokalnej konfiguracji użytkownika. Nie wykonano commitów, pushowania ani merge.

## Powtarzanie testów

```text
php tests/foundation.php
php tests/registration.php
php tests/login.php
php tests/authorization.php
php tests/profile.php
php tests/http.php
```

Testy bez bazy działają od razu. Pełny zestaw wymaga `pdo_mysql`, `proc_open()` oraz ustawionych lokalnie `CUTTIME_TEST_DSN`, `CUTTIME_TEST_USER`, `CUTTIME_TEST_PASSWORD`. Przykładowy DSN: `mysql:host=127.0.0.1;port=3306;dbname=cuttime;charset=utf8mb4`. Użyj serwera testowego i nie zapisuj rzeczywistych haseł w repozytorium.

Dotychczasowe testy PHP korzystają z tabel tymczasowych i wymagają `CREATE TEMPORARY TABLES`. Nowy test HTTP dodatkowo wymaga możliwości tworzenia, wypełniania i usuwania własnych schematów `cuttime_http_*`. Generuje losową nazwę, importuje SQL wyłącznie do niej, kopiuje kod do katalogu tymczasowego i tworzy tam konfigurację oraz sesje. Nie czyta `config/database.local.php` użytkownika. Uruchamia PHP na wolnym porcie loopback; na końcu zatrzymuje serwer, usuwa własny schemat i pliki tymczasowe, także po nieudanej asercji. Stały SQL testów i nazwa schematu generowana przez test nie zawierają danych żądań HTTP.

Brak DSN daje wyraźny komunikat `SKIP`; taki przebieg nie zastępuje pełnego testu bazy i HTTP.
