# Bezpieczny zapis rezerwacji — Etap 4

Zalogowany klient zatwierdza wizytę w podsumowaniu na `/booking.php`. Formularz wysyła POST do `/booking-save.php` z istniejącym tokenem CSRF. Endpoint wymaga roli `client`; ID właściciela bierze z konta odczytanego przez autoryzację na podstawie sesji. Parametry `user_id`, `role`, `status`, `duration`, `end_time`, cena i deklarowana dostępność nie sterują zapisem.

## Transakcja i ponowne sprawdzenie

1. Walidacja ID, daty, godziny i jawnego potwierdzenia.
2. `BEGIN`, następnie `SELECT ... FROM employees WHERE id = :id FOR UPDATE`. Blokada jednego pracownika obejmuje wszystkie dni. Jest celowo szersza niż blokada dnia, prosta i działa także przy pustym terminarzu. Istniejąca administracyjna edycja grafiku i przypisań również blokuje ten rekord.
3. Aktualny odczyt blokujący usługi, kategorii, pracownika, jego konta i przypisania N:M. Wszystkie muszą być aktywne, a konto pracownika mieć właściwą rolę. Sprawdzane i blokowane jest też aktywne konto klienta.
4. Koniec jest obliczany z aktualnego `services.duration`. Początek musi należeć do siatki 15 minut. Całość musi mieścić się w jednym przedziale pracy odpowiedniego dnia tygodnia. Porównania SQL uwzględniają sekundy przechowywane w TIME.
5. Bezpośrednio przed INSERT odczyt konfliktów `FOR UPDATE`: `start_time < new_end AND end_time > new_start`, dla tego pracownika i daty oraz statusów z `BOOKING_BLOCKING_STATUSES`. Anulowane wizyty nie blokują.
6. Ponowne sprawdzenie przyszłego początku po ewentualnym oczekiwaniu na blokady, INSERT ze statusem `pending`, COMMIT. Cena nie jest zapisywana, ponieważ obecny schemat rezerwacji nie ma takiej kolumny.

Druga transakcja tego samego pracownika czeka na pierwszą. Po uzyskaniu blokady widzi jej zatwierdzoną rezerwację dzięki aktualnemu odczytowi `FOR UPDATE`, również przy izolacji REPEATABLE READ. Konflikt zwraca HTTP 409 i komunikat „Wybrany termin został właśnie zajęty. Wybierz inną godzinę.”

`finally` wycofuje każdą niedokończoną transakcję, również po błędzie lub wcześniejszym zwrocie walidacji. COMMIT/ROLLBACK zwalnia blokady. Deadlock i timeout blokady dają bezpieczny komunikat ponowienia, pozostałe wyjątki obsługuje dotychczasowy handler. Powtórny POST na ten sam termin zostaje odrzucony jako konflikt. Sukces używa flash i przekierowania 303 do części klienta.

Gwarancja dotyczy zapisów wykonywanych przez ten mechanizm; bezpośredni SQL może ją ominąć. Administracyjna korekta statusu pozostaje dotychczasowym mechanizmem — przywrócenie anulowanej wizyty nie jest nowym zapisem klienta i obecnie nie sprawdza kolizji. Nie należy używać jej do ponownego rezerwowania terminu bez osobnej walidacji.

## Pliki i testy

- `includes/booking-save.php`: transakcja, odczyty blokujące, walidacja i INSERT.
- `public/booking-save.php`: autoryzacja, metoda POST, limit rozmiaru, CSRF, komunikaty i przekierowanie.
- `includes/booking-selection-view.php`: formularz potwierdzenia tylko dla klienta.
- `public/client.php`: aktualny opis możliwości klienta.
- `tests/booking-save-http.php`, `tests/booking-save-worker.php`: testy POST i dwa niezależne procesy PHP z oddzielnymi połączeniami PDO. Proces nadrzędny przytrzymuje blokadę, uruchamia obu klientów i zwalnia ją; oczekiwany wynik to jeden zapis i jeden konflikt.
- `tests/http.php`: uruchomienie nowych testów oraz poprawiony odczyt nagłówków HTTP dla PHP 8.2.

Testy działają wyłącznie na losowej bazie `cuttime_http_*`, tworzonej przez istniejący zestaw `php tests/http.php`. Obejmują role, CSRF, manipulacje POST, nieaktywność, godziny pracy, wszystkie pięć konfliktów i obie granice, ponowienie oraz równoległy zapis. Nie zmieniają lokalnej bazy użytkownika ani schematu SQL projektu.

Weryfikacja: PHP 8.2.35 i MariaDB 11.4.5, pełny zestaw HTTP — **1286 zaliczonych, 0 błędów**, w tym równoległy zapis przez dwa niezależne połączenia (jedna rezerwacja, jeden konflikt).
