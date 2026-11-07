# Rezerwacje administratora — Etap 3

Moduł jest dostępny pod `/admin-reservations.php` oraz w istniejącej nawigacji administratora. Korzysta z aktualnego schematu `database/database.sql`; nie wymaga migracji ani ponownego importu danych.

## Lista i szczegóły

- Lista łączy `reservations.user_id` z kontem klienta, `employee_id` z profilem pracownika i jego kontem oraz `service_id` z usługą. Pokazuje imiona i nazwiska, nazwę usługi, datę, zapisane godziny początku/końca i polską nazwę statusu. Numer rezerwacji jest dodatkową informacją.
- Lista obejmuje wszystkie statusy, w tym historię nieaktywnych kont, profili i usług. Sortowanie: data malejąco, godzina malejąco, ID malejąco. Paginacja zwraca do 20 rekordów; nadmierny poprawny numer strony jest ograniczany do ostatniej strony wyników.
- Szczegóły (`?action=details&id=…`) zawierają również dane kontaktowe klienta i pracownika oraz komentarz. Nie pobierają haseł ani hashy.
- Baza nie przechowuje ceny historycznej rezerwacji, dlatego szczegóły nie przedstawiają aktualnej ceny usługi jako ceny wcześniejszej wizyty. Czas wizyty pochodzi z rezerwacji, nie z obecnej długości usługi.
- Na telefonie tabela przewija się w osobnym kontenerze. Formularze i nawigacja dopasowują się do szerokości ekranu.

## Łączone filtry

| Parametr GET | Znaczenie i walidacja |
|---|---|
| `date` | Dokładna data wizyty, `RRRR-MM-DD`; istniejąca data kalendarzowa z roku 1000–9999. |
| `status` | Jedna z czterech wartości wymienionych poniżej. |
| `employee_id` | Poprawne dodatnie ID UINT32 oraz rzeczywiście istniejący profil pracownika. Wybór obejmuje także nieaktywne osoby. |
| `page` | Dodatnia liczba całkowita w zakresie UINT32, bez zer wiodących. Brak parametru oznacza stronę 1. |

Pusta data, status lub pracownik oznacza brak tego ograniczenia. Warunki łączą się przez `AND`: np. data + status + pracownik zawężają wynik jednocześnie. Tablice, nieistniejące daty, ujemne ID, nieznane statusy i nieistniejący pracownicy powodują czytelny błąd 400. Niepoprawne filtry blokują również zapis statusu z takiego adresu.

Zastosowanie formularza filtrów rozpoczyna listę od pierwszej strony. Paginacja, szczegóły, zapis statusu i powrót do listy zachowują filtry. Adresy są budowane lokalnie z zatwierdzonych wartości; nie ma dowolnego adresu powrotnego.

Po przeglądzie całego panelu paginacja korzysta ze wspólnego `includes/admin-pagination.php`: pokazuje zakres wyników, poprzednią/następną stronę oraz pierwszą/ostatnią, gdy skracają nawigację. Wszystkie linki zachowują trzy filtry. Błędy daty, statusu i pracownika są również wyświetlane przy polach i połączone z nimi przez `aria-describedby`. Aktualne wyniki weryfikacji całej aplikacji zawiera [raport Etapu 3](stage3-audit.md); wyniki poniżej dotyczą wcześniejszego wdrożenia modułu rezerwacji.

## Zmiana statusu

Statusy odpowiadają dokładnie `reservations.status`:

| Wartość w bazie | Etykieta |
|---|---|
| `pending` | Oczekująca |
| `confirmed` | Potwierdzona |
| `completed` | Zrealizowana |
| `cancelled` | Anulowana |

Administrator wybiera status w szczegółach, zaznacza potwierdzenie i wysyła formularz POST. Serwer sprawdza status, potwierdzenie oraz CSRF. GET nie zapisuje zmian. Udany zapis kończy się przekierowaniem 303 do szczegółów i jednorazowym komunikatem sukcesu.

Zapytanie aktualizuje wyłącznie `status`, pod warunkiem że nadal odpowiada on wartości odczytanej przy otwarciu formularza (`expected_status`). Jeśli inny administrator lub druga karta zmieni status, zapis zwraca konflikt 409 i wymaga ponownego potwierdzenia na podstawie aktualnego stanu. Zapis tej samej wartości jest poprawny. Usunięty rekord daje 404; błędne ID daje 400.

Dozwolone są korekty między wszystkimi czterema statusami. Moduł nie wprowadza dodatkowej maszyny stanów ani procesu tworzenia/przesuwania wizyt. Nie zmienia daty, godzin, klienta, pracownika, usługi ani komentarza. Nie sprawdza kolizji terminów lub zgodności z bieżącą dostępnością podczas korekty statusu — reguły planowania wizyt należą do Etapu 4. Administrator powinien sprawdzić termin przed przywróceniem anulowanej wizyty do aktywnego statusu.

## Pliki i zabezpieczenia

- `public/admin-reservations.php` — kontroler listy, szczegółów i statusu; przed każdą akcją wywołuje istniejące `require_role('admin')`.
- `includes/admin-reservations.php` — zapytania, walidacja filtrów/statusów, paginacja i lokalne adresy.
- `includes/admin-reservations-view.php` — tabela, filtry, szczegóły i formularz; dane wyświetla przez wspólne `e()`.
- `includes/account-view.php`, `includes/admin-navigation.php`, `public/css/auth.css` — integracja z istniejącym layoutem, nawigacją i stylami.
- `tests/reservations-http.php`, dołączany przez `tests/http.php` — scenariusze całego modułu i regresji.

Wykorzystywane są istniejące PDO, autoryzacja, sesja, CSRF, flash i obsługa błędów. Wszystkie zapytania są przygotowane, a wartości użytkownika wiązane osobno; dynamiczne fragmenty WHERE pochodzą wyłącznie ze stałej mapy kolumn. LIMIT/OFFSET są wiązane jako liczby. POST ma istniejący limit 32 KiB. Role i dodatkowe pola przesłane formularzem nie nadają uprawnień i nie modyfikują innych danych.

Wspólny handler ukrywa błędy PDO, SQL, konfigurację i ścieżki. Nie zastępuje awarii bazy pustą listą ani fałszywym sukcesem. Kontrola dostępu i mechanizm braku cache obejmują wszystkie widoki modułu.

## Testowanie i wynik — 6 listopada 2026

Uruchom `php tests/http.php` według [instrukcji środowiska testowego](stage2-audit.md#powtarzanie-testów). Testy tworzą własną losową bazę i tymczasową kopię aplikacji; nie używają lokalnej konfiguracji użytkownika. `tests/reservations-http.php` jest częścią zestawu, nie osobnym poleceniem.

Scenariusze obejmują:

- odmowę wszystkich akcji gościowi, klientowi i pracownikowi, CSRF i niedozwolone metody;
- nieprawidłowe ID, typy tablicowe, nadmierne dane, nieistniejące daty i daty lat przestępnych, wszystkie kombinacje filtrów i brak wyników;
- listę i szczegóły nieaktywnych osób/usług, zapis wszystkich statusów i zapis bez zmiany;
- nieaktualny formularz, potwierdzenie, brak rekordu, zachowanie reszty danych mimo dodatkowych pól POST;
- rzeczywisty błąd zapisu wywołany testowym triggerem, niedostępną tabelę i bezpieczne komunikaty;
- paginację na dodatkowych rekordach, deterministyczne sortowanie i zachowanie filtrów w linkach/przekierowaniach;
- XSS w nazwach, kontaktach i komentarzu oraz zgodność listy statusów z SQL.

Wyniki na PHP 8.4.27 i odizolowanej MariaDB 11.4.5: **85 testów PHP i 1086 kontroli HTTP**, bez błędów i pominięć. Składnia wszystkich **60 plików PHP** poprawna.

W Edge sprawdzono **6 widoków × 3 szerokości (1280, 390 i 320 px)**: listę, wyniki filtrów, szczegóły, formularz statusu, brak wyników i błędny filtr. Bez poziomego przepełnienia całej strony; sprawdzone pola mają co najmniej 16 px tekstu, a przyciski, nawigacja i etykieta potwierdzenia obszar wysokości co najmniej 44 px. Sprawdzono również wysłanie trzech filtrów, błąd braku potwierdzenia z fokusem, zapis statusu, zachowanie powrotu z filtrami i brak danych po wylogowaniu oraz przycisku Wstecz.

Nie sprawdzano lokalnej bazy użytkownika, osobnego MySQL, docelowego HTTPS ani fizycznego telefonu. Małe ekrany zweryfikowano przez zmianę rozmiaru widoku przeglądarki. SQL, ERD, konta testowe i mechanizmy Etapu 2 pozostają bez zmian.
