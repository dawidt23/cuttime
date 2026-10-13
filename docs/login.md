# Logowanie — Etap 2

Po [konfiguracji PHP i bazy](php-foundation.md) otwórz `/login.php`. Możesz użyć kont testowych z [README](../README.md#dane-testowe) albo konta utworzonego przez rejestrację.

W `database.sql` poprawiono hash kont testowych: poprzednia wartość nie przechodziła `password_verify('password', ...)`. Nowy hash jest wygenerowany przez PHP `password_hash()` i sprawdzony testem. Ta zmiana pliku nie aktualizuje wcześniej zaimportowanej bazy. Jeśli masz taką bazę, użyj nowo zarejestrowanego konta albo zaktualizuj wyłącznie hashe demonstracyjnych kont w swoim kliencie SQL. Nie importuj ponownie całego schematu do wypełnionej bazy.

## Przebieg

1. Formularz GET/POST korzysta z dotychczasowego bootstrapu, sesji, tokenu CSRF, `e()` i arkusza `public/css/auth.css`.
2. `validate_login()` w `includes/auth.php` sprawdza pola po stronie PHP. E-mail jest normalizowany jak przy rejestracji; hasło pozostaje dokładnie takie, jak wpisano.
3. `log_in()` korzysta ze wspólnego `db()` i prepared SELECT z parametrem `:email`. Hasło sprawdza przez `password_verify()`, a następnie wymaga `active = 1` i poprawnej roli z bazy.
4. Brak konta, błędne hasło i nieaktywne konto dają ten sam komunikat: „Nieprawidłowy adres e-mail lub hasło.” Dla nieistniejącego konta również wykonywana jest weryfikacja względem stałego hasha zastępczego; nie jest to konto ani hasło dostępowe.
5. Po sukcesie `session_regenerate_id(true)` zmienia ID i usuwa starą sesję. Zapisane zostają `user_id` (int) i `user_role` (`client`, `employee` lub `admin`) odczytane z bazy. Stare dane anonimowe i token CSRF są usuwane. Sesja nie zawiera hasła ani hasha; może dodatkowo zawierać jednorazowy komunikat `_flash` i nowy `_csrf_token` przy otwarciu kolejnego formularza.
6. Przekierowanie HTTP 303 prowadzi na `/client.php`, `/employee.php` lub `/admin.php`, zgodnie z rolą z bazy. Mapę adresów zawiera wspólny moduł autoryzacji. Odświeżenie nie powtarza POST. `require_login()` korzysta z `current_user()`, który sprawdza aktywność w bazie i aktualizuje rolę sesji. Wejście zalogowanego użytkownika na `/login.php` również kieruje do jego części; ponowny POST nie przełącza konta.

Wylogowanie opisuje [obsługa sesji](sessions.md), a ochronę wszystkich obecnych stron — [kontrola dostępu](authorization.md). Strony ról są minimalne; nie ma jeszcze edycji profilu ani funkcjonalności Etapu 3.

## Walidacja i bezpieczeństwo

- Oba pola są wymagane. PHP odrzuca brakujące wartości, puste ciągi, same białe znaki Unicode, tablice i inne typy, niepoprawny UTF-8, NUL oraz znaki sterujące.
- E-mail ma limit 254 bajtów i jest sprawdzany przez `FILTER_VALIDATE_EMAIL`. Hasło ma limit 72 bajtów zgodny z bcrypt używanym przez rejestrację; nie jest trimowane. Logowanie nie wymusza minimum 12 znaków obowiązującego przy tworzeniu nowych haseł, dzięki czemu konta testowe z SQL nadal działają.
- Rozmiar jest sprawdzany przed operacjami Unicode i bcrypt. Żądania powyżej 16 KiB otrzymują HTTP 413. Niepoprawny CSRF daje 403, błędy walidacji lub uwierzytelnienia 422, a nieobsługiwana metoda 405.
- Pola `role`, `active`, `user_id` i inne dodatkowe parametry POST nie nadają uprawnień. Źródłem ID, aktywności i roli jest baza.
- E-mail i dane konta przechodzą przez istniejące `e()` przy wyświetlaniu. Hasło nigdy nie wraca do HTML ani komunikatu. Bardzo długie dane nie są ponownie wyświetlane.
- Błędy PDO obsługuje wspólny handler: ogólny komunikat HTTP 500 bez SQL, konfiguracji i śladu stosu. Nadal wymagane są ustawienia PHP opisane w instrukcji fundamentu.

## Testy

```text
php tests/foundation.php
php tests/registration.php
php tests/login.php
```

Testy logowania bez konfiguracji bazy sprawdzają walidację. Aby uruchomić także uwierzytelnianie i sesje, ustaw lokalnie `CUTTIME_TEST_DSN`, `CUTTIME_TEST_USER` i `CUTTIME_TEST_PASSWORD`, analogicznie do [testów rejestracji](registration.md#testy). Potrzebne są `pdo_mysql` oraz uprawnienie `CREATE TEMPORARY TABLES`. Bez DSN część bazowa jest jawnie pomijana.

`tests/login.php` tworzy tymczasową tabelę `users` według `database.sql`, widoczną tylko w połączeniu testowym. Nie czyta `database.local.php` i nie zmienia trwałych danych. Sprawdza brakujące pola, spacje, format e-maila, nietypowe i długie dane, XSS, nieistniejące i nieaktywne konta, błędne i poprawne hasła, wszystkie trzy role, ignorowanie nadpisania roli/aktywności, odnowienie sesji i usunięcie starego pliku, zachowanie danych po ponownym otwarciu sesji oraz logowanie konta utworzonego przez rejestrację.

Sprawdzono na PHP 8.5.11 i odizolowanej MariaDB 11.4.5: 13 testów logowania, 16 rejestracji i 16 fundamentu. Testy HTTP objęły formularz, CSRF, walidację, przekierowanie po sukcesie, cookies, brak hasła w HTML i sesji, nieskuteczność starego identyfikatora sesji oraz odświeżanie roli i odrzucanie konta dezaktywowanego po zalogowaniu.

Dokumentacja PHP: [`password_verify()`](https://www.php.net/manual/en/function.password-verify.php), [`session_regenerate_id()`](https://www.php.net/manual/en/function.session-regenerate-id.php).
