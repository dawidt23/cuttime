# Role i kontrola dostępu — Etap 2

Wartości `users.role` w aktualnym SQL to dokładnie `client`, `employee` i `admin`. Struktura bazy i dane testowe pozostają bez zmian. Publiczna rejestracja nadal tworzy wyłącznie rolę `client` ustawioną przez PHP.

## Uprawnienia i wszystkie strony

Każda rola ma własną część. Nie ma automatycznego dziedziczenia uprawnień: administrator nie jest równocześnie klientem lub pracownikiem. Wspólna strona konta dopuszcza wszystkie trzy role.

| URL | Gość | `client` | `employee` | `admin` | Kontrola |
|---|---|---|---|---|---|
| `/register.php` | Tak | Tak | Tak | Tak | Publiczny formularz; zapis przez POST z CSRF. |
| `/login.php` | Formularz | Przekierowanie do swojej części | Przekierowanie do swojej części | Przekierowanie do swojej części | Publiczny formularz; uwierzytelnianie przez POST z CSRF. |
| `/account.php` | Nie | Tak | Tak | Tak | `require_roles(['client', 'employee', 'admin'])` |
| `/client.php` | Nie | Tak | Nie | Nie | `require_role('client')` |
| `/employee.php` | Nie | Nie | Tak | Nie | `require_role('employee')` |
| `/admin.php` | Nie | Nie | Nie | Tak | `require_role('admin')` |
| `/logout.php` | Zakończenie własnej anonimowej sesji | Wylogowanie | Wylogowanie | Wylogowanie | Wyłącznie POST z poprawnym CSRF tej sesji; GET daje 405. |

Wylogowanie jest wspólną akcją sesji, nie widokiem z chronionymi danymi. Nie wymaga odczytu konta z bazy, dzięki czemu działa również podczas awarii PDO lub po dezaktywacji konta. Nie daje dostępu do cudzej sesji ani do stron wymagających logowania.

CSS, `js/session.js` i zasoby w `public/assets/` są publiczne i nie zawierają danych kont. Kod `includes/`, konfiguracja `config/`, SQL, dokumentacja i testy pozostają poza katalogiem dokumentów serwera. Serwer musi wskazywać wyłącznie na `public/`, zgodnie z instrukcją uruchomienia. Strona główna `/` nie została jeszcze utworzona.

## Jeden moduł autoryzacji

Wszystkie reguły znajdują się w `includes/auth.php`:

| Funkcja | Działanie |
|---|---|
| `current_user_id()` | Odczytuje dodatnie ID typu `int` z sesji. Samo ID nie potwierdza aktywności konta. |
| `current_user()` | Pobiera istniejące, aktywne konto po ID przez prepared SELECT, bez hasha hasła. Sprawdza znaną rolę i synchronizuje jej kopię w sesji. |
| `is_logged_in()` | Sprawdza, czy `current_user()` zwraca aktywne konto. |
| `current_user_role()` | Zwraca aktualną rolę odczytaną z bazy albo `null`. |
| `require_login()` | Zwraca aktywne konto; gościa kieruje na `/login.php` i kończy skrypt. |
| `require_role('admin')` | Wymaga jednej roli, delegując do `require_roles()`. |
| `require_roles(['employee', 'admin'])` | Dopuszcza tylko wskazane role, po sprawdzeniu zalogowania. Nieprawidłowa lub pusta lista nie otwiera dostępu. |
| `role_home($role)` | Zwraca stałą lokalną ścieżkę z `ROLE_HOME_PATHS`. Nie przyjmuje adresu powrotu z żądania. |

Nie zmieniono mechanizmu PDO, konfiguracji ani helperów sesji. Pojedyncza reguła w kontrolerze uruchamia całą kontrolę przed dołączeniem HTML. Przykład strony tylko dla administratora:

```php
<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_role('admin');
```

Dla strony dopuszczającej pracownika i administratora użyj `$user = require_roles(['employee', 'admin']);`. Lista jest stałą decyzją programisty w kodzie strony — nie przekazuj do niej GET ani POST.

## Źródło uprawnień i odmowa dostępu

- ID użytkownika pochodzi z sesji ustawionej po zweryfikowaniu hasła. Bieżąca rola i aktywność są odczytywane z bazy przy każdym żądaniu chronionej strony.
- `role`, `user_role`, `active`, `user_id` i adres powrotu wysłane w GET/POST nie zmieniają uprawnień. Nawet zmiana samego `user_role` w zapisanej sesji nie zastępuje roli z bazy.
- Gość dostaje przekierowanie HTTP 303 do logowania. Zalogowany bez wymaganej roli dostaje HTTP 303 do własnej części i jednorazowy komunikat „Nie masz uprawnień do tej strony.” Treść zabronionej strony nie jest renderowana.
- Po zmianie roli kolejne żądanie korzysta już z nowych uprawnień. Dezaktywacja lub usunięcie konta usuwa ID i rolę z sesji i uniemożliwia dostęp.
- Nieznana rola w bazie nie daje dostępu. Błędna lista ról w kodzie lub awaria bazy przechodzi do istniejącego bezpiecznego handlera, bez SQL, konfiguracji i śladu stosu w odpowiedzi.
- Wszystkie chronione widoki zachowują nagłówki `no-store`, wspólny skrypt obsługi historii, escapowanie przez `e()` i wylogowanie przez POST z CSRF.

## Przekierowania i minimalne strony

Po poprawnym logowaniu klient trafia na `/client.php`, pracownik na `/employee.php`, a administrator na `/admin.php`. Tak samo działa wejście zalogowanego użytkownika na `/login.php`.

Trzy nowe kontrolery oraz istniejący `account.php` używają `includes/account-view.php`: wspólnego widoku tytułu, informacji o koncie, nawigacji i wylogowania. Nie powielono HTML ani bloków autoryzacji. Widok znajduje się poza katalogiem publicznym, a każda publiczna strona dołączająca go najpierw sprawdza uprawnienia.

Nie dodano CRUD, terminarza, rezerwowania wizyt, zarządzania usługami, personelem ani użytkownikami. Nie jest to jeszcze ukończony cały Etap 2 — profil i jego edycja pozostają osobnymi zadaniami.

## Testy

```text
php tests/foundation.php
php tests/registration.php
php tests/login.php
php tests/authorization.php
```

Testy autoryzacji bez bazy sprawdzają mapę adresów, odrzucanie błędnych list ról i brak dostępu dla gościa. Aby uruchomić pełne 26 przypadków, ustaw lokalnie `CUTTIME_TEST_DSN`, `CUTTIME_TEST_USER`, `CUTTIME_TEST_PASSWORD` tak jak w [testach logowania](login.md#testy). Wymagane są `pdo_mysql`, dostępne `proc_open()` oraz uprawnienie `CREATE TEMPORARY TABLES`.

Skrypt uruchamia osobne procesy, aby sprawdzić również zakończenie skryptu po przekierowaniu. Każdy korzysta z tymczasowej tabeli `users` utworzonej według rzeczywistego SQL. Połączenie testowe jest podstawione wyłącznie wewnątrz testu: nie wczytuje lokalnej konfiguracji aplikacji i nie modyfikuje trwałych danych. Bez DSN część bazowa jest wyraźnie pomijana.

Sprawdzono wszystkie kombinacje gość/klient/pracownik/administrator i cztery chronione strony, dopuszczenie kilku ról, próby nadpisania roli, zmianę roli po zalogowaniu, nieaktywne i nieistniejące konto. Testy HTTP sprawdzają dodatkowo docelowe przekierowania, brak treści chronionej w odmowie, komunikat, cache oraz brak dostępu po wylogowaniu.

Weryfikacja tej wersji na PHP 8.5.11 i odizolowanej MariaDB 11.4.5: 71 testów PHP (w tym 26 autoryzacji), 66 kontroli HTTP oraz sprawdzenie składni wszystkich 21 plików PHP zakończyły się powodzeniem. Sprawdzono również bezpieczną odmowę dostępu podczas awarii bazy i działające wówczas wylogowanie. SQL, ERD i wspólne połączenie PDO pozostały bez zmian.
