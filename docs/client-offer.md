# CutTime — publiczna oferta i wybór usługi

Pierwszy krok Etapu 4. Publiczna oferta jest dostępna bez konta. Strony nie tworzą rezerwacji ani nie zapisują wyboru w sesji; identyfikator usługi przechodzi w lokalnym adresie kolejnego ekranu i za każdym razem jest sprawdzany w bazie. Wybór pracownika, daty i godziny opisuje [kolejny krok](client-booking.md).

## Strony

| Adres | Działanie |
|---|---|
| `/services.php` | Karty usług z nazwą, kategorią, opisem, ceną w złotych i czasem w minutach. |
| `/service.php?id=1` | Szczegóły aktywnej usługi i odnośnik do kolejnego ekranu. |
| `/booking.php?service_id=1` | Rozpoczęcie wyboru pracownika dla usługi. |

Przycisk „Wybierz usługę” działa z listy i ze szczegółów. Można wrócić do szczegółów lub zmienić usługę. Nie ma jeszcze zapisu, anulowania ani terminarza pracownika.

Oferta jest dostępna także dla zalogowanych użytkowników. Korzysta wtedy ze wspólnej nawigacji konta i istniejącego wylogowania. Odnośniki do oferty dodano do strony logowania i nawigacji konta. Nie zmieniono uprawnień stron chronionych.

## Dane i zabezpieczenia

- `includes/offer.php` wykonuje przygotowane zapytanie PDO do istniejących `services` i `service_categories`. Warunki `s.active = 1 AND c.active = 1` obowiązują na wszystkich trzech stronach. Dezaktywacja kategorii ukrywa jej usługi w ofercie, bez zmiany ich rekordów i historii.
- Poza listą zapytanie wiąże `:id`. Zwraca wyłącznie publiczne dane usługi, bez danych kont, rezerwacji i pracowników. Cena pozostaje ciągiem dziesiętnym z `DECIMAL(10,2)`; wyświetlenie zmienia separator na przecinek, bez obliczeń zmiennoprzecinkowych.
- `record_id()` w `includes/helpers.php` przyjmuje kanoniczny zapis dodatniej liczby do `4294967295`, zgodnie z `INT UNSIGNED`. Odrzuca tablice, spacje, znak plus/minus, ułamki, notację wykładniczą, zera wiodące i zbyt długie wartości. Jest to dotychczasowa reguła administracyjna przeniesiona do wspólnego helpera; `admin_record_id()` deleguje do niej bez zmiany zachowania.
- Niepoprawny lub brakujący identyfikator daje HTTP 400; brak usługi, nieaktywna usługa lub nieaktywna kategoria — jednakowe HTTP 404 z bezpiecznym komunikatem i powrotem do oferty. Nie wyświetlamy odrzuconej wartości.
- Cena, czas i aktywność pochodzą z bazy. Dodatkowe parametry żądania nie mogą ich nadpisać. Nowe strony dopuszczają tylko GET i HEAD; pozostałe metody otrzymują HTTP 405. Nie wykonują operacji zmieniających dane, więc wybór usługi nie wymaga tokenu CSRF. Wylogowanie nadal wymaga istniejącego POST z CSRF.
- Wszystkie dane usługi i kategorii w HTML przechodzą przez `e()`. Używany jest dotychczasowy bootstrap, jedno PDO, sesja, autoryzacja, flash, nagłówki CSP i bezpieczna obsługa wyjątków. Nie powstała druga wersja tych mechanizmów.

## Pliki

Nowe:

- `public/services.php`, `public/service.php`, `public/booking.php` — cienkie kontrolery publicznych stron;
- `includes/offer-page.php` — wspólna obsługa żądania, walidacja i wybór widoku;
- `includes/offer.php` — odczyt publicznej oferty;
- `includes/offer-view.php`, `includes/offer-service-summary.php` — lista, szczegóły, podsumowanie i wspólne wyświetlenie opisu/ceny/czasu;
- `tests/offer-http.php` — scenariusze w istniejącym zestawie HTTP;
- `docs/client-offer.md` — ten opis.

Zmodyfikowane:

- `includes/account-view.php` — istniejący layout obsługuje też publiczną ofertę;
- `includes/account-navigation.php`, `public/login.php`, `public/client.php` — odnośniki i aktualny opis możliwości klienta;
- `includes/helpers.php`, `includes/admin-forms.php` — współdzielenie istniejącej walidacji ID;
- `public/css/auth.css` — karty usług, podsumowanie i układ mobilny;
- `tests/http.php` — uruchomienie testów oferty;
- `README.md` — aktualny stan Etapu 4, adres oferty i struktura plików.

SQL, dane startowe, konfiguracja, logowanie, autoryzacja i kontrolery administracyjne nie zostały zmienione.

## Weryfikacja — 7 listopada 2026

Na PHP 8.4.27 i odizolowanej MariaDB 11.4.5:

- `php tests/http.php`: **1186 kontroli zaliczonych**, w tym 100 nowych kontroli oferty; bez błędów. Testy używają własnego losowego schematu i kopii aplikacji, nie lokalnej bazy użytkownika.
- **85 testów PHP** istniejącego fundamentu, rejestracji, logowania, autoryzacji i profilu — zaliczone bez pominięć.
- Sprawdzono składnię wszystkich **71 plików PHP** i `git diff --check` — bez błędów. Kontrola zmienionych plików i wzorców kluczy nie wykazała nowych sekretów; lokalna konfiguracja nadal jest ignorowana przez Git.
- Testy oferty obejmują wszystkie role i gościa, błędne/brakujące ID, tablice, duże wartości, próbę SQL injection, brak rekordu, dezaktywację usługi/kategorii między ekranami, XSS zapisany w nazwie/opisie/kategorii, zerową i maksymalną cenę, brak opisu, pustą ofertę, niedozwolone metody, podmianę parametrów i bezpieczny błąd bazy. Porównanie siedmiu tabel potwierdza brak zapisu rezerwacji i innych danych przez strony oferty.
- Edge: **18 sprawdzeń układu** (6 widoków × 1280/390/320 px), bez poziomego przewijania całej strony; sprawdzane przyciski i linki mają co najmniej 44 px wysokości. Sprawdzono klikanie lista → szczegóły → wybór oraz przejście z konta klienta do oferty. Obejrzano zrzuty listy i podsumowania.
- Testy nie zastępują uruchomienia na docelowym serwerze, osobnego sprawdzenia MySQL ani fizycznego telefonu.

Przy dodatkowej próbie uruchomienia istniejącego `tests/admin-polish-http.php` wykryto wcześniejszą rozbieżność poza tym zadaniem: test oczekuje zakresu „Wyniki … z …” w paginacji rezerwacji, podczas gdy `includes/admin-reservations-view.php` ma osobny widok poprzedniej/następnej strony bez tego zakresu. Ten zestaw nie był podłączony do `tests/http.php` w zastanym repozytorium. Próba zatrzymała się na tej asercji; nie oznacza to awarii zapisu ani filtrowania rezerwacji. Nie zmieniano testu ani modułu rezerwacji, a dodatkowe wywołanie pozostawiono poza podstawowym zestawem tak jak przed zadaniem.

Aby powtórzyć testy, użyj konfiguracji `CUTTIME_TEST_DSN`, `CUTTIME_TEST_USER`, `CUTTIME_TEST_PASSWORD` i poleceń opisanych w [instrukcji testów](stage2-audit.md#powtarzanie-testów). Lokalny podgląd: po skonfigurowaniu bazy uruchom `php -S 127.0.0.1:8000 -t public`, następnie otwórz `/services.php`.
