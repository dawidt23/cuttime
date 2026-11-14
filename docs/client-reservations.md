# CutTime — własne rezerwacje klienta

Moduł znajduje się pod `/client-reservations.php`, w nawigacji konta jako **Moje rezerwacje**. Korzysta z istniejących PDO, sesji, `require_role('client')`, CSRF, `e()`, flash messages, layoutu oraz helpera paginacji. Nie zmienia schematu SQL.

## Lista i szczegóły

- **Nadchodzące**: status `pending` lub `confirmed`, początek ściśle późniejszy od aktualnego czasu w `Europe/Warsaw`, najbliższe wizyty na początku.
- **Historia**: wizyty `completed` oraz wszystkie nieanulowane wizyty, których początek już nastąpił. Upływ czasu nie zmienia automatycznie statusu; historyczna wizyta może nadal mieć status oczekujący lub potwierdzony.
- **Anulowane**: status `cancelled`, niezależnie od daty.

Każda karta pokazuje usługę, pracownika, datę, godziny początku i końca, cenę oraz tekstowy status z oznaczeniem kolorem. Widok korzysta z elastycznej siatki CSS, dopasowującej się również do wąskiego ekranu. SQL stosuje LIMIT/OFFSET po 20 rekordów; licznik oraz każda strona wyników zachowują filtr właściciela i grupy.

Cena pochodzi z `services.price` i jest podpisana **Aktualna cena usługi**. Baza nie przechowuje historycznej ceny przy rezerwacji; późniejsza zmiana cennika zmieni więc również tę informację w historii.

Szczegóły: `/client-reservations.php?action=details&reservation_id=…`. Lista, licznik i szczegóły używają warunku `user_id = :client`, gdzie klient pochodzi z sesji przez istniejącą autoryzację. Nieaktywny pracownik lub usługa nie ukrywa istniejącej historii. Cudze i nieistniejące ID zwracają ten sam komunikat 404, bez ujawniania danych.

## Anulowanie

1. Klient otwiera własne szczegóły. Przy przyszłej wizycie `pending` lub `confirmed` widzi opis skutków, wymagany checkbox i przycisk **Anuluj tę wizytę**.
2. Formularz wysyła POST do `?action=cancel`, z `reservation_id`, `_csrf` i `confirm=1`. GET nie zmienia danych. Serwer waliduje metodę, rozmiar żądania, token, ID oraz potwierdzenie.
3. Właściciel pochodzi wyłącznie z zalogowanego konta. Parametry `user_id`, rola, proponowany status i data nie decydują o uprawnieniach.
4. Transakcja blokuje pracownika, a następnie własną rezerwację przez `FOR UPDATE`, zachowując kolejność blokad stosowaną przy tworzeniu wizyty. Po oczekiwaniu ponownie sprawdza właściciela, aktualny status i czas w Warszawie. Niedozwolone są wizyty rozpoczęte, przeszłe, zrealizowane i już anulowane.
5. UPDATE zmienia wyłącznie status na `cancelled`. Jego WHERE ponownie wymaga ID, właściciela, oczekiwanego statusu i przyszłego początku. COMMIT zatwierdza zmianę, a `finally` wycofuje każdą niedokończoną transakcję i zwalnia blokady. Błędy blokad dają bezpieczny komunikat ponowienia; pozostałe wyjątki obsługuje istniejący handler.
6. Przekierowanie 303 do zakładki Anulowane pokazuje komunikat sukcesu. Powtórzenie POST jest odrzucane; rekord i jego pozostałe pola pozostają w bazie. Anulowana wizyta przestaje blokować termin w istniejącym mechanizmie rezerwacji.

## Pliki

Nowe:

- `includes/reservation-statuses.php` — wspólne etykiety statusów i dozwolone statusy anulowania; przeniesiono tu także dotychczasową listę statusów blokujących termin.
- `includes/client-reservations.php` — odczyty z kontrolą własności, grupowanie, paginacja, transakcyjne anulowanie.
- `includes/client-reservations-view.php`, `includes/client-reservation-card.php` — lista, szczegóły oraz potwierdzenie, wspólna karta wizyty.
- `public/client-reservations.php` — kontroler chroniony rolą klienta, walidacja żądań, CSRF i komunikaty.
- `tests/client-reservations-http.php` — testy modułu.
- `docs/client-reservations.md` — ten opis.

Zmodyfikowane: `includes/account-view.php`, `includes/account-navigation.php`, `public/client.php`, `public/css/auth.css`, `includes/admin-reservations.php`, `includes/booking.php`, `tests/http.php` oraz `README.md`. Moduły administratora i wyboru terminu korzystają ze wspólnych stałych statusów; ich uprawnienia i logika pozostają takie same.

## Weryfikacja

Pełny zestaw `php tests/http.php` na PHP 8.2.35 / MariaDB 11.4.5: **1363 zaliczonych, 0 błędów**. Składnia wszystkich **84 plików PHP** poprawna. Testy używają własnego losowego schematu `cuttime_http_*`, nie lokalnej bazy użytkownika.

Sprawdzono listę i szczegóły własne/cudze, ograniczenie właściciela na każdej stronie paginacji, wymagane dane i cenę, statusy, XSS, role, brak dostępu po wylogowaniu, ID nieistniejące i błędne, CSRF, brak potwierdzenia, GET, ręczny POST, przeszłość, rozpoczętą wizytę, wizytę zrealizowaną i anulowaną, zmianę parametrów, ponowienie oraz zwolnienie terminu po anulowaniu. Bieżący test obejmuje również wcześniejszą regresję równoczesnego tworzenia rezerwacji. Nie wykonano wizualnego testu w przeglądarce; responsywność oceniono na podstawie struktury HTML/CSS.
