# Kategorie usług — Etap 3

Moduł działa pod `/admin-categories.php`, przez istniejącą pozycję „Kategorie” w nawigacji administratora. Wymaga standardowej konfiguracji PHP/PDO z [instrukcji uruchomienia](php-foundation.md). Korzysta z istniejącej tabeli `service_categories`; SQL, ERD i dane testowe pozostały bez zmian.

## CRUD

- **CREATE:** `?action=create` pokazuje formularz nazwy, opcjonalnego opisu i aktywności. Poprawny POST dodaje rekord, po czym przekierowuje do listy z jednorazowym komunikatem.
- **READ:** lista zawiera ID, nazwę, skrócony opis, aktywność, liczbę przypisanych usług i akcje. Paginacja obejmuje 20 rekordów, z sortowaniem według nazwy i ID. Pełny opis jest dostępny w edycji. Pusta lista pokazuje komunikat i przycisk dodawania.
- **UPDATE:** `?action=edit&id=…` odczytuje kategorię i umożliwia edycję trzech pól. Nie zmienia jej ID ani przypisanych usług. Zapis niezmienionych wartości jest poprawny; aktualna nazwa nie jest traktowana jako własny duplikat.
- **DELETE:** `?action=delete&id=…` jedynie pokazuje potwierdzenie z nazwą kategorii. Usunięcie wymaga osobnego POST, tokenu CSRF i zaznaczonego pola potwierdzenia. Anulowanie wraca do listy. Ponowne usunięcie nieistniejącego rekordu daje czytelny błąd 404.

Zmiana aktywności kategorii nie zmienia aktywności usług. Usługi mają [osobny moduł zarządzania](admin-services.md), podobnie jak [podstawowe dane pracowników](admin-employees.md). Przypisywanie usług pracownikom, zarządzanie dostępnością i rezerwacjami pozostaje na kolejne kroki.

## Walidacja

- Nazwa: wymagana, 1–100 znaków Unicode, po usunięciu białych znaków na brzegach; limit zgodny z `VARCHAR(100)`.
- Opis: opcjonalny, do 2000 znaków Unicode (limit aplikacyjny mieszczący się w `TEXT`); puste wartości i same białe znaki zapisują się jako `NULL`. Dopuszczalne są nowe wiersze i tabulatory.
- Teksty: najpierw limit bajtów, następnie poprawność UTF-8, brak niedozwolonych znaków sterujących i długość. Tablice zamiast pojedynczego pola są odrzucane. Całe żądanie POST ma limit 32 KiB.
- Treść jest zwykłym tekstem. Znaki HTML zapisują się dosłownie i są wyświetlane przez istniejące `e()`; nie jest to edytor HTML. Escapowanie nie zastępuje walidacji ani parametrów SQL.
- Aktywność: wyłącznie tekstowe wartości `0` albo `1` z formularza administratora.
- Nazwa musi być unikalna zgodnie z kolacją bazy. Wstępny prepared SELECT daje komunikat przed zapisem, a obsługa błędu UNIQUE chroni również równoległe INSERT/UPDATE.
- ID i numer strony: dodatnia liczba całkowita zapisana cyframi, bez zer wiodących, tablic, znaków, ułamków i przekroczenia zakresu `INT UNSIGNED`. Zbyt wysoki poprawny numer strony jest ograniczany do ostatniej strony.
- Akcja pochodzi z zamkniętej listy `list/create/edit/delete`. Brak rekordu zwraca 404, błędny parametr 400, niedozwolona metoda 405, błędny formularz 422, wadliwy CSRF 403, zbyt duży POST 413.

## Zależność kategoria → usługi

Lista i ekran potwierdzenia uwzględniają wszystkie usługi, także nieaktywne. Dla kategorii z usługami nie ma przycisku wykonującego usunięcie, a próba bezpośredniego POST jest blokowana komunikatem i HTTP 409.

Przed DELETE aplikacja ponownie sprawdza zależności. Jeżeli usługa zostanie przypisana pomiędzy SELECT a DELETE, istniejący `fk_services_category` z `ON DELETE RESTRICT` uniemożliwi osierocenie danych. Błąd 1451 jest zamieniany na ten sam czytelny komunikat. Nie ma usuwania kaskadowego ani automatycznego usuwania usług.

## Wspólny kod i zabezpieczenia

`public/admin-categories.php` jest jednym kontrolerem listy i formularzy. Przed wszystkimi operacjami wywołuje istniejące `require_role('admin')`, które odczytuje bieżącą rolę i aktywność z bazy. Dane GET/POST nie nadają uprawnień. ID celu zapisu pochodzi wyłącznie ze zweryfikowanego URL; dodatkowe ID w POST jest ignorowane. Administrator może edytować dowolną istniejącą kategorię.

`includes/admin-categories.php` zawiera zapytania modułu, a `includes/admin-categories-view.php` jego treść. Przy dodaniu usług wspólną walidację tekstów, aktywności, ID oraz sprawdzanie metod i CSRF wydzielono do `includes/admin-forms.php`, a formularz oferty do `includes/admin-offer-form.php`. Kategorie zachowują dotychczasowe reguły. Oba moduły korzystają z istniejącego `account-view.php` i `admin-navigation.php`; nie powstał drugi layout ani system nawigacji. Wszystkie zapytania modułu korzystają z prepared statements, a LIMIT/OFFSET są wiązane jako liczby całkowite. Kod używa dotychczasowego `db()`, sesji, CSRF, flash, przekierowań, `e()` i bezpiecznej obsługi błędów.

Nieznane błędy PDO trafiają do wspólnego handlera; interfejs nie wypisuje wyjątków, SQL ani ścieżek. Pliki `includes/` pozostają poza `public/`. Tabela na wąskim ekranie przewija się we własnym obszarze, bez poszerzania całej strony; formularze korzystają z dotychczasowej stylistyki.

## Testy

Uruchom `php tests/http.php` z testowym DSN zgodnie z [instrukcją testów](stage2-audit.md#powtarzanie-testów). Nowy `tests/categories-http.php` jest częścią tego zestawu i korzysta z jego infrastruktury, własnego losowego schematu oraz kopii aplikacji. Nie odczytuje lokalnej konfiguracji użytkownika.

Przypadki obejmują pełny CRUD, wszystkie role i akcje przez GET/POST, CSRF, potwierdzenie, rozmiar żądania, błędne ID/akcje/typy/UTF-8, puste pola, Unicode i graniczne długości, unikalność, niezmieniony zapis, podmianę ID w POST, XSS, paginację, nieistniejące rekordy, zależne i nieaktywne usługi oraz przypisanie usługi po wyświetleniu potwierdzenia. Testy dodatkowo wymuszają pominięcie wstępnego sprawdzenia, aby sprawdzić obsługę rzeczywistych błędów FK i UNIQUE w silniku bazy. Oryginalne kategorie i usługi testowego schematu pozostają zachowane po zakończeniu tych scenariuszy.

Weryfikacja 03.11.2026 na PHP 8.4.27 i odizolowanej MariaDB 11.4.5:

- 85 testów PHP i 270 kontroli zestawu HTTP zakończonych powodzeniem, bez pominięć.
- Poprawna składnia wszystkich 37 plików PHP; brak nowych sekretów w bieżących plikach repozytorium.
- Edge: lista, dodawanie, edycja, potwierdzenie usunięcia i blokada dla kategorii z usługami przy 1280, 390 i 320 px. Brak przewijania poziomego całej strony; tabela przewija się we własnym obszarze. Pola mają tekst 16 px, a przyciski i odnośniki co najmniej 44 px wysokości.
- Wysłanie pustego formularza z pominięciem walidacji HTML daje błąd PHP i fokus na komunikacie. Powrót przez historię przeglądarki po wylogowaniu prowadzi do logowania, bez danych administratora.

Testy nie korzystały z prawdziwej bazy użytkownika. Lokalny podgląd wymaga własnego `config/database.local.php`. Nie testowano osobno silnika MySQL, docelowego HTTPS ani fizycznego telefonu.
