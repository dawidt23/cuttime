# CutTime — fundament techniczny Etapu 2

Kod wspólny jest używany przez [rejestrację](registration.md) i przygotowany do dalszych zadań. Nie implementuje jeszcze logowania, wylogowania, profilu ani paneli. Schemat i dane Etapu 1 pozostają bez zmian.

## Konfiguracja lokalna

1. Przygotuj PHP 8.2 lub nowsze z rozszerzeniami PDO, `pdo_mysql` i obsługą sesji oraz zaimportowaną bazę opisaną w [README](../README.md#import-bazy).
2. Skopiuj `config/database.example.php` do `config/database.local.php`. W PowerShell z katalogu projektu możesz użyć `Copy-Item config/database.example.php config/database.local.php`, jeżeli plik lokalny jeszcze nie istnieje.
3. W pliku lokalnym wpisz host, port jako liczbę, nazwę bazy oraz login i hasło lokalnego konta MySQL/MariaDB. Domyślną nazwą bazy jest `cuttime`. Konto aplikacji powinno mieć tylko potrzebne uprawnienia do tej bazy; konto importujące schemat może być inne.
4. `database.local.php` jest ignorowany przez Git. Nie wpisuj sekretów w `database.php` ani w pliki `*.example.*`. Plik `.env` nie jest automatycznie wczytywany.
5. W aktywnym `php.ini` serwera zastosuj ustawienia z `config/php.ini.example`, szczególnie `display_errors = Off` i `display_startup_errors = Off`. Ustaw prywatne, zapisywalne lokalizacje logów i sesji poza `public/`. Przykładowy plik INI nie ładuje się sam. Po zmianie konfiguracji uruchom ponownie serwer PHP.
6. Ustaw katalog dokumentów serwera na **`public/`**, aby `config/`, `includes/`, `database/`, `docs/` i `tests/` nie były udostępniane przez HTTP. Na docelowym serwerze używaj HTTPS. Przy reverse proxy serwer musi prawidłowo ustawiać `HTTPS`; kod nie ufa nagłówkom przekazywanym dowolnie przez klienta.

Do lokalnej pracy użyj `php -S 127.0.0.1:8000 -t public` po skonfigurowaniu PHP. Formularz jest dostępny pod `/register.php`; `/login.php` wyświetla tylko informację o planowanym logowaniu. Nie ma jeszcze strony startowej pod `/`.

## Jedno miejsce inicjalizacji

Każda przyszła strona w `public/` powinna zaczynać się od poniższego kodu, przed HTML i innym wyjściem:

```php
<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
```

Dla stron w podkatalogach dopasuj liczbę poziomów `dirname()`. Bootstrap ładuje helpery i uruchamia sesję; nie otwiera od razu połączenia z bazą. `db()` otwiera jedno połączenie PDO na żądanie i wykorzystuje je ponownie przy kolejnych wywołaniach.

## Dostęp do danych

Połączenie używa `utf8mb4`, `PDO::ERRMODE_EXCEPTION`, domyślnego `FETCH_ASSOC` i natywnych prepared statements (`ATTR_EMULATE_PREPARES = false`). Dane użytkownika zawsze przekazuj oddzielnie od SQL:

```php
$statement = db()->prepare('SELECT id, name FROM users WHERE email = :email');
$statement->execute(['email' => $email]);
$user = $statement->fetch();
```

Nie sklejaj danych formularzy z SQL. Parametry służą do wartości; nazwy kolumn i kierunek sortowania wymagają zamkniętej listy dozwolonych opcji. Brak lokalnego pliku lub błąd PDO trafia do wspólnej obsługi błędów; nie wypisuj `$error->getMessage()` do odpowiedzi.

## Helpery

| Funkcja | Przeznaczenie |
|---|---|
| `start_secure_session()` | Idempotentny start sesji; strict mode, tylko cookies, HttpOnly, SameSite=Lax, Secure przy HTTPS. |
| `e($text)` | `htmlspecialchars()` z ENT_QUOTES, ENT_SUBSTITUTE i UTF-8; dla tekstu HTML i atrybutów w cudzysłowach. |
| `redirect('/sciezka')` | Lokalne przekierowanie i zakończenie skryptu; domyślnie HTTP 303. Odrzuca adresy zewnętrzne i wstrzyknięcia nagłówków. |
| `flash('success', $message)` | Dodanie komunikatu; dozwolone typy to success, error i info. |
| `consume_flash()` | Jednorazowy odczyt i usunięcie komunikatów z sesji. |
| `current_user_id()` | Odczyt dodatniego ID typu int z sesji. |
| `current_user()` | Odczyt aktywnego konta z bazy bez hasha hasła; dla gościa zwraca null. |
| `is_logged_in()` | Sprawdzenie, czy sesja wskazuje istniejące, aktywne konto. |
| `current_user_role()` | Aktualna rola z bazy: client, employee, admin albo null. |
| `csrf_token()` | Losowy token formularza związany z sesją. |
| `csrf_is_valid($token)` | Sprawdzenie tokenu z odrzuceniem niepoprawnych typów danych. |

Przykład bezpiecznego wyświetlenia komunikatów w przyszłym widoku:

```php
foreach (consume_flash() as $message) {
    echo '<p>' . e($message['message']) . '</p>';
}
```

`e()` nie służy do kodu JavaScript, CSS, walidacji adresów URL ani parametrów SQL. Dane przechowuj w bazie bez escapowania HTML; zabezpieczaj je dopiero przy wyświetlaniu.

Helpery nie ustawiają zalogowanego użytkownika i nie zabezpieczają samodzielnie stron. Przy przyszłym logowaniu trzeba zweryfikować hasło przez `password_verify()`, sprawdzić aktywność, zregenerować ID sesji i dopiero wtedy przypisać `(int) $user['id']` do `$_SESSION['user_id']`. Rola nie może pochodzić z formularza. Kontrola uprawnień stron, wylogowanie i limity czasu sesji pozostają kolejnymi zadaniami. Odczyt konta każdorazowo sprawdza aktualny stan w bazie. Rejestracja korzysta już ze wspólnej ochrony CSRF; należy ją stosować również w przyszłych formularzach zmieniających dane.

## Obsługa błędów

`includes/errors.php` obsługuje nieprzechwycone wyjątki, ostrzeżenia PHP i błędy krytyczne po inicjalizacji. Odpowiedź otrzymuje HTTP 500 i ogólny komunikat bez PDO, SQL, ścieżek i konfiguracji. Buforowanie wyjścia HTTP pozwala usunąć niewysłaną treść przy błędzie.

Handler wyjątków zapisuje w logu jedynie klasę błędu, nazwę pliku i numer linii, bez treści wyjątku, argumentów i śladu stosu. Log PHP jest przeznaczony dla administratora serwera i musi być poza katalogiem publicznym.

Błąd składni strony głównej lub błąd startu PHP może wystąpić **przed uruchomieniem bootstrapu**. Dlatego ustawienia `php.ini` są wymagane; sam handler PHP nie może zabezpieczyć tego przypadku. Nie wysyłaj ręcznie bufora przed zakończeniem obsługi żądania.

## Sprawdzenie

W PowerShell, z katalogu projektu:

```powershell
Get-ChildItem config,includes,public,tests -Filter *.php -Recurse | ForEach-Object { php -l $_.FullName }
php tests/foundation.php
php tests/registration.php
```

Testy nie wymagają bazy ani lokalnego pliku dostępowego. Sprawdzają sesję, XSS, flash, niepoprawne ID, przekierowania oraz brak szczegółów błędów w odpowiedzi. Działają w procesach potomnych i katalogu tymczasowym, nie nadpisując konfiguracji użytkownika.

Po skonfigurowaniu bazy można dodatkowo wykonać lokalnie (bez tworzenia publicznej strony diagnostycznej):

```text
php -r "require 'includes/bootstrap.php'; db()->query('SELECT 1'); echo 'PDO OK';"
```

Nie wymaga to zmian danych. Tego połączenia nie zastępują testy bez serwera bazy.

Ustawienia oparto na dokumentacji PHP: [sesje](https://www.php.net/manual/en/session.security.ini.php), [PDO](https://www.php.net/manual/en/pdo.construct.php), [konfiguracja błędów](https://www.php.net/manual/en/errorfunc.configuration.php).
