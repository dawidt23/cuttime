# Sesja i wylogowanie — Etap 2

## Jedna obsługa sesji

`includes/bootstrap.php` uruchamia `start_secure_session()` z `includes/session.php`. To jedyne miejsce w kodzie aplikacji wywołujące natywne `session_start()`. Helper sprawdza stan sesji, więc kolejne wywołania nie otwierają jej ponownie.

Cookie nazywa się `CUTTIMESESSID`. Zachowano strict mode, identyfikator tylko w cookie, `HttpOnly`, `SameSite=Lax` i `Secure` przy HTTPS. Sesja nie jest przesyłana w URL. `session_cache_limiter('nocache')` ustawia między innymi `Cache-Control: no-store, no-cache, must-revalidate`.

Po logowaniu nadal wykonywane jest `session_regenerate_id(true)`. Sesja zawiera:

| Klucz | Zawartość |
|---|---|
| `user_id` | Dodatnie ID użytkownika typu `int`, odczytane z bazy. |
| `user_role` | Rola z bazy: `client`, `employee` lub `admin`. |
| `_csrf_token` | Token formularzy, np. przycisku wylogowania. |
| `_flash` | Opcjonalne jednorazowe komunikaty. |

Sesja nie zawiera hasła, hasha hasła ani całego rekordu użytkownika. ID i rola nie pochodzą z parametrów formularza. `current_user()` przy odczycie sprawdza aktywność konta i aktualizuje rolę na podstawie bazy.

## Wspólne funkcje

W `includes/auth.php` znajdują się `current_user_id()`, `current_user()`, `is_logged_in()`, `current_user_role()`, `log_in()`, `require_login()`, `require_role()`, `require_roles()`, `role_home()` oraz `log_out()`. Walidacja logowania pozostaje w `validate_login()`. Nie dodano drugiego połączenia PDO ani drugiego zestawu helperów.

`current_user_id()` odczytuje poprawny typ ID z sesji; sam nie potwierdza aktywności w bazie. Do ochrony strony służy `require_login()`, które korzysta z pełnego sprawdzenia konta, zwraca jego dane bez hasha lub przekierowuje gościa do logowania i kończy wykonanie skryptu.

## Chroniona strona

Wspólna strona `/account.php` wyświetla dane zalogowanego konta oraz przycisk wylogowania. Jest dostępna dla wszystkich trzech ról. Po logowaniu użytkownik trafia do swojej części: `/client.php`, `/employee.php` lub `/admin.php`. Wszystkie te strony korzystają ze wspólnego widoku i ochrony historii. Osobne `/profile.php` i `/profile-edit.php` zapewniają [profil z edycją danych](profile.md), z tą samą kontrolą aktywnego konta, nagłówkami cache i obsługą historii. Formularz edycji korzysta z tego samego tokenu CSRF. Po zapisie nie trzeba zmieniać danych osobowych w sesji — są odczytywane na nowo z bazy.

Każda kolejna strona wymagająca logowania powinna zaczynać się tak, przed jakimkolwiek HTML:

```php
<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_login();
```

W jej HTML dołącz też `<script src="/js/session.js" defer></script>`. Nagłówki sesji zapobiegają przechowywaniu chronionej odpowiedzi w cache HTTP. Pamięć historii przeglądarki (bfcache) jest osobnym mechanizmem: skrypt ukrywa opuszczany widok i przy zdarzeniu `pageshow` z `persisted = true` przeładowuje stronę. Dzięki temu kontrola PHP jest wykonywana ponownie po przywróceniu widoku. JavaScript wspomaga obsługę historii; każde żądanie chronionej strony jest niezależnie weryfikowane przez PHP.

Jeżeli strona wymaga konkretnej roli lub kilku ról, użyj zamiast samego `require_login()` funkcji `require_role()` albo `require_roles()`, zgodnie z [dokumentacją autoryzacji](authorization.md). Obie najpierw wymagają aktywnego konta, a następnie sprawdzają jego aktualną rolę w bazie.

## Wylogowanie

1. Formularz na stronie konta lub części danej roli wysyła POST do `/logout.php` ze wspólnym tokenem CSRF. GET i inne metody dają HTTP 405; brak, tablica lub niepoprawny token daje HTTP 403. Takie żądania nie wylogowują użytkownika.
2. `log_out()` ustawia `$_SESSION = []` i wysyła wygasłe cookie z takim samym path, domain, Secure, HttpOnly i SameSite jak cookie sesji.
3. `session_destroy()` usuwa zapis sesji po stronie serwera. Kod usuwa również lokalną wartość cookie i zeruje `session_id()`, aby stare ID nie zostało ponownie użyte w tym żądaniu.
4. Kontroler wykonuje HTTP 303 do `/login.php?logged_out=1`. Stały komunikat „Wylogowano pomyślnie.” nie zawiera danych żądania. Parametr odpowiada tylko za komunikat, nie potwierdza uprawnień.
5. Publiczny formularz logowania może utworzyć nową anonimową sesję na potrzeby CSRF. Nie jest to przywrócona sesja użytkownika. Stare cookie i stary token nie przywracają dostępu.

Wylogowanie nie odczytuje bazy, więc może zakończyć sesję również podczas awarii PDO. Nie wylogowuje innych urządzeń ani niezależnych sesji użytkownika.

## Weryfikacja

Uruchom `php tests/foundation.php`, `php tests/login.php` i `php tests/registration.php` zgodnie z instrukcją konfiguracji testów w [dokumentacji logowania](login.md#testy). Rozszerzone testy fundamentu sprawdzają odmowę dostępu gościowi, wyczyszczenie sesji i cookie, usunięcie pliku sesji, odrzucenie starego ID oraz bezpieczne ponowne wywołanie wylogowania.

Scenariusz do sprawdzenia lokalnie:

1. Otwórz `/account.php` jako gość — nastąpi przekierowanie do logowania.
2. Zaloguj się — pojawi się chroniona część Twojej roli. Kliknij „Wyloguj się”.
3. Powinien pojawić się publiczny formularz z komunikatem potwierdzenia.
4. Użyj przycisku Wstecz, odśwież stronę i ręcznie otwórz `/account.php` — dane zalogowanego konta nie powinny być dostępne.
5. Sprawdź też drugą kartę z otwartą wcześniej stroną konta: jej odświeżenie powinno prowadzić do logowania.

Sprawdzono 45 testów PHP (16 fundamentu, 13 logowania, 16 rejestracji), 23 kontrole HTTP sesji/wylogowania oraz rzeczywiste przejście Wstecz, odświeżenie i wejście przez URL w Edge. Testy bazy wykonano w odizolowanej MariaDB 11.4.5 przy PHP 8.5.11, bez modyfikowania lokalnej konfiguracji i danych użytkownika.

Źródła: [PHP — zakończenie sesji](https://www.php.net/manual/en/function.session-destroy.php), [MDN — pageshow](https://developer.mozilla.org/en-US/docs/Web/API/Window/pageshow_event), [Chrome — bfcache i no-store](https://developer.chrome.com/docs/web-platform/bfcache-ccns).
