# Profil i edycja danych — Etap 2

Po [uruchomieniu projektu](php-foundation.md) i zalogowaniu wybierz „Mój profil” na stronie swojej roli lub konta. `/profile.php` wyświetla imię, nazwisko, e-mail, telefon i rolę. `/profile-edit.php` umożliwia edycję pierwszych czterech pól. Obie strony są dostępne dla aktywnego klienta, pracownika i administratora, zawsze w odniesieniu do własnego konta.

## Własność i kontrola dostępu

- Oba kontrolery zaczynają od istniejącego bootstrapu i `require_roles(['client', 'employee', 'admin'])`. Aktualne konto pobiera `current_user()` przez prepared SELECT według ID zapisanego w sesji. Sprawdzane są również aktywność i rzeczywista rola w bazie.
- `update_own_profile()` sam odczytuje `current_user_id()`. Nie przyjmuje ID jako argumentu ani z formularza. Parametry `id` i `user_id` w GET/POST nie wskazują edytowanego konta.
- UPDATE ma stałą listę pól: `name`, `surname`, `email`, `phone`, oraz warunek `id = :id AND active = 1`. `role`, `active`, hasło, ID i pola administracyjne przesłane przez użytkownika są ignorowane. Dotyczy to również profilu administratora.
- Profil nie pobiera ani nie wyświetla hasła lub hasha. Rola jest wyłącznie informacją z bazy. Brak danych konta, dezaktywacja lub zakończenie sesji uniemożliwiają dostęp.

## Walidacja i zapis

Rejestracja i edycja korzystają z jednej funkcji `validate_user_details()` w `includes/user-validation.php`. Reguły wcześniej używane przy rejestracji zostały zachowane.

| Pole | Reguły PHP |
|---|---|
| Imię | 1–50 znaków Unicode; litery i znaki diakrytyczne, pojedyncze spacje, apostrofy i myślniki między członami. Wstępny limit 200 bajtów. |
| Nazwisko | Jak imię, maksymalnie 80 znaków; wstępny limit 320 bajtów. |
| E-mail | Maksymalnie 254 bajty, `FILTER_VALIDATE_EMAIL`, usunięcie skrajnych białych znaków, małe litery i unikalność. |
| Telefon | Polski numer: 9 cyfr, pierwsza różna od zera, opcjonalnie `+48`, spacje lub myślniki; zapis jako `+48` i 9 cyfr. Maksymalnie 32 bajty wejścia. |

Wszystkie cztery pola są wymagane. Odrzucane są również same białe znaki Unicode, tablice i inne wartości nietekstowe, niepoprawny UTF-8, NUL, znaki sterujące oraz zbyt długie dane. Istniejące konto z telefonem NULL można wyświetlić („Nie podano”); przy edycji trzeba go uzupełnić, zgodnie z zasadą przyjętą w rejestracji.

Prepared SELECT sprawdza zajętość adresu z warunkiem `id <> :id`, więc własny niezmieniony e-mail jest prawidłowy. Ograniczenie UNIQUE nadal zabezpiecza równoczesne zapisy; jego naruszenie podczas UPDATE daje czytelny komunikat przy polu e-mail. Zapis bez zmiany wartości również kończy się sukcesem.

Wszystkie SELECT i UPDATE dotyczące konta używają istniejącego PDO z parametrami. W bazie zapisywane są zwykłe dane, a podczas wyświetlania wspólny helper `e()` zabezpiecza tekst i atrybuty HTML. Dotyczy to także dawnych danych zawierających HTML. Nieoczekiwane błędy przechodzą do wspólnego handlera i nie ujawniają SQL, ścieżek ani konfiguracji.

POST wymaga wspólnego tokenu CSRF. Brak lub niepoprawny token daje HTTP 403, żądanie powyżej 16 KiB — 413, błędy pól — 422, niedozwolona metoda — 405. Po poprawnym zapisie następuje HTTP 303 na `/profile.php` i jednorazowy komunikat „Zapisano dane profilu.” Odświeżenie nie powtarza UPDATE. Nowy e-mail służy od tej chwili do logowania, a profil odczytuje aktualne dane z bazy.

## Pliki i interfejs

`includes/profile.php` zawiera zapis, a `includes/profile-view.php` wspólny widok dwóch kontrolerów. Profil korzysta z istniejącego arkusza `public/css/auth.css`, formularzy i komunikatów. Dane układają się w dwie kolumny, a na małych ekranach w jedną. Widoki mają nagłówki `no-store` i istniejący `public/js/session.js` do ponownej kontroli dostępu po przywróceniu z historii.

Nie zmieniono schematu SQL, danych testowych, ERD, połączenia PDO ani mechanizmu konfiguracji. Ten formularz nie obsługuje zmiany hasła, roli, aktywności ani zarządzania cudzymi kontami.

## Testy

```text
php tests/profile.php
php tests/registration.php
php tests/login.php
php tests/authorization.php
php tests/foundation.php
```

`tests/profile.php` zawiera 7 przypadków bez bazy oraz 6 z rzeczywistym PDO. Część bazowa wymaga `pdo_mysql`, `CREATE TEMPORARY TABLES` oraz lokalnych zmiennych `CUTTIME_TEST_DSN`, `CUTTIME_TEST_USER`, `CUTTIME_TEST_PASSWORD`, opisanych w [testach rejestracji](registration.md#testy). Bez DSN jest jawnie pomijana. Test tworzy tymczasową tabelę `users` według SQL, bez zmieniania trwałych danych i bez odczytywania lokalnej konfiguracji aplikacji.

Sprawdzane są puste pola, białe znaki, e-maile, telefon, limity, Unicode, nietypowe typy wejścia, XSS, wszystkie role, własny i zajęty e-mail, niezmieniony zapis, nieaktywne i nieistniejące konto oraz podmiana ID, roli, aktywności, hasła i daty utworzenia. Test konfliktu unikalności pomija wynik SELECT, aby wymusić obsługę rzeczywistego błędu UNIQUE podczas UPDATE.

Na PHP 8.5.11 i odizolowanej MariaDB 11.4.5 przeszły wszystkie 84 testy PHP, w tym 13 profilu. Dodatkowe 61 kontroli HTTP objęło oba chronione adresy, formularz, CSRF, metody i limity żądań, przekierowanie i komunikat, zmianę adresu używanego do logowania, escapowanie danych zapisanych w bazie, brak dostępu po wylogowaniu i dezaktywacji oraz bezpieczne odpowiedzi podczas awarii bazy. Składnia wszystkich 27 plików PHP jest poprawna. Oba widoki sprawdzono w Edge przy szerokościach 1280, 390 i 320 px — bez przewijania w poziomie. Nie korzystano z lokalnej bazy użytkownika.
