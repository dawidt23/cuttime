# Rejestracja klienta — Etap 2

Po [konfiguracji PHP i bazy](php-foundation.md) uruchom `php -S 127.0.0.1:8000 -t public` i otwórz `http://127.0.0.1:8000/register.php`.

`public/register.php` obsługuje formularz GET/POST i korzysta z dotychczasowego bootstrapu, PDO, sesji, flash, przekierowań, obsługi błędów oraz `e()`. Walidacja i zapis znajdują się w `includes/registration.php`. Token CSRF jest współdzielonym helperem w `includes/csrf.php`, a styl znajduje się w `public/css/auth.css`.

## Zasady walidacji

Wszystkie sześć pól jest wymaganych w publicznym formularzu. Chociaż kolumna `users.phone` dopuszcza NULL, publiczna rejestracja wymaga telefonu kontaktowego.

| Pole | Reguły po stronie PHP |
|---|---|
| Imię | 1–50 znaków Unicode; litery, znaki diakrytyczne, pojedyncze spacje, apostrofy i myślniki pomiędzy członami. |
| Nazwisko | Jak imię, maksymalnie 80 znaków. |
| E-mail | Maksymalnie 254 bajty, `FILTER_VALIDATE_EMAIL`, usunięcie skrajnych białych znaków i małe litery; unikalność sprawdzana w bazie. |
| Telefon | Polski numer: 9 cyfr, pierwsza różna od zera; opcjonalne `+48`, zwykłe spacje i myślniki. Zapis w postaci `+48` i 9 cyfr. Surowe pole ma limit 32 bajtów. |
| Hasło | Co najmniej 12 znaków, maksymalnie 72 bajty UTF-8. Bez trimowania, bez wymogu specjalnego zestawu znaków. |
| Powtórzenie hasła | Ta sama dokładna wartość i limit 72 bajtów; nigdy nie jest zapisywane w bazie. |

PHP odrzuca brakujące i puste pola, same białe znaki (również Unicode), tablice i inne wartości nietekstowe, niepoprawny UTF-8, NUL, znaki sterujące oraz bardzo długie dane. Kontrola bajtów odbywa się przed operacjami na Unicode. Formularz ma limit rozmiaru żądania 16 KiB; zbyt duże żądanie otrzymuje HTTP 413. Ograniczenia HTML wspierają użytkownika, ale nie zastępują kontroli serwerowej.

## Zapis i bezpieczeństwo

- Sprawdzenie istniejącego adresu i INSERT korzystają z prepared statements dotychczasowego PDO.
- Hasło jest zapisane wyłącznie przez `password_hash($password, PASSWORD_BCRYPT)`, z automatyczną solą. Limit 72 bajtów zapobiega obcięciu hasła przez bcrypt. [Dokumentacja PHP](https://www.php.net/manual/en/function.password-hash.php)
- Rola `client` i `active = 1` są stałymi w zapytaniu serwera. Dodatkowe `role=administrator`, `role=admin`, `active=1`, `active=0` i inne nieprzewidziane pola nie wpływają na zapis. Konto jest aktywne zgodnie z przyjętą polityką; nie ma jeszcze weryfikacji e-maila.
- SELECT poprzedza zapis, a błąd UNIQUE z INSERT jest dodatkowo obsługiwany, gdy drugi zapis nastąpi pomiędzy sprawdzeniem i INSERT. Komunikat jest czytelny i nie zawiera SQL.
- Brak lub niepoprawny token CSRF daje HTTP 403. Po sukcesie token jest usuwany. Zwykłe błędy pól dają HTTP 422; metody inne niż GET/POST — HTTP 405.
- Dane ponownie wyświetlane w formularzu i komunikatach przechodzą przez istniejące `e()`. Hasła nie są wpisywane do atrybutów formularza, komunikatów ani sesji. Wartości przekraczające limity nie są ponownie wyświetlane.
- Nieoczekiwane błędy bazy przechodzą do istniejącego handlera i dają ogólną odpowiedź HTTP 500. Ustawienia `php.ini` nadal są wymagane zgodnie z instrukcją fundamentu.

Po udanym INSERT następuje przekierowanie HTTP 303 na GET formularza i jednorazowy komunikat sukcesu. Odświeżenie nie powtarza INSERT. Użytkownik nie jest automatycznie logowany. Odnośnik prowadzi do `login.php`, który obecnie zawiera tylko informację o planowanym logowaniu, bez formularza i bez uwierzytelniania.

## Testy

Testy walidacji bez bazy:

```text
php tests/foundation.php
php tests/registration.php
```

Testy zapisu wymagają rozszerzenia `pdo_mysql` oraz zmiennych środowiskowych `CUTTIME_TEST_DSN`, `CUTTIME_TEST_USER`, `CUTTIME_TEST_PASSWORD`, ustawionych lokalnie. Przykładowy DSN: `mysql:host=127.0.0.1;port=3306;dbname=cuttime;charset=utf8mb4`. Nie wpisuj rzeczywistych haseł do repozytorium. Konto testowe musi mieć uprawnienie `CREATE TEMPORARY TABLES`.

Skrypt tworzy tymczasową tabelę `users` według rzeczywistej definicji z `database.sql`. Zasłania ona tabelę właściwą tylko w połączeniu testowym; test nie zmienia trwałych danych ani nie czyta konfiguracji lokalnej aplikacji. Gdy DSN nie jest ustawiony, testy bazy są wyraźnie oznaczone jako pominięte.

Sprawdzane są puste pola, spacje, e-maile, telefony, zgodność haseł, limity, Unicode, tablice, XSS, CSRF, zapis i `password_verify()`, duplikaty, wymuszenie roli/aktywności oraz obsługa naruszenia UNIQUE w trakcie INSERT. Scenariusz wyścigu wymusza pominięcie wyniku SELECT; samo odrzucenie INSERT wykonuje rzeczywisty silnik bazy.

Weryfikacja tej wersji: PHP 8.5.11, MariaDB 11.4.5 w odizolowanym środowisku, 16 testów rejestracji oraz testy HTTP formularza i przekierowań. Nie uruchamia to ani nie konfiguruje Twojej własnej lokalnej bazy.
