# CutTime — terminarz pracownika

Panel działa na istniejącej stronie `/employee.php`, dostępnej w nawigacji jako **Mój terminarz**. Korzysta z `require_role('employee')`, PDO, sesji, CSRF, `e()`, komunikatów flash, wspólnego layoutu i paginacji. Nie zmienia schematu bazy ani kontroli administratora.

## Tożsamość i własność

ID konta pochodzi z istniejącej autoryzacji na podstawie sesji. Profil ustalany jest przez `employees.user_id`, które w SQL ma UNIQUE. Wymagany jest aktywny profil. Brak profilu lub jego dezaktywacja daje HTTP 403 z informacją o kontakcie z administratorem.

Lista, jej licznik oraz szczegóły mają warunek `e.user_id = :user AND e.active = 1`. Przekazanie `employee_id`, `user_id` lub roli w GET/POST nie zmienia tożsamości. Szczegóły dodatkowo wymagają `r.id = :id`. Cudze i nieistniejące ID dają identyczną odpowiedź 404, bez danych wizyty.

## Widoki

- **Dzisiaj** — wszystkie własne wizyty z dzisiejszą datą, także zrealizowane i anulowane, w kolejności godzin.
- **Przyszłe wizyty** — własne oczekujące i potwierdzone wizyty od jutra, od najbliższej daty. Dzisiejsze terminy pozostają w pierwszej zakładce.
- **Historia** — wizyty z poprzednich dni oraz wszystkie zrealizowane i anulowane, od najpóźniejszej daty. Dzisiejsza wizyta końcowa pojawi się zarówno tutaj, jak i w dziennym zestawieniu. Upływ czasu nie zmienia statusu automatycznie.

Daty są obliczane w `Europe/Warsaw`. SQL używa LIMIT/OFFSET po 20 wizyt. Licznik i kolejne strony zachowują ograniczenie pracownika i grupy. Karty pokazują klienta, usługę, datę, godziny początku i końca oraz tekstowy status z kolorem. Szczegóły zawierają dodatkowo telefon klienta i komentarz do wizyty. Dane historyczne nie znikają po dezaktywacji usługi lub klienta.

## Zmiana statusu

| Aktualny status | Dozwolony nowy status |
| --- | --- |
| `pending` | `confirmed`, `cancelled` |
| `confirmed` | `completed` po planowanym końcu, `cancelled` |
| `completed` | brak |
| `cancelled` | brak |

Model dotyczy pracownika. Może również uzupełnić status minionej wizyty: potwierdzić oczekującą, a potem zrealizować, albo anulować wizytę, która nie doszła do skutku. Sam klient nadal może anulować tylko przyszłą wizytę. Pracownik nie przywraca anulowanych terminów, co zapobiega ponownemu zajęciu zwolnionego przedziału bez walidacji konfliktów. Dotychczasowa administracyjna korekta statusów pozostaje osobnym mechanizmem.

Formularz szczegółów wysyła POST do `/employee.php?action=status`, zawierający ID wizyty, dozwolony nowy status, status z chwili otwarcia formularza (`expected_status`), CSRF i wymagane potwierdzenie. GET nigdy nie aktualizuje danych. Kontroler sprawdza metodę, rozmiar żądania, token i ID; funkcja domenowa sprawdza wartości statusów i potwierdzenie.

Transakcja ponownie ustala aktywny profil przez ID konta i blokuje go `FOR UPDATE`. Następnie blokuje rezerwację warunkiem `id = :id AND employee_id = :employee`. To ta sama kolejność blokad co przy tworzeniu i anulowaniu rezerwacji klienta. Po oczekiwaniu ponownie sprawdzane są aktualny status, dopuszczalne przejście oraz czas zakończenia.

UPDATE zmienia wyłącznie status i ponownie wymaga ID, przypisania pracownika oraz oczekiwanego statusu. Stary formularz otrzymuje HTTP 409, zamiast nadpisać np. anulowanie przez klienta lub zmianę administratora. COMMIT zatwierdza zmianę; `finally` wycofuje niedokończoną transakcję, również po błędach. Deadlock/timeout daje bezpieczny komunikat ponowienia; inne wyjątki obsługuje istniejący handler. Sukces używa przekierowania 303 i komunikatu flash.

## Pliki

Nowe:

- `includes/employee-reservations.php` — profil pracownika, odczyty, lista dozwolonych zmian i transakcyjny zapis statusu.
- `includes/employee-reservations-view.php` — zakładki, paginacja i formularz.
- `includes/employee-reservation-card.php` — wspólna karta listy i szczegółów.
- `tests/employee-visits-http.php` — testy modułu na izolowanej bazie.
- `docs/employee-visits.md` — ten opis.

Zmodyfikowane: `public/employee.php`, `includes/reservation-statuses.php`, `includes/account-view.php`, `includes/account-navigation.php`, `public/css/auth.css`, `tests/http.php`, `README.md`.

## Weryfikacja

PHP 8.2.35 i MariaDB 11.4.5: pełny zestaw HTTP zakończył się wynikiem **1445 zaliczonych, 0 błędów**. Wszystkie **88 plików PHP** przeszły kontrolę składni. Testy wykonano na osobnym losowym schemacie `cuttime_http_*`; dane lokalnej aplikacji nie były modyfikowane.

Sprawdzono oba konta pracowników, własne i cudze listy/szczegóły/POST, podmianę ID i tożsamości, nieistniejące wizyty, błędne parametry i statusy, CSRF, brak potwierdzenia, zbyt duże żądania, GET, klienta/gościa/admina, brakujący i nieaktywny profil, XSS, paginację, stare formularze oraz przejścia statusów. Realizacja przyszłej wizyty i ponowne otwarcie statusu końcowego są odrzucane. Zmiana poprawnego statusu zachowuje pozostałe pola rekordu i historię. Ogólny zestaw sprawdza również brak dostępu po wylogowaniu i bezpieczne błędy konfiguracji.

Panel używa istniejącej responsywnej siatki kart. Nie wykonano wizualnego testu w przeglądarce; układ oceniono na podstawie HTML/CSS.
